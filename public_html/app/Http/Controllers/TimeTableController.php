<?php

namespace App\Http\Controllers;

use App\Models\Master;
use App\Models\TimeTable;
use App\Modules\People\Services\TeacherIdentityVerifier;
use Illuminate\Http\Request;

class TimeTableController extends Controller
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function getTeacherDOB(Request $request, TeacherIdentityVerifier $identities)
    {
        abort_unless($request->isMethod('post'), 405);
        // The timetable schema has names, not codes, so ambiguous names must fail closed.
        $teacher = $identities->verifyTeacher($request->only('teacher_name', 'dob', 'password'));
        $students = Master::where('teacher_name', $teacher->teacher_name)
            ->where('teacher_code', $teacher->teacher_code)->pluck('student_name');
        $timeTables = TimeTable::whereIn('student_name', $students)
            ->where(function ($query) use ($teacher) {
                foreach (self::DAYS as $day) {
                    $query->orWhere($day.'_teacher', $teacher->teacher_name);
                }
            })->get();

        $days = array_fill_keys(self::DAYS, []);
        foreach ($timeTables as $timeTable) {
            foreach (self::DAYS as $day) {
                if ($timeTable->{$day.'_teacher'} !== $teacher->teacher_name || !$timeTable->{$day.'_time'}) {
                    continue;
                }
                $days[$day][] = [
                    'time' => $timeTable->{$day.'_time'},
                    'subject' => $timeTable->{$day.'_subject'},
                    'hour' => $timeTable->{$day.'_hour'},
                    'student_name' => $timeTable->student_name,
                    'link' => $this->safeMeetingLink($timeTable->link),
                ];
            }
        }

        return response()->json(['message' => 'Teacher found', 'days' => $days])
            ->header('Cache-Control', 'no-store, private');
    }

}
