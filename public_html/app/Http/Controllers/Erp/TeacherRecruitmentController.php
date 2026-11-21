<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Access,TeacherRecruitment,TeamPermissions};
use Illuminate\Http\Request;

class TeacherRecruitmentController extends Controller
{
    public function mutate(Request $request,int $unit,string $action)
    {
        abort_unless(in_array($action,['teacher-candidate-create','teacher-candidate-update','teacher-candidate-contact','teacher-candidate-register','teacher-candidate-approve','teacher-candidate-resolve'],true),404);
        $result=$action==='teacher-candidate-resolve'
            ? (new \App\Modules\Erp\Services\TeacherDataReview())->resolve($unit,$request->user(),$request->except('_token'))
            : (new TeacherRecruitment())->mutate($unit,$request->user(),$action,$request->except('_token'));
        return response()->json(['result'=>$result,'message'=>'Teacher profile saved.']);
    }
}
