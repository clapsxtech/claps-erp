<?php
namespace Tests\Feature;

use App\Modules\Erp\Services\Operations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamAccessTest extends TestCase
{
    private function team(string $code): int
    {
        return DB::table('erp_units')->insertGetId(['code'=>$code,'name'=>'Team '.$code,'timezone'=>'UTC']);
    }

    private function embedded($response): array
    {
        $html=$response->getContent();$tag='<script type="application/json" id="erp-session">';$start=strpos($html,$tag)+strlen($tag);
        return json_decode(html_entity_decode(substr($html,$start,strpos($html,'</script>',$start)-$start)),true);
    }

    /** Team names the staff app shell lists for the signed-in user. */
    private function appTeams(): array
    {
        return array_column($this->embedded($this->get('/app')->assertOk())['units'],'name','id');
    }

    public function test_superadmin_can_assign_and_revoke_staff_without_team_membership(): void
    {
        $admin=$this->createAdmin(); $staff=$this->createMember();
        $a=$this->team('A'); $b=$this->team('B');
        $this->actingAs($admin)->get('/')->assertRedirect('/app');
        $this->assertSame([$a=>'Team A',$b=>'Team B'],$this->appTeams());
        $this->getJson('/app-api/units/'.$b.'/admin/team')->assertOk()->assertJsonPath('team.name','Team B');
        $this->postJson(route('erp.business.mutate',[$a,'membership-grant']),['user_id'=>$staff->id,'role'=>'staff'])->assertOk();
        $this->assertDatabaseHas('erp_audit',['action'=>'membership.granted','actor_id'=>$admin->id]);
        $this->actingAs($staff)->get('/')->assertRedirect('/app');
        $this->assertSame([$a=>'Team A'],$this->appTeams());
        $this->assertNotContains('staff',$this->embedded($this->get('/app'))['units'][0]['pages']);
        $this->getJson('/app-api/units/'.$a.'/students')->assertOk()->assertDontSee('Team B');
        $this->postJson(route('erp.mutate',[$a,'task']),['title'=>'Team task'])->assertOk();
        foreach (['/app-api/units/'.$b.'/overview/home','/app-api/units/'.$b.'/money/summary','/app-api/units/'.$b.'/students',route('erp.export',[$b,'students']),route('erp.lookup',[$b,'type'=>'students'])] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson(route('erp.mutate',[$b,'task']),['title'=>'Forbidden'])->assertForbidden();
        $this->getJson('/app-api/units/'.$a.'/admin/team')->assertForbidden();
        $this->getJson(route('erp.lookup',[$a,'type'=>'users']))->assertForbidden();
        foreach (['membership-grant','membership-revoke','teacher-link','teacher-unlink'] as $action) {
            $this->postJson(route('erp.business.mutate',[$a,$action]),['user_id'=>$staff->id,'role'=>'admin'])->assertForbidden();
        }
        // Site administration and the historical records (old /admin/users, /admin/roles, /admin/settings, /markreport, /attendanceReport).
        foreach (['/app-api/admin/teams','/app-api/admin/accounts','/app-api/records/users','/app-api/records/roles','/app-api/records/settings','/app-api/records/assessment-reports','/app-api/records/attendance'] as $url) $this->getJson($url)->assertForbidden();
        $this->actingAs($admin)->postJson(route('erp.business.mutate',[$a,'membership-revoke']),['user_id'=>$staff->id])->assertOk();
        $this->actingAs($staff)->getJson('/app-api/units/'.$a.'/overview/home')->assertForbidden();
        $this->assertSame([],$this->appTeams());
        $this->assertDatabaseMissing('erp_tasks',['title'=>'Forbidden']);
    }

    public function test_assignment_service_rejects_team_admin_privilege_escalation(): void
    {
        $staff=$this->createMember(); $team=$this->team('A');
        DB::table('erp_memberships')->insert(['user_id'=>$staff->id,'unit_id'=>$team,'role'=>'admin']);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        (new Operations())->grantMembership($team,$staff->id,['user_id'=>$staff->id,'role'=>'staff']);
    }

    public function test_login_portals_enforce_account_role_and_active_organization(): void
    {
        $admin=$this->createMember(['user_type'=>'superadmin']); $staff=$this->createMember();
        $this->assertSame(['kind'=>'guest','screen'=>'login','status'=>null,'portal'=>'superadmin'],$this->embedded($this->get('/superadmin/login')->assertOk()->assertSee('Superadmin login')));
        $this->assertSame('staff',$this->embedded($this->get('/staff/login')->assertOk()->assertSee('Staff login'))['portal']);
        $credentials=['email'=>$staff->email,'password'=>'Local-Testing-Only-2026','portal'=>'superadmin'];
        $this->postJson('/login',$credentials)->assertUnprocessable(); $this->assertGuest();
        $this->postJson('/login',array_merge($credentials,['portal'=>'staff']))->assertOk()->assertJson(['redirect'=>url('/app')]); $this->assertAuthenticatedAs($staff);
        $this->post('/logout');
        $this->postJson('/login',array_merge($credentials,['email'=>$admin->email]))->assertOk()->assertJson(['redirect'=>url('/app')]); $this->assertAuthenticatedAs($admin);
        $this->post('/logout');
        $admin->forceFill(['status'=>0])->save();
        $this->postJson('/login',array_merge($credentials,['email'=>$admin->email]))->assertUnprocessable(); $this->assertGuest();
        $staff->forceFill(['business_id'=>2])->save();
        $this->postJson('/login',array_merge($credentials,['portal'=>'staff']))->assertUnprocessable(); $this->assertGuest();
        $this->get('/register')->assertNotFound();
    }

    public function test_staff_cannot_mutate_a_foreign_record_through_their_own_team(): void
    {
        $staff=$this->createMember(); $a=$this->team('A'); $b=$this->team('B');
        DB::table('erp_memberships')->insert(['user_id'=>$staff->id,'unit_id'=>$a,'role'=>'staff']);
        $task=DB::table('erp_tasks')->insertGetId(['unit_id'=>$b,'title'=>'Private task','status'=>'open','created_by'=>$staff->id]);
        $this->actingAs($staff)->postJson(route('erp.business.mutate',[$a,'task-update']),['task_id'=>$task,'status'=>'completed'])->assertUnprocessable();
        $this->assertDatabaseHas('erp_tasks',['id'=>$task,'status'=>'open']);
    }
}
