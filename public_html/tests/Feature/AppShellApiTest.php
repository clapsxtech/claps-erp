<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The React app host page and its JSON reads keep the classic role matrix. Synthetic data only. */
class AppShellApiTest extends TestCase
{
    private int $unit;
    private int $other;
    private int $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'APP-A', 'name' => 'App Unit A', 'timezone' => 'UTC']);
        $this->other = DB::table('erp_units')->insertGetId(['code' => 'APP-B', 'name' => 'Private Unit B', 'timezone' => 'UTC']);
        $this->student = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'APP-S1', 'name' => 'Synthetic Owing Student', 'registered_on' => '2026-01-05', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE', 'guardian_contact' => '+910000000000']);
        DB::table('erp_students')->insert(['unit_id' => $this->unit, 'code' => 'APP-S2', 'name' => 'Synthetic Clear Student', 'registered_on' => '2026-01-06', 'timezone' => 'UTC', 'status' => 'paused', 'grade' => '9', 'syllabus' => 'ICSE']);
        DB::table('erp_students')->insert(['unit_id' => $this->other, 'code' => 'APP-SECRET', 'name' => 'Private Other Student', 'registered_on' => '2026-01-01', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE']);
        DB::table('erp_ledger')->insert(['unit_id' => $this->unit, 'student_id' => $this->student, 'kind' => 'lesson_charge', 'amount_paise' => -45000, 'currency' => 'INR', 'reference' => 'APP-CHG-1', 'event_key' => 'app-chg-1', 'occurred_at' => now(), 'created_by' => 1]);
    }

    private function member(string $role)
    {
        $user = $this->createMember(['name' => 'Synthetic '.$role]);
        DB::table('erp_memberships')->insert(['unit_id' => $this->unit, 'user_id' => $user->id, 'role' => $role]);
        return $user;
    }

    public function test_host_page_embeds_only_permitted_units_and_capabilities(): void
    {
        $finance = $this->member('finance');
        $response = $this->actingAs($finance)->get('/app/u/'.$this->unit.'/payments')->assertOk()->assertSee('id="erp-root"', false);
        $session = json_decode(html_entity_decode($this->between($response->getContent(), '<script type="application/json" id="erp-session">', '</script>')), true);
        $this->assertCount(1, $session['units']);
        $this->assertSame('App Unit A', $session['units'][0]['name']);
        $this->assertSame(['money', 'accounts'], $session['units'][0]['pages']);
        $this->assertContains('payment', $session['units'][0]['actions']);
        $this->assertNotContains('student', $session['units'][0]['actions']);
        $this->assertFalse($session['user']['isAdmin']);
        $this->assertStringNotContainsString('Private Unit B', $response->getContent());
    }

    public function test_host_page_requires_sign_in(): void
    {
        $this->get('/app/u/'.$this->unit.'/students')->assertRedirect('/login');
    }

    public function test_student_list_is_scoped_sorted_filtered_and_whitelisted(): void
    {
        $manager = $this->member('team_manager');
        $json = $this->actingAs($manager)->getJson('/app-api/units/'.$this->unit.'/students?sort=balance&dir=asc')->assertOk()->json();
        $this->assertSame(2, $json['meta']['total']);
        $this->assertSame('Synthetic Owing Student', $json['data'][0]['name']);
        $this->assertSame(-45000, $json['data'][0]['balancePaise']);
        $this->assertSame(['id', 'code', 'name', 'grade', 'syllabus', 'status', 'registeredOn', 'priority', 'activeClasses', 'balancePaise'], array_keys($json['data'][0]));
        $this->assertTrue($json['can']['openProfile']);
        $this->assertSame(['dueCount' => 1, 'duePaise' => -45000], $json['totals']);

        $this->getJson('/app-api/units/'.$this->unit.'/students?status=paused')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'APP-S2');
        $this->getJson('/app-api/units/'.$this->unit.'/students?due=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/app-api/units/'.$this->unit.'/students?q=APP-SECRET')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/app-api/units/'.$this->unit.'/students?sort=password')->assertStatus(422);
        $this->getJson('/app-api/units/'.$this->other.'/students')->assertForbidden();
    }

    public function test_academics_sees_students_without_balances_or_profiles(): void
    {
        $json = $this->actingAs($this->member('academics'))->getJson('/app-api/units/'.$this->unit.'/students?sort=balance')->assertOk()->json();
        $this->assertNull($json['data'][0]['balancePaise']);
        $this->assertNull($json['totals']);
        $this->assertSame('name', $json['meta']['sort']);
        $this->assertFalse($json['can']['openProfile']);
        $this->getJson('/app-api/units/'.$this->unit.'/students/'.$this->student)->assertForbidden();
    }

    public function test_roles_without_the_people_page_are_refused(): void
    {
        $this->actingAs($this->member('sales_followups'))->getJson('/app-api/units/'.$this->unit.'/students')->assertForbidden();
        $this->actingAs($this->member('finance'))->getJson('/app-api/units/'.$this->unit.'/students')->assertForbidden();
    }

    public function test_profile_is_for_managers_and_lists_explicit_fields(): void
    {
        $json = $this->actingAs($this->member('read_only'))->getJson('/app-api/units/'.$this->unit.'/students/'.$this->student)->assertOk()->json();
        $this->assertSame('APP-S1', $json['student']['code']);
        $this->assertSame([['currency' => 'INR', 'balancePaise' => -45000]], $json['balances']);
        $this->assertSame(['id', 'occurred_at', 'kind', 'reference', 'amount_paise', 'currency', 'reverses_id'], array_keys($json['ledger'][0]));
        $other = DB::table('erp_students')->where('code', 'APP-SECRET')->value('id');
        $this->getJson('/app-api/units/'.$this->unit.'/students/'.$other)->assertNotFound();
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start) + strlen($start);
        return substr($haystack, $from, strpos($haystack, $end, $from) - $from);
    }
}
