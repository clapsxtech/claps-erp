<?php
namespace Tests\Feature;
use App\Models\User;
use App\Modules\Erp\Services\{Administration,TeamPermissions};
use Illuminate\Support\Facades\{DB,Hash};
use Tests\TestCase;

class AdministrationWorkflowTest extends TestCase
{
    private function team(string $code='TEST'): int {return DB::table('erp_units')->insertGetId(['code'=>$code,'name'=>$code.' team','timezone'=>'UTC']);}
    /** The JSON read that replaced each old business page; each declares the same page permission. */
    private const READS=['people'=>'students','academics'=>'academics','money'=>'money/summary','followups'=>'followups'];
    private function page(int $unit,string $page): string {return '/app-api/units/'.$unit.'/'.self::READS[$page];}
    private function embedded($response): array
    {
        $html=$response->getContent();$tag='<script type="application/json" id="erp-session">';$start=strpos($html,$tag)+strlen($tag);
        return json_decode(html_entity_decode(substr($html,$start,strpos($html,'</script>',$start)-$start)),true);
    }
    public function test_superadmin_can_create_rename_archive_and_restore_arbitrary_teams(): void
    {
        $this->actingAs($this->createAdmin());
        $this->getJson('/app-api/admin/teams')->assertOk();
        $this->postJson(route('erp.teams.create'),['code'=>'NEW_TEAM','name'=>'New team','timezone'=>'UTC'])->assertOk()->assertJson(['message'=>'Team saved.']);
        $id=DB::table('erp_units')->where('code','NEW_TEAM')->value('id');
        $this->postJson(route('erp.teams.update',$id),['code'=>'NEW_TEAM','name'=>'Renamed team','timezone'=>'UTC'])->assertOk();
        $this->assertDatabaseHas('erp_units',['id'=>$id,'name'=>'Renamed team']);
        $staff=$this->createMember();DB::table('erp_memberships')->insert(['unit_id'=>$id,'user_id'=>$staff->id,'role'=>'team_manager']);
        $this->postJson(route('erp.teams.archive',$id),['archived'=>1])->assertOk();
        $this->getJson($this->page($id,'people'))->assertOk();
        $this->postJson(route('erp.mutate',[$id,'task']),['title'=>'Blocked'])->assertForbidden();
        $this->actingAs($staff)->getJson($this->page($id,'people'))->assertForbidden();
        $this->actingAs(User::find(1))->postJson(route('erp.teams.archive',$id),['archived'=>0])->assertOk();
        $this->actingAs($staff)->getJson($this->page($id,'people'))->assertOk();
        $this->assertDatabaseHas('erp_memberships',['user_id'=>$staff->id,'unit_id'=>$id]);
        $this->assertDatabaseHas('erp_audit',['action'=>'team.archived']);
    }
    public function test_onboarding_is_atomic_and_role_assignment_is_validated(): void
    {
        $this->actingAs($this->createAdmin());$a=$this->team('A1');$b=$this->team('B1');
        $payload=['name'=>'New employee','email'=>'new@example.invalid','phone'=>'9111111111','gender'=>'OTHER','password'=>'Strong-New-Password','confirm_password'=>'Strong-New-Password','team_ids'=>[$a,$b],'initial_role'=>'finance'];
        $this->postJson(route('erp.onboard'),$payload)->assertOk();
        $user=User::where('email',$payload['email'])->firstOrFail();
        $this->assertSame(2,DB::table('erp_memberships')->where('user_id',$user->id)->where('role','finance')->count());
        $this->assertTrue(Hash::check($payload['password'],$user->password));
        $payload['email']='bad@example.invalid';$payload['phone']='9222222222';$payload['team_ids']=[$a,999999];
        $this->postJson(route('erp.onboard'),$payload)->assertUnprocessable();
        $this->assertDatabaseMissing('users',['email'=>'bad@example.invalid']);
        $payload['team_ids']=[$a];$payload['initial_role']='superadmin';
        $this->postJson(route('erp.onboard'),$payload)->assertUnprocessable();
        $this->assertDatabaseMissing('users',['email'=>'bad@example.invalid']);
    }
    public function test_every_role_is_team_scoped_and_sensitive_actions_fail_closed(): void
    {
        $a=$this->team('A1');$b=$this->team('B1');$this->createAdmin();
        $cases=['team_manager'=>['people','task','payment'],'academics'=>['academics','mark','payment'],'finance'=>['money','payment','mark'],'sales_followups'=>['followups','task','payment'],'read_only'=>['people',null,'task']];
        foreach($cases as $role=>[$page,$allowed,$denied]){
            $user=$this->createMember();DB::table('erp_memberships')->insert(['unit_id'=>$a,'user_id'=>$user->id,'role'=>$role]);
            $this->actingAs($user)->get('/')->assertRedirect('/app');
            $this->get('/app')->assertOk();
            $this->getJson($this->page($a,$page))->assertOk();
            $this->getJson($this->page($b,$page))->assertForbidden();
            $this->getJson('/app-api/admin/teams')->assertForbidden();
            $this->getJson('/app-api/admin/accounts')->assertForbidden();
            $this->postJson(route('erp.business.mutate',[$a,'membership-grant']),['user_id'=>$user->id,'role'=>'admin'])->assertForbidden();
            $this->getJson(route('erp.lookup',[$a,'type'=>'users']))->assertForbidden();
            if($role!=='team_manager')$this->postJson(route('erp.mutate',[$a,$denied]),[])->assertForbidden();
            if($allowed){
                $response=$this->postJson(route('erp.mutate',[$a,$allowed]),[]);
                $response->assertUnprocessable(); // Allowed action reaches payload validation, never silently writes.
            }
        }
    }
    public function test_readonly_cannot_complete_own_task_or_use_composite_action_routes(): void
    {
        $user=$this->createMember();$unit=$this->team();
        DB::table('erp_memberships')->insert(['unit_id'=>$unit,'user_id'=>$user->id,'role'=>'read_only']);
        $task=DB::table('erp_tasks')->insertGetId(['unit_id'=>$unit,'created_by'=>$user->id,'assigned_to'=>$user->id,'title'=>'Read only task','status'=>'open']);
        $this->actingAs($user)->post(route('erp.tasks.complete',$task))->assertForbidden();
        $this->postJson(route('erp.business.mutate',[$unit,'task-update']),['task_id'=>$task,'status'=>'completed'])->assertForbidden();
        $this->getJson($this->page($unit,'followups'))->assertOk();
        // The app is told this role has no actions, so it shows no "Complete task" or "Save record".
        $units=collect($this->embedded($this->get('/app')->assertOk())['units'])->keyBy('id');
        $this->assertSame([],$units[$unit]['actions']);
        $this->assertDatabaseHas('erp_tasks',['id'=>$task,'status'=>'open']);
    }
    public function test_account_actions_require_password_and_revoke_sessions_without_exposing_secrets(): void
    {
        $admin=$this->createAdmin();$staff=$this->createMember();$unit=$this->team();
        DB::table('erp_memberships')->insert(['unit_id'=>$unit,'user_id'=>$staff->id,'role'=>'finance']);
        DB::table('sessions')->insert(['id'=>'synthetic-session','user_id'=>$staff->id,'payload'=>'test','last_activity'=>time()]);
        $this->actingAs($admin)->postJson(route('erp.accounts.update',$staff->id),['action'=>'suspend','current_password'=>'wrong'])->assertUnprocessable();
        $this->assertDatabaseHas('sessions',['id'=>'synthetic-session']);
        $this->postJson(route('erp.accounts.update',$staff->id),['action'=>'suspend','current_password'=>'Local-Testing-Only-2026'])->assertOk();
        $this->assertDatabaseMissing('sessions',['id'=>'synthetic-session']);
        $this->actingAs($staff->fresh())->get('/app')->assertForbidden();
        $this->getJson('/app-api/units/'.$unit.'/money/summary')->assertForbidden();
        $this->actingAs($admin)->postJson(route('erp.accounts.update',$staff->id),['action'=>'activate','current_password'=>'Local-Testing-Only-2026'])->assertOk();
        $this->actingAs($staff->fresh())->get('/app')->assertOk();
        $this->actingAs($admin)->postJson(route('erp.accounts.update',$staff->id),['action'=>'reset_password','current_password'=>'Local-Testing-Only-2026','password'=>'Changed-Strong-Password','password_confirmation'=>'Changed-Strong-Password'])->assertOk();
        $this->assertTrue(Hash::check('Changed-Strong-Password',$staff->fresh()->password));
        $this->assertStringNotContainsString('Password',DB::table('erp_audit')->where('entity_type','user')->pluck('payload')->implode(''));
        $this->postJson(route('erp.accounts.update',$admin->id),['action'=>'suspend','current_password'=>'Local-Testing-Only-2026'])->assertUnprocessable();
    }
    public function test_promotion_and_demotion_leave_team_assignments_but_remove_site_privileges(): void
    {
        $admin=$this->createAdmin();$staff=$this->createMember();
        $unit=$this->team();DB::table('erp_memberships')->insert(['unit_id'=>$unit,'user_id'=>$staff->id,'role'=>'finance']);
        $this->actingAs($admin)->postJson(route('erp.accounts.update',$staff->id),['action'=>'promote','current_password'=>'Local-Testing-Only-2026'])->assertOk();
        $this->assertSame('superadmin',$staff->fresh()->user_type);
        $this->actingAs($staff->fresh())->getJson('/app-api/admin/teams')->assertOk();
        $this->actingAs($admin)->postJson(route('erp.accounts.update',$staff->id),['action'=>'demote','current_password'=>'Local-Testing-Only-2026'])->assertOk();
        $this->actingAs($staff->fresh())->getJson('/app-api/admin/teams')->assertForbidden();
        $this->getJson('/app-api/admin/accounts')->assertForbidden();
        $this->assertDatabaseHas('erp_memberships',['unit_id'=>$unit,'user_id'=>$staff->id,'role'=>'finance']);
        $this->getJson('/app-api/units/'.$unit.'/money/summary')->assertOk();
    }
    public function test_operations_and_migration_are_superadmin_only_and_render(): void
    {
        $this->actingAs($this->createAdmin())->getJson('/app-api/admin/operations')->assertOk()->assertJsonPath('health.status','ok');
        $this->getJson('/app-api/admin/migration')->assertOk();
        $this->actingAs($this->createMember())->getJson('/app-api/admin/operations')->assertForbidden();
        $this->getJson('/app-api/admin/migration')->assertForbidden();
    }
}
