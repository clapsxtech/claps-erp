<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpUnifiedEntryTest extends TestCase
{
    private function embedded($response): array
    {
        $html=$response->getContent();$tag='<script type="application/json" id="erp-session">';$start=strpos($html,$tag)+strlen($tag);
        return json_decode(html_entity_decode(substr($html,$start,strpos($html,'</script>',$start)-$start)),true);
    }

    public function test_root_is_the_workspace_and_old_entry_points_are_gone(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/app')->assertRedirect('/login');
        // The old entry points were removed without redirects (product decision).
        foreach (['/erp','/erp/','/legacy','/home','/tools'] as $old) $this->get($old)->assertNotFound();
        $admin=$this->createAdmin();
        $unit=DB::table('erp_units')->insertGetId(['code'=>'ENTRY','name'=>'Entry Unit','timezone'=>'UTC']);
        DB::table('erp_memberships')->insert(['unit_id'=>$unit,'user_id'=>$admin->id,'role'=>'admin']);
        $this->actingAs($admin)->get('/')->assertRedirect('/app');
        $session=$this->embedded($this->get('/app')->assertOk());
        $this->assertSame('staff',$session['kind']);
        $this->assertTrue($session['user']['isAdmin']);
        $this->assertSame(['Entry Unit'],array_column($session['units'],'name'));
        // Records & administration, including user accounts and academics records, is open to the administrator.
        $this->getJson('/app-api/records')->assertOk();
        $this->getJson('/app-api/records/users')->assertOk()->assertSee('QA Admin');
        $this->getJson('/app-api/records/assessments')->assertOk();
    }

    public function test_site_administration_stays_out_of_member_reach(): void
    {
        $member=$this->createMember();
        $session=$this->embedded($this->actingAs($member)->get('/app')->assertOk());
        $this->assertFalse($session['user']['isAdmin']);
        // The old /tools page only showed members a directory of links; every record screen behind it was admin-only.
        foreach (['/app-api/records','/app-api/records/users','/app-api/records/settings','/app-api/admin/accounts'] as $url) $this->getJson($url)->assertForbidden();
        $this->actingAs($this->createMember(['business_id'=>2]))->get('/app')->assertForbidden();
        $this->getJson('/app-api/records')->assertForbidden();
    }
}
