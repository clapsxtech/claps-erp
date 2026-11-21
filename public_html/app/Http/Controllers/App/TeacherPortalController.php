<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Access, TeamPermissions};
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in teacher portal at /teacher. The host page embeds only the
 * teacher's own name, code and time zone (or, for administrators and unlinked
 * staff, the setup steps). Data comes from workspace(). Writes post to the
 * /erp/teacher/attendance and /erp/teacher/results routes, which answer JSON.
 */
class TeacherPortalController extends Controller
{
    public const SECTIONS = ['overview', 'timetable', 'students', 'attendance', 'results', 'reports'];

    public function page(Request $request, ?string $section = null)
    {
        $user = $request->user();
        try {
            [$teacher, $unitId] = TeacherPortalIdentity::resolve($user);
        } catch (AuthorizationException $refused) {
            (new SiteAccessPolicy())->assertSiteMember($user);
            $units = (new Access())->units($user);
            if (TeacherPortalIdentity::belongsInPortal($user, $units)) {
                return AppShellController::page([
                    'kind' => 'teacher',
                    'mode' => 'refused',
                    'user' => $this->user($user),
                    'message' => $refused->getMessage(),
                ], 'My teaching · ClapsLearn')->setStatusCode(403);
            }
            return $this->setup($user, $units);
        }
        $unit = DB::table('erp_units')->where('id', $unitId)->first(['name']);
        return AppShellController::page([
            'kind' => 'teacher',
            'mode' => 'portal',
            'user' => $this->user($user),
            'teacher' => ['name' => $teacher->name, 'code' => $teacher->code, 'timezone' => $teacher->timezone ?: 'Asia/Kolkata'],
            'unitName' => $unit->name ?? '',
        ], 'My teaching · ClapsLearn');
    }

    /** Administrators and staff without a teacher link: how to connect a teacher account. */
    private function setup($user, $units)
    {
        $isAdmin = (new SiteAccessPolicy())->isAdministrator($user);
        $permissions = new TeamPermissions();
        return AppShellController::page([
            'kind' => 'teacher',
            'mode' => 'setup',
            'user' => $this->user($user),
            'isAdmin' => $isAdmin,
            'units' => $units->map(fn ($unit) => [
                'id' => (int) $unit->id,
                'name' => $unit->name,
                'canOpenTeamAccess' => $isAdmin,
                'canOpenPeople' => $permissions->allows($user, (int) $unit->id, 'page', 'people'),
            ])->values(),
        ], 'Teacher accounts · ClapsLearn');
    }

    private function user($user): array
    {
        return ['name' => $user->name, 'initials' => Str::upper(Str::substr($user->name, 0, 1))];
    }

