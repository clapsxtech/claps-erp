<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Master;
use App\Models\Subject;
use App\Models\Topic;

/**
 * Host pages for the public, no-login teacher forms (attendance, results,
 * performance, timetable). A teacher identifies with their name and date of
 * birth on each POST, which the form controllers (WorkHourController and friends)
 * verify; these pages embed only what is public anyway: the teacher
 * names and the subject or topic lists. Never a DOB, a student, a code or a rate.
 * The timetable page embeds no directory at all.
 */
class PublicFormsController extends Controller
{
    public function attendance()
    {
        return $this->page('attendance', 'Record a lesson', [
            'teachers' => $this->teachers(),
            'subjects' => $this->subjects(),
            'maxDate' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function result()
    {
        return $this->page('result', 'Upload a result', [
            'teachers' => $this->teachers(),
            'subjects' => $this->subjects(),
        ]);
    }

    public function performance()
    {
        return $this->page('performance', 'Performance remark', [
            'teachers' => $this->teachers(),
            'topics' => Topic::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($topic) => ['id' => (int) $topic->id, 'name' => (string) $topic->name])->values()->all(),
        ]);
    }

    public function timetable()
    {
        return $this->page('timetable', 'My timetable', []);
    }

    private function page(string $form, string $title, array $data)
    {
        $session = ['kind' => 'public', 'form' => $form]
            + $data
            + ['readOnly' => config('sheet_transition.authority') === 'sheets' && $form !== 'timetable'];

        return AppShellController::page($session, $title.' · ClapsLearn')
            ->header('Cache-Control', 'no-store, private');
    }

    /** @return string[] */
    private function teachers(): array
    {
        return Master::query()->whereNotNull('teacher_name')->where('teacher_name', '!=', '')
            ->distinct()->orderBy('teacher_name')->pluck('teacher_name')
            ->map(fn ($name) => (string) $name)->values()->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    private function subjects(): array
    {
        return Subject::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn ($subject) => ['id' => (int) $subject->id, 'name' => (string) $subject->name])->values()->all();
    }
}
