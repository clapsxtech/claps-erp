<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sales, HR and admin journeys: each role reaches its own screens' data and nothing else. */
class ErpMobileUiTest extends TestCase
{
    private function embedded($response): array
    {
        $html=$response->getContent();$tag='<script type="application/json" id="erp-session">';$start=strpos($html,$tag)+strlen($tag);
        return json_decode(html_entity_decode(substr($html,$start,strpos($html,'</script>',$start)-$start)),true);
    }

    public function test_sales_hr_and_admin_navigation_respects_existing_access(): void
    {
        $admin = $this->createAdmin(['name'=>'Workspace Admin']);
        $unit = DB::table('erp_units')->insertGetId(['code'=>'LEARN', 'name'=>'Claps Learning', 'timezone'=>'Asia/Kolkata']);
        $sales = $this->createMember(['name'=>'Sales Counsellor']);
        $manager = $this->createMember(['name'=>'Recruitment Manager']);
        DB::table('erp_memberships')->insert([
            ['unit_id'=>$unit,'user_id'=>$sales->id,'role'=>'sales_followups'],
            ['unit_id'=>$unit,'user_id'=>$manager->id,'role'=>'team_manager'],
        ]);
        $base = '/app-api/units/'.$unit;
        $this->actingAs($sales)->postJson(route('erp.business.mutate',[$unit,'admission-create-lead']), [
            'name'=>'Ananya — Mathematics enquiry','phone'=>'+91 90000 12345','enquiry_date'=>'2026-10-26',
            'grade'=>'Grade 8','subject'=>'Mathematics','source'=>'Referral',
        ])->assertOk();
        // The sales pipeline lists the lead with its normalized, callable phone number.
        $this->getJson($base.'/admissions')->assertOk()->assertSee('Ananya')->assertSee('919000012345');
        $pages = $this->embedded($this->get('/app')->assertOk())['units'][0]['pages'];
        $this->assertContains('admissions', $pages);
        $this->assertNotContains('accounts', $pages);
        $this->assertNotContains('attendance', $pages);
        $this->getJson($base.'/accounts/overview')->assertForbidden();
        $this->getJson($base.'/attendance')->assertForbidden();

        $this->actingAs($manager)->postJson(route('erp.business.mutate',[$unit,'teacher-candidate-create']), [
            'name'=>'Meera Nair','phone'=>'9000012346','email'=>'meera@example.invalid',
            'qualification'=>'MSc Mathematics, BEd','subjects'=>'Mathematics, Science',
        ])->assertOk();
        $this->getJson($base.'/recruitment')->assertOk()->assertSee('Meera Nair');
        $this->getJson($base.'/recruitment/'.DB::table('erp_teacher_candidates')->value('id'))->assertOk()->assertSee('Meera Nair');
        $this->getJson('/app-api/admin/teams')->assertForbidden();
        $this->getJson('/app-api/admin/accounts')->assertForbidden();
        $this->getJson('/app-api/admin/operations')->assertForbidden();

        $this->actingAs($admin)->getJson('/app-api/admin/teams')->assertOk()->assertSee('Claps Learning');
        $this->getJson('/app-api/admin/accounts')->assertOk()->assertSee('Sales Counsellor');
        $this->getJson('/app-api/admin/operations')->assertOk();
    }
}
