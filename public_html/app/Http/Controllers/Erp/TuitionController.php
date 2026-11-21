<?php
namespace App\Http\Controllers\Erp;
use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Access,TeamPermissions,TuitionWorkflows};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TuitionController extends Controller
{
    public function __construct() {}
    private function authorizeTeam(Request $request,int $unit,bool $write): void
    {
        (new Access())->unit($request->user(),$unit,$write);
        abort_unless(in_array((new TeamPermissions())->role($request->user(),$unit),['superadmin','admin','team_manager'],true),403,'Private operations are restricted to administrators and team managers.');
    }
    public function mutate(Request $request,int $unit,string $operation)
    {
        $this->authorizeTeam($request,$unit,true);
        // The date-time picker sends the team's local time; reviews store the deadline in UTC.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',(string)$request->input('deadline_at'))) {
            $timezone=DB::table('erp_units')->where('id',$unit)->value('timezone') ?: 'UTC';
            $request->merge(['deadline_at'=>\Carbon\CarbonImmutable::createFromFormat('Y-m-d\TH:i',$request->input('deadline_at'),$timezone)->utc()->format('Y-m-d H:i:s')]);
        }
        if ($operation==='monthly-review') {
            $v=$request->validate(['attendance_ids'=>'required|array|min:1|max:200','attendance_ids.*'=>'required|integer|min:1','snapshots'=>'required|array','snapshots.*'=>'required|string|size:64','deadline_at'=>'required|date_format:Y-m-d H:i:s','note'=>'required|string|max:4000']);
            DB::transaction(function()use($unit,$request,$v){
                $ids=array_unique($v['attendance_ids']); sort($ids,SORT_NUMERIC);
                foreach($ids as $id) {
                    abort_unless(isset($v['snapshots'][$id]),422,'Prepare the statement again.');
                    (new TuitionWorkflows())->parentReview($unit,(int)$request->user()->id,['attendance_id'=>$id,'decision'=>'share','deadline_at'=>$v['deadline_at'],'note'=>$v['note'],'snapshot'=>$v['snapshots'][$id]]);
                }
            });
            return response()->json(['message'=>'Statement sharing recorded for the selected attendance entries.']);
        }
        if ($operation==='parent-request-resolve') {
            $v=$request->validate(['request_id'=>'required|integer|min:1','reply'=>'required|string|max:2000']);
            $updated=DB::table('erp_parent_requests')->where('unit_id',$unit)->where('id',$v['request_id'])->where('status','open')->update(['status'=>'resolved','resolved_by'=>$request->user()->id,'resolved_at'=>now(),'updated_at'=>now()]);
            abort_unless($updated,404);
            \App\Modules\Erp\Services\Audit::record($unit,(int)$request->user()->id,'parent_request.resolved','parent_request',(int)$v['request_id'],['reply'=>$v['reply']]);
            return response()->json(['message'=>'Parent query marked as answered. Correct or accept the class record separately if needed.']);
        }
        $method=['note'=>'note','parent-review'=>'parentReview','candidate'=>'candidate','interview'=>'interview'][$operation]??null;
        abort_unless($method,404);
        $row=(new TuitionWorkflows())->$method($unit,(int)$request->user()->id,$request->except('_token'));
        return response()->json(['result'=>$row,'message'=>'Saved with your name and time in the audit history.']);
    }
}
