<?php
namespace App\Http\Controllers\Erp;
use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Access,Audit,People,Scheduling,Attendance,Finance,MoneyInput};
use Illuminate\Http\Request;
class WorkspaceController extends Controller
{
    public function mutate(Request $request,int $unit,string $action)
    {
        (new Access())->unit($request->user(),$unit); $actor=(int)$request->user()->id; $p=MoneyInput::forAction($request->except('_token'),$action);
        $map=['student'=>[People::class,'student'],'teacher'=>[People::class,'teacher'],'eligibility'=>[People::class,'eligibility'],'task'=>[People::class,'task'],'complete-task'=>[People::class,'completeTask'],'mark'=>[People::class,'mark'],'assign'=>[Scheduling::class,'assign'],'end-assignment'=>[Scheduling::class,'endAssignment'],'availability'=>[Scheduling::class,'availability'],'propose'=>[Scheduling::class,'proposeAgreement'],'confirm'=>[Scheduling::class,'confirmAgreement'],'materialize'=>[Scheduling::class,'materialize'],'reschedule'=>[Scheduling::class,'reschedule'],'cancel'=>[Scheduling::class,'cancel'],'pause-classes'=>[Scheduling::class,'pauseClasses'],'stop-classes'=>[Scheduling::class,'stopClasses'],'switch-teacher'=>[Scheduling::class,'switchTeacher'],'extra'=>[Scheduling::class,'scheduleExtra'],'exception'=>[Scheduling::class,'exception'],'end-agreement'=>[Scheduling::class,'endAgreement'],'attendance'=>[Attendance::class,'submit'],'payment'=>[Finance::class,'payment']];
        if($action==='import-batch'){ $p['attendance_ids']=is_array($request->input('attendance_ids'))?$request->input('attendance_ids'):array_filter(array_map('trim',explode(',',(string)$request->input('attendance_ids')))); $result=(new Attendance())->importBatch($unit,$actor,$p); }
        elseif($action==='approve')$result=(new Attendance())->approve($unit,$actor,(int)$request->input('attendance_id'));
        elseif($action==='correct')$result=(new Attendance())->correct($unit,$actor,(int)$request->input('attendance_id'),$p);
        elseif($action==='duplicate')$result=(new Attendance())->resolveDuplicate($unit,$actor,(int)$request->input('review_id'),$p);
        elseif($action==='no-show')$result=(new Attendance())->noShow($unit,$actor,$p);
        else{abort_unless(isset($map[$action]),404);[$class,$method]=$map[$action];$result=(new $class())->$method($unit,$actor,$p);}
        $message='Saved and audited.';
        if($action==='pause-classes')$message='Classes paused: '.$result->cancelled.' lesson(s) cancelled without charge. Later lessons stay booked.';
        if($action==='stop-classes')$message='Classes stopped: '.$result->cancelled.' later lesson(s) cancelled and '.$result->ended_assignments.' assignment(s) ended.'.($result->stopped?' Student marked stopped.':' The student stays active until their last class.');
        if($action==='switch-teacher')$message='Teacher changed: '.$result->cancelled.' lesson(s) moved and '.$result->lessons.' new lesson(s) booked with the new teacher.';
        if($action==='no-show')$message='Recorded as a no-show. The student is not charged and the class is not paid.';
        if($action==='approve')$message='Attendance approved. Teacher accrual: '.(!empty($result->teacher_financial_posted)?'posted':'none (demo or disabled policy)').'. Student charge: '.(!empty($result->student_financial_posted)?'already imported':'not imported').'. No money was transferred.';
        return response()->json(['result'=>$result,'message'=>$message]);
    }
}
