<?php
namespace App\Modules\Erp\Services;

use App\Models\User;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use App\Modules\People\Services\StaffProvisioner;
use Illuminate\Support\Facades\{DB,Hash,Validator};
use Illuminate\Validation\{Rule,ValidationException};
use Illuminate\Support\Str;

class Administration
{
    private function admin(User $actor): void {(new SiteAccessPolicy())->assertAdministrator($actor);}
    public function team(User $actor,array $input,?int $id=null): int
    {
        $this->admin($actor);
        $v=Validator::make($input,[
            'code'=>['required','regex:/^[A-Z][A-Z0-9_-]{1,19}$/',Rule::unique('erp_units','code')->ignore($id)],
            'name'=>'required|string|max:150','timezone'=>'required|timezone','phone'=>'nullable|string|max:30',
            'legal_name'=>'nullable|string|max:200','address'=>'nullable|string|max:1000','state'=>'nullable|string|max:60','tax_mode'=>'nullable|in:none,gst',
            'tax_id'=>['nullable','required_if:tax_mode,gst','regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],'invoice_prefix'=>['nullable','regex:/^[A-Z0-9-]{1,20}$/'],
        ],['tax_id.regex'=>'Enter a 15-character GSTIN in capitals, for example 32ABCDE1234F1Z5.','tax_id.required_if'=>'A GST-registered team needs its GSTIN.'])->validate();
        if(array_key_exists('state',$v)) $v['state']=IndianStates::normalize($v['state'],'state');
        if(!empty($v['tax_id']) && !empty($v['state']) && substr($v['tax_id'],0,2)!==IndianStates::code($v['state'])) throw ValidationException::withMessages(['tax_id'=>'The GSTIN starts with '.substr($v['tax_id'],0,2).', which is not the GST code for '.$v['state'].' ('.IndianStates::code($v['state']).').']);
        if(($v['tax_mode']??null)==='gst' && empty($v['state'])) throw ValidationException::withMessages(['state'=>'A GST-registered team needs its state, to decide CGST/SGST or IGST.']);
        if(array_key_exists('tax_mode',$v) && $v['tax_mode']===null) unset($v['tax_mode']);
        return DB::transaction(function()use($actor,$v,$id){
            if($id){
                $unit=DB::table('erp_units')->where('id',$id)->lockForUpdate()->first(); abort_unless($unit,404);
                // Dates already materialized in this team must retain their timezone semantics.
                if($unit->timezone!==$v['timezone'] && DB::table('erp_lessons')->where('unit_id',$id)->exists()) throw ValidationException::withMessages(['timezone'=>'A team with dated lessons cannot change timezone.']);
                // Post existing charges under the current tax settings before they change.
                foreach(['tax_mode','state','tax_id'] as $taxField) if(array_key_exists($taxField,$v) && $v[$taxField]!==$unit->$taxField){ (new Books())->sync($id); break; }
                DB::table('erp_units')->where('id',$id)->update($v+['updated_at'=>now()]);
            } else $id=DB::table('erp_units')->insertGetId($v+['created_at'=>now(),'updated_at'=>now()]);
            Audit::record($id,$actor->id,'team.saved','unit',$id,$v);return $id;
        });
    }
    public function archive(User $actor,int $id,bool $archive): void
    {
        $this->admin($actor);
        DB::transaction(function()use($actor,$id,$archive){
            abort_unless(DB::table('erp_units')->where('id',$id)->lockForUpdate()->first(),404);
            DB::table('erp_units')->where('id',$id)->update(['archived_at'=>$archive?now():null,'updated_at'=>now()]);
            Audit::record($id,$actor->id,$archive?'team.archived':'team.restored','unit',$id,[]);
        });
    }
    public function onboard(User $actor,array $input): User
    {
        $this->admin($actor);
        $v=Validator::make($input,['assignments'=>'required|array|min:1|max:30','assignments.*.unit_id'=>'required|integer|distinct|exists:erp_units,id','assignments.*.role'=>['required',Rule::in(TeamPermissions::ROLES)]])->validate();
        return DB::transaction(function()use($actor,$input,$v){
            foreach($v['assignments'] as $assignment) abort_if(DB::table('erp_units')->where('id',$assignment['unit_id'])->lockForUpdate()->value('archived_at'),422,'Restore the archived team before assigning staff.');
            $user=app(StaffProvisioner::class)->create($actor,collect($input)->except('assignments','_token')->all());
            foreach($v['assignments'] as $assignment) (new Operations())->grantMembership((int)$assignment['unit_id'],$actor->id,['user_id'=>$user->id,'role'=>$assignment['role']]);
            Audit::record(null,$actor->id,'account.onboarded','user',$user->id,['teams'=>array_column($v['assignments'],'unit_id')]);
            return $user;
        });
    }
    public function account(User $actor,int $id,array $input): void
    {
        $this->admin($actor);
        $v=Validator::make($input,['action'=>'required|in:suspend,activate,reset_password,revoke_sessions,promote,demote','current_password'=>'required|string','password'=>'required_if:action,reset_password|nullable|string|min:12|max:72|confirmed'])->validate();
        if(!Hash::check($v['current_password'],$actor->password)) throw ValidationException::withMessages(['current_password'=>'Your current password is incorrect.']);
        if(!empty($v['password']) && strlen($v['password'])>72) throw ValidationException::withMessages(['password'=>'Password must be at most 72 bytes.']);
        DB::transaction(function()use($actor,$id,$v){
            // Serialize administrative changes and recheck actor after acquiring the account locks.
            $accounts=User::where('business_id',config('erp.business_id'))->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get();
            $freshActor=$accounts->firstWhere('id',$actor->id);$this->admin($freshActor);
            $user=$accounts->firstWhere('id',$id);abort_unless($user,404);
            $action=$v['action'];$policy=new SiteAccessPolicy();
            if(in_array($action,['suspend','demote'],true)) {
                abort_if($id===$actor->id,422,'Use a different superadmin to change your own access.');
                abort_if($action==='demote' && $id===1,422,'The installation owner cannot be demoted.');
                if($policy->isAdministrator($user) && $accounts->filter(fn($u)=>$policy->isAdministrator($u))->count()<=1) abort(422,'Keep at least one active superadmin.');
            }
            if($action==='suspend')$user->status=0;
            if($action==='activate')$user->status=1;
            if($action==='reset_password')$user->password=Hash::make($v['password']);
            if($action==='promote'){$user->user_type='superadmin';$user->status=1;}
            if($action==='demote'){$user->user_type='user';$user->syncRoles([]);$user->syncPermissions([]);}
            $user->remember_token=Str::random(60);$user->save();
            DB::table('sessions')->where('user_id',$id)->delete();
            Audit::record(null,$actor->id,'account.'.$action,'user',$id,[]);
        });
    }
}
