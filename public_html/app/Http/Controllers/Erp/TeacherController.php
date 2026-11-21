<?php
namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\Attendance;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Access\AuthorizationException;

class TeacherController extends Controller
{
    // ERP views do not read legacy settings or trigger legacy application data access.
    public function __construct() {}
    private function identity(Request $request): array
    {
        $user=$request->user();
        (new SiteAccessPolicy())->assertSiteMember($user);
        // Explicit administrator-created user linkage only. Never infer identity from a name/email.
        $teacher=DB::table('erp_teachers')->where('user_id',$user->id)->where('status','active')->first(['id','code','name','timezone']);
        if (!$teacher) throw new AuthorizationException('An active, explicitly linked teacher account is required.');
        $pools=DB::table('erp_teacher_units')->where('teacher_id',$teacher->id)->get();
        if ($pools->count() !== 1) throw new AuthorizationException('Teacher must belong to exactly one unit pool.');
        $unitId=(int)$pools->first()->unit_id;
        if (DB::table('erp_units')->where('id',$unitId)->whereNotNull('archived_at')->exists()) throw new AuthorizationException('This team is archived.');
        if (!DB::table('erp_memberships')->where('user_id',$user->id)->where('unit_id',$unitId)->where('role','teacher')->exists()) throw new AuthorizationException('Explicit teacher membership in this unit is required.');
        return [$teacher,$unitId,(int)$user->id];
    }

    public function result(Request $request)
    {
        [$teacher,$unitId,$actorId]=$this->identity($request);
        $v=$request->validate(['enrollment_id'=>'required|integer|min:1','assessment'=>'required|string|max:255','assessed_on'=>'required|date_format:Y-m-d|before_or_equal:'.\Carbon\CarbonImmutable::now($teacher->timezone)->toDateString(),'score'=>'required|integer|min:0|lte:maximum','maximum'=>'required|integer|min:1|max:1000000000','notes'=>'nullable|string|max:4000','submission_key'=>'required|uuid']);
        foreach(['enrollment_id','score','maximum'] as $field) $v[$field]=(int)$v[$field];
        $key=strtolower($v['submission_key']);unset($v['submission_key']);
        $hash=hash('sha256',json_encode([$unitId,$actorId,$teacher->id,$v]));
        $savedId=DB::transaction(function()use($request,$teacher,$unitId,$actorId,$v,$key,$hash){
            DB::table('erp_teachers')->where('id',$teacher->id)->lockForUpdate()->first();
            [$current,$currentUnit]=$this->identity($request);
            abort_unless($current->id===$teacher->id && $currentUnit===$unitId,403);
            $assignment=DB::table('erp_enrollments')->where('id',$v['enrollment_id'])->where('unit_id',$unitId)->where('teacher_id',$teacher->id)->lockForUpdate()->first();
            abort_unless($assignment,403,'Choose one of your own assignments.');
            $saved=DB::table('erp_marks')->where('submission_key',$key)->first();
            if($saved){abort_unless(hash_equals((string)$saved->submission_hash,$hash),409,'This submission was already used with different details. Reload before creating a new result.');return (int)$saved->id;}
            abort_unless($assignment->status==='active' && DB::table('erp_students')->where('id',$assignment->student_id)->where('unit_id',$unitId)->where('status','active')->exists(),422,'This assignment is no longer active.');
            $record=(new \App\Modules\Erp\Services\People())->mark($unitId,$actorId,$v);
            DB::table('erp_marks')->where('id',$record['id'])->update(['submission_key'=>$key,'submission_hash'=>$hash]);
            return (int)$record['id'];
        },3);
        // The React teacher portal posts JSON; a replay of the same submission answers with the same saved result.
        return response()->json(['message'=>'Assessment result saved.','result'=>['id'=>$savedId]]);
    }

    public function submit(Request $request)
    {
        [$teacher,$unitId,$actorId]=$this->identity($request);
        if ($request->boolean('no_show')) {
            $payload=Validator::make($request->only(['lesson_id','reason']),['lesson_id'=>'required|integer|min:1','reason'=>'required|string|max:2000'])->validate();
            DB::transaction(function() use ($unitId,$actorId,$teacher,$payload,$request) {
                DB::table('erp_teachers')->where('id',$teacher->id)->lockForUpdate()->first();
                [$current,$currentUnit]=$this->identity($request);
                if ($current->id !== $teacher->id || $currentUnit !== $unitId || !DB::table('erp_lessons')->where('id',$payload['lesson_id'])->where('unit_id',$unitId)->where('teacher_id',$teacher->id)->exists()) throw new AuthorizationException('Only your own dated lessons in your unit can be marked.');
                (new Attendance())->noShow($unitId,$actorId,$payload);
            }, 3);
            return response()->json(['message'=>'Recorded as a no-show. The student is not charged and the class is not paid.','result'=>['lessonId'=>(int)$payload['lesson_id'],'status'=>'no_show']]);
        }
        $payload=Validator::make($request->only(['lesson_id','actual_minutes','actual_starts_at','change_reason']),['actual_starts_at'=>'nullable|date_format:Y-m-d\\TH:i','change_reason'=>'nullable|string|max:4000','lesson_id'=>'required|integer|min:1','actual_minutes'=>'required|integer|min:1|max:480'])->validate();
        if (!empty($payload['actual_starts_at'])) $payload['actual_starts_at']=\Carbon\CarbonImmutable::createFromFormat('Y-m-d\\TH:i',$payload['actual_starts_at'],$teacher->timezone)->utc()->format('Y-m-d H:i:s');
        $record=DB::transaction(function() use ($unitId,$actorId,$teacher,$payload,$request) {
            // Stable teacher lock also serializes scheduling and explicit pool changes.
            DB::table('erp_teachers')->where('id',$teacher->id)->lockForUpdate()->first();
            [$current,$currentUnit]=$this->identity($request);
            if ($current->id !== $teacher->id || $currentUnit !== $unitId || !DB::table('erp_lessons')->where('id',$payload['lesson_id'])->where('unit_id',$unitId)->where('teacher_id',$teacher->id)->exists()) throw new AuthorizationException('Only your own dated lessons in your unit can receive your attendance.');
            return (new Attendance())->submit($unitId,$actorId,$payload);
        }, 3);
        return response()->json(['message'=>'Attendance submitted for administrator review.','result'=>['id'=>(int)$record->id,'lessonId'=>(int)$record->lesson_id,'actualMinutes'=>(int)$record->actual_minutes,'status'=>$record->status]]);
    }
}
