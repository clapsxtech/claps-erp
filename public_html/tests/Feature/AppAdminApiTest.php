<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\{DB, Hash};
use Tests\TestCase;

/** Admin JSON reads for the React app: superadmin only, explicit fields, no secrets. Synthetic data only. */
class AppAdminApiTest extends TestCase
{
    private const SECRET = 'SYNTHETIC-TOTP-SECRET-DO-NOT-LEAK';
    private const RECOVERY = 'synthetic-recovery-hash-do-not-leak';
    private const REMEMBER = 'synthetic-remember-token-do-not-leak';

    private int $unit;
    private int $other;
    private User $admin;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'ADM-A', 'name' => 'Admin Unit A', 'timezone' => 'UTC']);
        $this->other = DB::table('erp_units')->insertGetId(['code' => 'ADM-B', 'name' => 'Admin Unit B', 'timezone' => 'UTC', 'archived_at' => now()]);
        $this->manager = $this->createMember(['name' => 'Synthetic Manager']);
        DB::table('erp_memberships')->insert(['unit_id' => $this->unit, 'user_id' => $this->manager->id, 'role' => 'team_manager']);
        // A member whose account carries every kind of secret, to prove none of it is returned.
        User::where('id', $this->manager->id)->update(['two_factor_secret' => self::SECRET, 'two_factor_recovery_codes' => json_encode([self::RECOVERY]), 'remember_token' => self::REMEMBER]);
        $teacherUser = $this->createMember(['name' => 'Synthetic Teacher Login']);
        DB::table('erp_memberships')->insert(['unit_id' => $this->unit, 'user_id' => $teacherUser->id, 'role' => 'teacher']);
        $teacher = DB::table('erp_teachers')->insertGetId(['code' => 'ADM-T1', 'name' => 'Synthetic Teacher', 'status' => 'active', 'timezone' => 'UTC', 'user_id' => $teacherUser->id]);
        DB::table('erp_teacher_units')->insert(['teacher_id' => $teacher, 'unit_id' => $this->unit]);
        DB::table('erp_audit')->insert(['unit_id' => $this->unit, 'actor_id' => $this->admin->id, 'action' => 'team.saved', 'entity_type' => 'unit', 'entity_id' => $this->unit, 'payload' => '{"note":"SYNTHETIC-AUDIT-PAYLOAD"}', 'created_at' => now()]);
    }

    private function assertNoSecrets(string $body): void
    {
        foreach ([self::SECRET, self::RECOVERY, self::REMEMBER, 'SYNTHETIC-AUDIT-PAYLOAD', 'password', 'two_factor', 'remember_token', '$2y$'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_team_access_is_superadmin_only_and_lists_explicit_fields(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/app-api/units/'.$this->unit.'/admin/team')->assertOk();
        $json = $response->json();
        $this->assertSame(['team', 'members', 'teachers', 'accounts', 'roles'], array_keys($json));
        $this->assertSame(['userId', 'name', 'email', 'role', 'roleLabel', 'isActive', 'isSelf', 'updatedAt'], array_keys($json['members'][0]));
        $this->assertCount(2, $json['members']);
        $this->assertSame(['id', 'code', 'name', 'status', 'linkedUserId', 'linkedUserName'], array_keys($json['teachers'][0]));
        $this->assertSame('Synthetic Teacher Login', $json['teachers'][0]['linkedUserName']);
        $this->assertSame(['id', 'name', 'email'], array_keys($json['accounts'][0]));
        $this->assertContains('teacher', array_column($json['roles'], 'value'));
        $this->assertNoSecrets($response->getContent());

        $this->getJson('/app-api/units/'.$this->other.'/admin/team')->assertOk()->assertJsonPath('team.isArchived', true)->assertJsonPath('members', []);
        $this->actingAs($this->manager)->getJson('/app-api/units/'.$this->unit.'/admin/team')->assertForbidden();
    }

    public function test_team_access_requires_sign_in(): void
    {
        $this->getJson('/app-api/units/'.$this->unit.'/admin/team')->assertUnauthorized();
    }

    public function test_teams_and_accounts_are_superadmin_only_and_whitelisted(): void
    {
        $teams = $this->actingAs($this->admin)->getJson('/app-api/admin/teams')->assertOk();
        $this->assertSame(['id', 'code', 'name', 'timezone', 'phone', 'legalName', 'address', 'state', 'taxMode', 'taxId', 'invoicePrefix', 'isArchived', 'archivedAt', 'memberCount'], array_keys($teams->json('data.0')));
        $byCode = collect($teams->json('data'))->keyBy('code');
        $this->assertTrue($byCode['ADM-B']['isArchived']);
        $this->assertSame(2, $byCode['ADM-A']['memberCount']);
        $this->assertContains('Kerala', $teams->json('states'));

        $accounts = $this->getJson('/app-api/admin/accounts?q=Manager')->assertOk();
        $this->assertSame(1, $accounts->json('meta.total'));
        $row = $accounts->json('data.0');
        $this->assertSame(['id', 'name', 'email', 'isActive', 'isAdmin', 'isOwner', 'isSelf', 'memberships'], array_keys($row));
        $this->assertSame([['unitId' => $this->unit, 'unitName' => 'Admin Unit A', 'role' => 'team_manager', 'roleLabel' => 'Team manager']], $row['memberships']);
        $this->assertFalse($row['isAdmin']);
        $this->assertNoSecrets($accounts->getContent());
        $this->assertNoSecrets($this->getJson('/app-api/admin/accounts')->assertOk()->getContent());
        $this->getJson('/app-api/admin/accounts?per_page=7')->assertStatus(422);

        $this->actingAs($this->manager)->getJson('/app-api/admin/teams')->assertForbidden();
        $this->getJson('/app-api/admin/accounts')->assertForbidden();
    }

    public function test_operations_returns_health_and_audit_without_payloads(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/app-api/admin/operations')->assertOk();
        $this->assertContains($response->json('health.status'), ['ok', 'warning', 'error']);
        $this->assertArrayHasKey('database', $response->json('health.checks'));
        $this->assertSame(['id', 'createdAt', 'actorId', 'actorName', 'unitId', 'unitName', 'action', 'entityType', 'entityId'], array_keys($response->json('events.0')));
        $this->assertSame('QA Admin', $response->json('events.0.actorName'));
        $this->assertNoSecrets($response->getContent());
        $this->getJson('/app-api/admin/operations?q=nothing-matches')->assertOk()->assertJsonPath('meta.total', 0);

        $migration = $this->getJson('/app-api/admin/migration')->assertOk();
        $this->assertSame(['batches', 'historyRows'], array_keys($migration->json()));

        $this->actingAs($this->manager)->getJson('/app-api/admin/operations')->assertForbidden();
        $this->getJson('/app-api/admin/migration')->assertForbidden();
    }

    /** The React admin forms post JSON to the classic routes: success is a redirect, failure is JSON. */
    public function test_classic_admin_writes_answer_json_posts_as_the_app_expects(): void
    {
        $this->actingAs($this->admin)->postJson('/erp/administration/teams', ['code' => 'ADM_C', 'name' => 'Admin Unit C', 'timezone' => 'UTC', 'phone' => null])->assertRedirect();
        $this->assertDatabaseHas('erp_units', ['code' => 'ADM_C', 'phone' => null]);
        $this->postJson('/erp/administration/teams', ['code' => 'bad code', 'name' => 'X', 'timezone' => 'UTC'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/erp/administration/accounts/'.$this->manager->id, ['action' => 'revoke_sessions', 'current_password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/erp/units/'.$this->unit.'/business-actions/membership-revoke', ['user_id' => $this->admin->id])->assertStatus(422);
        $this->actingAs($this->manager)->postJson('/erp/administration/teams', ['code' => 'ADM_D', 'name' => 'Nope', 'timezone' => 'UTC'])->assertForbidden();
    }

    public function test_global_admin_reads_require_sign_in(): void
    {
        foreach (['teams', 'accounts', 'operations', 'migration'] as $path) {
            $this->getJson('/app-api/admin/'.$path)->assertUnauthorized();
        }
        $this->getJson('/app-api/account/security')->assertUnauthorized();
    }

    public function test_security_status_is_own_account_only_and_never_returns_secrets(): void
    {
        $session = ['two_factor_setup' => ['secret' => 'encrypted-pending', 'expires' => time() + 600], 'two_factor_recovery_display' => 'encrypted-codes'];
        $response = $this->actingAs($this->manager->fresh())->withSession($session)->getJson('/app-api/account/security')->assertOk();
        $this->assertSame(['name' => 'Synthetic Manager', 'email' => $this->manager->email, 'twoFactorEnabled' => true, 'classicUrl' => '/account/security'], $response->json());
        $this->assertNoSecrets($response->getContent());
        // The one-time recovery code display must still be there for the classic screen.
        $response->assertSessionHas('two_factor_recovery_display', 'encrypted-codes');
        $response->assertSessionHas('two_factor_setup');

        $this->actingAs($this->admin)->getJson('/app-api/account/security')->assertOk()->assertJsonPath('twoFactorEnabled', false);
    }
}
