<?php
namespace Tests\Feature;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Bugs found by the site administrator journey simulation (docs/JOURNEY_SIMULATION.md). */
class AdminJourneyTest extends TestCase
{
    public function test_roles_can_be_created_without_permissions_and_cleared_on_update(): void
    {
        $this->actingAs($this->createAdmin());
        $this->post('/admin/roles',['name'=>'Auditors'])->assertRedirect('admin/roles')->assertSessionHas('status','success');
        $role=Role::where('name','Auditors')->firstOrFail();

        Permission::create(['name'=>'reports.view','guard_name'=>$role->guard_name]);
        $role->givePermissionTo('reports.view');
        $this->put('/admin/roles/'.$role->id,['name'=>'Auditors'])->assertRedirect('admin/roles')->assertSessionHas('status','success');
        $this->assertCount(0,$role->fresh()->permissions,'Unticking every permission removes them all.');
    }

    public function test_account_changes_do_not_use_up_other_rate_limits(): void
    {
        $admin=$this->createAdmin();
        $staff=$this->createMember();
        $this->actingAs($admin);
        for ($i=0; $i<7; $i++) {
            $this->post(route('erp.accounts.update',$staff->id),['action'=>'revoke_sessions','current_password'=>'Local-Testing-Only-2026'])->assertRedirect();
        }
        $this->post(route('two-factor.setup'),['current_password'=>'Local-Testing-Only-2026'])->assertRedirect(route('account.security'));
        $this->postJson(route('erp.migration.execute'),[])->assertStatus(422);
    }

    public function test_a_new_team_reuses_shared_subjects_whatever_the_case(): void
    {
        $this->actingAs($this->createAdmin());
        $this->post(route('erp.teams.create'),['code'=>'CL','name'=>'Calicut Centre','timezone'=>'Asia/Kolkata'])->assertRedirect();
        $team=\Illuminate\Support\Facades\DB::table('erp_units')->where('code','CL')->value('id');
        $maths=\Illuminate\Support\Facades\DB::table('erp_subjects')->insertGetId(['name'=>'Maths']);
        $this->post(route('erp.mutate',[$team,'teacher']),['code'=>'CL-T1','name'=>'Calicut Teacher','timezone'=>'Asia/Kolkata'])->assertRedirect();
        $teacher=\Illuminate\Support\Facades\DB::table('erp_teachers')->where('code','CL-T1')->value('id');
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('erp_teacher_units')->where(['teacher_id'=>$teacher,'unit_id'=>$team])->exists());
        $this->post(route('erp.mutate',[$team,'eligibility']),['teacher_id'=>$teacher,'subject'=>'  maths ','grade'=>'8','syllabus'=>'State'])->assertRedirect();
        $this->assertSame(1,\Illuminate\Support\Facades\DB::table('erp_subjects')->whereRaw('LOWER(name) = ?',['maths'])->count());
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('erp_eligibilities')->where(['teacher_id'=>$teacher,'subject_id'=>$maths])->exists());
    }
}