    /** Everything the six sections show, for one date range, with explicit fields only. */
    public function workspace(Request $request)
    {
        [$teacher, $unitId] = TeacherPortalIdentity::resolve($request->user());
        $tz = $teacher->timezone ?: 'Asia/Kolkata';
        $filters = Validator::make($request->only(['from', 'through', 'student', 'results_page']), [
            'from' => 'nullable|date_format:Y-m-d', 'through' => 'nullable|date_format:Y-m-d',
            'student' => 'nullable|integer|min:1', 'results_page' => 'nullable|integer|min:1|max:100000',
        ])->validate();
        $now = CarbonImmutable::now($tz);
        $from = $filters['from'] ?? $now->subDays(30)->toDateString();
        $through = $filters['through'] ?? $now->addDays(90)->toDateString();
        if ($through < $from || (strtotime($through) - strtotime($from)) > 366 * 86400) throw ValidationException::withMessages(['through' => 'Choose a date range of at most 366 days.']);

        $students = DB::table('erp_enrollments as e')->join('erp_students as s', function ($join) { $join->on('s.id', '=', 'e.student_id')->on('s.unit_id', '=', 'e.unit_id'); })
            ->where('e.unit_id', $unitId)->where('e.teacher_id', $teacher->id)->select('s.id', 's.name', 's.code')->distinct()->orderBy('s.name')->get();
        $studentId = isset($filters['student']) ? (int) $filters['student'] : null;
        if ($studentId && !$students->contains('id', $studentId)) throw new AuthorizationException('Choose one of your own students.');

        $lessons = DB::table('erp_lessons as l')
            ->join('erp_students as s', function ($join) { $join->on('s.id', '=', 'l.student_id')->on('s.unit_id', '=', 'l.unit_id'); })
            ->join('erp_subjects as subject', 'subject.id', '=', 'l.subject_id')
            ->leftJoin('erp_attendance as a', function ($join) { $join->on('a.lesson_id', '=', 'l.id')->on('a.unit_id', '=', 'l.unit_id'); })
            ->where('l.unit_id', $unitId)->where('l.teacher_id', $teacher->id)->when($studentId, fn ($query) => $query->where('l.student_id', $studentId))
            ->where('l.starts_at', '>=', CarbonImmutable::parse($from, $tz)->startOfDay()->utc()->format('Y-m-d H:i:s'))
            ->where('l.starts_at', '<=', CarbonImmutable::parse($through, $tz)->endOfDay()->utc()->format('Y-m-d H:i:s'))
            ->orderBy('l.starts_at')->limit(501)
            ->get(['l.id', 'l.starts_at', 'l.ends_at', 'l.status', 'l.kind', 's.name as student_name', 's.code as student_code', 'subject.name as subject_name', 'a.id as attendance_id', 'a.status as attendance_status', 'a.actual_minutes']);
        $truncated = $lessons->count() > 500;
        $lessons = $lessons->take(500)->map(function ($lesson) use ($tz) {
            $start = CarbonImmutable::parse($lesson->starts_at, 'UTC');
            $end = CarbonImmutable::parse($lesson->ends_at, 'UTC');
            $ended = $end->isPast();
            $open = in_array($lesson->status, ['scheduled', 'completed'], true);
            return [
                'id' => (int) $lesson->id,
                'date' => $start->setTimezone($tz)->toDateString(),
                'startTime' => $start->setTimezone($tz)->format('H:i'),
                'endTime' => $end->setTimezone($tz)->format('H:i'),
                // Prefills the "different time" form, in the teacher's zone.
                'startsAtLocal' => $start->setTimezone($tz)->format('Y-m-d\TH:i'),
                // Same measure Attendance::submit compares against when deciding whether a reason is needed.
                'scheduledMinutes' => (int) round(abs($start->diffInMinutes($end))),
                'status' => $lesson->status,
                'kind' => $lesson->kind,
                'studentName' => $lesson->student_name,
                'studentCode' => $lesson->student_code,
                'subjectName' => $lesson->subject_name,
                'hasEnded' => $ended,
                'isUpcoming' => $lesson->status === 'scheduled' && $start->isFuture(),
                'needsAttendance' => !$lesson->attendance_id && $open && $ended,
                'canMarkNoShow' => !$lesson->attendance_id && $lesson->status === 'scheduled' && $ended,
                'attendance' => $lesson->attendance_id ? ['status' => $lesson->attendance_status, 'minutes' => (int) $lesson->actual_minutes] : null,
            ];
        })->values();

        $assignments = DB::table('erp_enrollments as e')->join('erp_students as s', function ($join) { $join->on('s.id', '=', 'e.student_id')->on('s.unit_id', '=', 'e.unit_id'); })
            ->join('erp_subjects as subject', 'subject.id', '=', 'e.subject_id')->where('e.teacher_id', $teacher->id)->where('e.unit_id', $unitId)
            ->where('e.status', 'active')->where('s.status', 'active')->orderBy('s.name')
            // Handoff notes from admissions help the teacher plan; notes about the parent stay with staff.
            ->leftJoinSub(DB::table('erp_leads')->whereNotNull('student_id')->groupBy('student_id')->selectRaw('student_id, MAX(id) AS lead_id'), 'latest_lead', 'latest_lead.student_id', '=', 's.id')
            ->leftJoin('erp_leads as handoff', function ($join) { $join->on('handoff.id', '=', 'latest_lead.lead_id')->on('handoff.unit_id', '=', 's.unit_id'); })
            ->get(['e.id', 's.id as student_id', 's.name', 's.code', 's.grade', 's.syllabus', 'subject.name as subject_name', 'handoff.learning_requirement', 'handoff.concern', 'handoff.about_student'])
            ->map(fn ($a) => [
                'id' => (int) $a->id, 'studentId' => (int) $a->student_id, 'studentName' => $a->name, 'studentCode' => $a->code,
                'grade' => $a->grade, 'syllabus' => $a->syllabus, 'subjectName' => $a->subject_name,
                'learningRequirement' => $a->learning_requirement, 'concern' => $a->concern, 'aboutStudent' => $a->about_student,
            ])->values();

        $resultQuery = DB::table('erp_marks as m')->join('erp_enrollments as e', function ($join) { $join->on('e.id', '=', 'm.enrollment_id')->on('e.unit_id', '=', 'm.unit_id'); })
            ->join('erp_students as s', function ($join) { $join->on('s.id', '=', 'e.student_id')->on('s.unit_id', '=', 'e.unit_id'); })
            ->join('erp_subjects as subject', 'subject.id', '=', 'e.subject_id')->where('e.teacher_id', $teacher->id)->where('m.unit_id', $unitId)
            ->when($studentId, fn ($query) => $query->where('e.student_id', $studentId))->whereBetween('m.assessed_on', [$from, $through]);
        $page = (int) ($filters['results_page'] ?? 1);
        $total = (clone $resultQuery)->count();
        $results = (clone $resultQuery)->orderByDesc('m.assessed_on')->orderByDesc('m.id')->forPage($page, 25)
            ->get(['m.id', 'm.assessment', 'm.assessed_on', 'm.score', 'm.maximum', 'm.notes', 's.name as student_name', 'subject.name as subject_name'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'assessment' => $r->assessment, 'assessedOn' => substr((string) $r->assessed_on, 0, 10),
                'score' => (int) $r->score, 'maximum' => (int) $r->maximum, 'notes' => $r->notes,
                'studentName' => $r->student_name, 'subjectName' => $r->subject_name,
            ])->values();

        return response()->json([
            'range' => ['from' => $from, 'through' => $through, 'today' => $now->toDateString(), 'timezone' => $tz, 'truncated' => $truncated, 'student' => $studentId],
            'lessons' => $lessons,
            'assignments' => $assignments,
            'students' => $students->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'code' => $s->code])->values(),
            'results' => ['data' => $results, 'meta' => ['page' => $page, 'perPage' => 25, 'total' => $total, 'lastPage' => max(1, (int) ceil($total / 25))]],
        ])->header('Cache-Control', 'no-store, private');
    }
}
