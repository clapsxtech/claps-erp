<?php
namespace Tests\Feature;

use Tests\TestCase;

/** Bugs found by the site administrator journey simulation (docs/JOURNEY_SIMULATION.md). */
class AdminJourneyTest extends TestCase
{
    public function test_account_changes_do_not_use_up_other_rate_limits(): void
    {
        $admin=$this->createAdmin();
        $staff=$this->createMember();
        $this->actingAs($admin);
        for ($i=0; $i<7; $i++) {
            $this->postJson(route('erp.accounts.update',$staff->id),['action'=>'revoke_sessions','current_password'=>'Local-Testing-Only-2026'])->assertOk();
        }
        $this->postJson(route('two-factor.setup'),['current_password'=>'Local-Testing-Only-2026'])->assertOk()->assertJsonStructure(['secret','otpauthUri']);
        $this->postJson(route('erp.migration.execute'),[])->assertStatus(422);
    }

    public function test_a_new_team_reuses_shared_subjects_whatever_the_case(): void
    {
        $this->actingAs($this->createAdmin());
        $this->postJson(route('erp.teams.create'),['code'=>'CL','name'=>'Calicut Centre','timezone'=>'Asia/Kolkata'])->assertOk();
        $team=\Illuminate\Support\Facades\DB::table('erp_units')->where('code','CL')->value('id');
        $maths=\Illuminate\Support\Facades\DB::table('erp_subjects')->insertGetId(['name'=>'Maths']);
        $this->postJson(route('erp.mutate',[$team,'teacher']),['code'=>'CL-T1','name'=>'Calicut Teacher','timezone'=>'Asia/Kolkata'])->assertOk();
        $teacher=\Illuminate\Support\Facades\DB::table('erp_teachers')->where('code','CL-T1')->value('id');
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('erp_teacher_units')->where(['teacher_id'=>$teacher,'unit_id'=>$team])->exists());
        $this->postJson(route('erp.mutate',[$team,'eligibility']),['teacher_id'=>$teacher,'subject'=>'  maths ','grade'=>'8','syllabus'=>'State'])->assertOk();
        $this->assertSame(1,\Illuminate\Support\Facades\DB::table('erp_subjects')->whereRaw('LOWER(name) = ?',['maths'])->count());
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('erp_eligibilities')->where(['teacher_id'=>$teacher,'subject_id'=>$maths])->exists());
    }
}
