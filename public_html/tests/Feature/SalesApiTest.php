<?php

namespace Tests\Feature;

use App\Http\Middleware\SalesGatewayOnly;
use App\Modules\Erp\Services\SalesApiTokens;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Crypt, DB};
use OTPHP\TOTP;
use Tests\TestCase;

class SalesApiTest extends TestCase
{
    private const GATEWAY = 'test-gateway-token';
    private const PASSWORD = 'Local-Testing-Only-2026';
    private $sales;
    private $otherSales;
    private $finance;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 08:00:00');CarbonImmutable::setTestNow('2026-11-21 08:00:00');
        config(['services.sales_gateway.token' => self::GATEWAY]);
        $this->createAdmin();
        $this->sales = $this->createMember(['id' => 41, 'email' => 'sales41@example.invalid']);
        $this->otherSales = $this->createMember(['id' => 42, 'email' => 'sales42@example.invalid']);
        $this->finance = $this->createMember(['id' => 43, 'email' => 'finance43@example.invalid']);
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'API-AA', 'name' => 'Claps Learn', 'timezone' => 'Asia/Kolkata']);
        DB::table('erp_memberships')->insert([
            ['unit_id' => $this->unit, 'user_id' => 41, 'role' => 'sales_followups'],
            ['unit_id' => $this->unit, 'user_id' => 42, 'role' => 'sales_followups'],
            ['unit_id' => $this->unit, 'user_id' => 43, 'role' => 'finance'],
        ]);
    }

    protected function tearDown(): void { Carbon::setTestNow();CarbonImmutable::setTestNow();parent::tearDown(); }

    private function gateway(array $headers = []): array
    {
        return [SalesGatewayOnly::HEADER => self::GATEWAY, 'Accept' => 'application/json'] + $headers;
    }

    private function bearer($user): array
    {
        return $this->gateway(['Authorization' => 'Bearer '.(new SalesApiTokens())->issue($user)['token']]);
    }

    private function url(string $path): string
    {
        return '/api/sales/units/'.$this->unit.$path;
    }

    public function test_requests_without_the_gateway_header_are_not_found(): void
    {
        $this->postJson('/api/sales/login', ['email' => 'sales41@example.invalid', 'password' => self::PASSWORD])->assertNotFound();
        $this->postJson('/api/sales/login', ['email' => 'sales41@example.invalid', 'password' => self::PASSWORD], [SalesGatewayOnly::HEADER => 'wrong'])->assertNotFound();
        $this->getJson($this->url('/overview'), [SalesGatewayOnly::HEADER => 'wrong', 'Authorization' => 'Bearer x'])->assertNotFound();
        config(['services.sales_gateway.token' => '']);
        $this->postJson('/api/sales/login', ['email' => 'sales41@example.invalid', 'password' => self::PASSWORD], [SalesGatewayOnly::HEADER => ''])->assertNotFound();
    }

    public function test_sign_in_issues_a_token_for_sales_staff_only(): void
    {
        $this->postJson('/api/sales/login', ['email' => 'sales41@example.invalid', 'password' => 'wrong'], $this->gateway())->assertStatus(422);
        $this->postJson('/api/sales/login', ['email' => 'finance43@example.invalid', 'password' => self::PASSWORD], $this->gateway())
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'This account does not have access to the Sales CRM.');
        $response = $this->postJson('/api/sales/login', ['email' => 'sales41@example.invalid', 'password' => self::PASSWORD], $this->gateway())->assertOk()
            ->assertJsonPath('user.id', 41)->assertJsonPath('user.units.0.id', $this->unit)->assertJsonPath('user.units.0.role', 'sales_followups');
        $this->assertContains('create-lead', $response->json('user.units.0.actions'));
        $this->getJson('/api/sales/me', $this->gateway(['Authorization' => 'Bearer '.$response->json('token')]))->assertOk()->assertJsonPath('user.name', 'QA Staff');
    }

    public function test_two_factor_accounts_must_send_a_code(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        DB::table('users')->where('id', 41)->update(['two_factor_secret' => Crypt::encryptString($secret)]);
        $this->postJson('/api/sales/login', ['email' => 'sales41@example.invalid', 'password' => self::PASSWORD], $this->gateway())
            ->assertStatus(202)->assertJsonPath('two_factor_required', true)->assertJsonMissing(['token']);
        $code = TOTP::createFromSecret($secret)->at(now()->timestamp);
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/sales/login', ['email' => 'sales41@example.invalid', 'password' => self::PASSWORD, 'code' => $wrong], $this->gateway())->assertStatus(422);
        $this->postJson('/api/sales/login', ['email' => 'sales41@example.invalid', 'password' => self::PASSWORD, 'code' => $code], $this->gateway())
            ->assertOk()->assertJsonStructure(['token', 'expires_at', 'user']);
    }

    public function test_repeated_failed_sign_ins_are_throttled(): void
    {
        for ($i = 0; $i < 8; $i++) $this->postJson('/api/sales/login', ['email' => 'sales41@example.invalid', 'password' => 'wrong'], $this->gateway())->assertStatus(422);
        $this->postJson('/api/sales/login', ['email' => 'sales41@example.invalid', 'password' => self::PASSWORD], $this->gateway())
            ->assertStatus(422)->assertJsonPath('errors.email.0', fn ($message) => str_starts_with($message, 'Too many sign-in attempts'));
    }

    public function test_invalid_expired_and_revoked_tokens_are_rejected(): void
    {
        $this->getJson($this->url('/overview'), $this->gateway(['Authorization' => 'Bearer not-a-token']))->assertStatus(401);
        $headers = $this->bearer($this->sales);
        $this->getJson($this->url('/overview'), $headers)->assertOk();
        Carbon::setTestNow(now()->addSeconds(SalesApiTokens::TTL_SECONDS + 1));
        $this->getJson($this->url('/overview'), $headers)->assertStatus(401);
        Carbon::setTestNow('2026-11-21 08:00:00');
        DB::table('users')->where('id', 41)->update(['password' => bcrypt('Changed-Password-2026')]);
        $this->getJson($this->url('/overview'), $headers)->assertStatus(401);
        $headers = $this->bearer($this->sales->fresh());
        DB::table('users')->where('id', 41)->update(['status' => 0]);
        $this->getJson($this->url('/overview'), $headers)->assertStatus(401);
    }

    public function test_lead_round_trip_uses_erp_rules_and_visibility(): void
    {
        $headers = $this->bearer($this->sales);
        $created = $this->postJson($this->url('/actions/create-lead'), ['phone' => '9000088001', 'country' => 'IN', 'grade' => '8'], $headers)->assertOk()->json('result');
        $this->assertDatabaseHas('erp_leads', ['id' => $created['id'], 'unit_id' => $this->unit, 'phone' => '9000088001', 'created_by' => 41]);
        $this->postJson($this->url('/actions/followup'), ['lead_id' => $created['id'], 'channel' => 'call', 'note' => 'Called parent', 'outcome' => 'Interested', 'next_due_on' => '2026-11-22'], $headers)->assertOk();
        $this->postJson($this->url('/actions/reminder'), ['lead_id' => $created['id'], 'due_on' => '2026-11-21', 'text' => 'Send demo slots'], $headers)->assertOk();

        $this->getJson($this->url('/leads'), $headers)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $created['id']);
        $detail = $this->getJson($this->url('/leads/'.$created['id']), $headers)->assertOk()
            ->assertJsonPath('lead.phone', '9000088001')->assertJsonPath('contact.whatsapp', 'https://wa.me/919000088001');
        $this->assertNotEmpty($detail->json('activities'));
        $this->getJson($this->url('/reminders?due=1'), $headers)->assertOk()->assertJsonPath('total', 1);
        $this->getJson($this->url('/overview'), $headers)->assertOk()->assertJsonPath('active_leads', 1)->assertJsonPath('due_reminders', 1);

        // Another salesperson cannot see or change this lead.
        $other = $this->bearer($this->otherSales);
        $this->getJson($this->url('/leads'), $other)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->url('/leads/'.$created['id']), $other)->assertNotFound();
        $this->postJson($this->url('/actions/update-lead'), ['lead_id' => $created['id'], 'is_hot' => true], $other)->assertForbidden();
        $this->assertDatabaseHas('erp_leads', ['id' => $created['id'], 'is_hot' => false]);

        // ERP validation still applies.
        $this->postJson($this->url('/actions/create-lead'), ['country' => 'IN'], $headers)->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_roles_without_sales_access_are_refused(): void
    {
        $headers = $this->bearer($this->finance);
        $this->getJson($this->url('/overview'), $headers)->assertForbidden();
        $this->postJson($this->url('/actions/create-lead'), ['phone' => '9000088002'], $headers)->assertForbidden();
        $this->postJson($this->url('/actions/not-an-action'), [], $this->bearer($this->sales))->assertNotFound();
        $otherUnit = DB::table('erp_units')->insertGetId(['code' => 'API-BB', 'name' => 'Other', 'timezone' => 'Asia/Kolkata']);
        $this->getJson('/api/sales/units/'.$otherUnit.'/overview', $this->bearer($this->sales))->assertForbidden();
    }

    public function test_demos_teachers_transfers_and_members_endpoints_respond(): void
    {
        $headers = $this->bearer($this->sales);
        $this->getJson($this->url('/demos'), $headers)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->url('/demos?scope=all'), $headers)->assertOk();
        $this->getJson($this->url('/transfers'), $headers)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->url('/teachers'), $headers)->assertOk()->assertJsonStructure(['data', 'subjects', 'levels', 'categories']);
        $this->getJson($this->url('/members'), $headers)->assertOk()->assertJsonFragment(['id' => 41]);
    }
}
