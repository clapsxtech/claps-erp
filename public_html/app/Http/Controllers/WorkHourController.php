<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Master;
use Illuminate\Http\Request;

class WorkHourController extends Controller
{
    
    

    public function getStudentinfo(Request $request)
    {
        $policy = app(\App\Modules\IdentityAccess\Services\SiteAccessPolicy::class);
        if ($policy->isAdministrator($request->user())) {
            $request->validate(['teacher_name' => ['required', 'string', 'max:255']]);
            $matches = Master::where('teacher_name', $request->teacher_name)->get();
            abort_unless($matches->pluck('teacher_code')->unique()->count() === 1, 422, 'Choose an unambiguous teacher identity.');
            $teacher = $matches->first();
        } else {
            $teacher = app(\App\Modules\People\Services\TeacherIdentityVerifier::class)->verifyTeacher($request->all());
        }
        $students = Master::where('teacher_name', $teacher->teacher_name)
            ->where('teacher_code', $teacher->teacher_code)
            ->select('student_name', 'student_code')->distinct()->get();
        return response()->json(['students' => $students, 'teacher_code' => $teacher->teacher_code])->header('Cache-Control', 'no-store, private');
    }
    public function store(Request $request)
    {
        $record = app(\App\Modules\Attendance\Services\RecordWorkHour::class)->record($request);
        return response()->json(['success' => true, 'msg' => 'Successfully created', 'id' => $record->id]);
    }

}
