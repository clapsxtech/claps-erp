<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\People\Services\StaffProvisioner;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staff account rules. Accounts are created through POST /erp/administration/onboard (Teams & accounts),
 * listed through GET /app-api/admin/accounts and changed through POST /erp/administration/accounts/{id}.
 * No HTTP route edits a staff profile any more (the old /admin/users edit screen was removed), so the
 * profile-edit rules of StaffProvisioner::update() are exercised at service level here.
 */
class StaffProvisioningTest extends TestCase
{
    private ?int $team = null;

    private function team(): int
    {
        return $this->team ??= DB::table('erp_units')->insertGetId(['code' => 'STAFF', 'name' => 'Staff team', 'timezone' => 'UTC']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Staff', 'email' => 'new-staff@example.invalid',
            'phone' => '9123456789', 'gender' => 'Female',
            'password' => 'Staff-Testing-Only-2026', 'confirm_password' => 'Staff-Testing-Only-2026',
        ], $overrides);
    }

    /** Onboarding payload: the staff profile plus the team assignment the Teams & accounts form sends. */
    private function onboard(array $overrides = []): array
    {
        return array_merge($this->payload(), ['team_ids' => [$this->team()], 'initial_role' => 'read_only'], $overrides);
    }

    /** StaffProvisioner::update() has no HTTP caller; returns the validation errors (or [] on success). */
    private function updateProfile(User $actor, $id, array $input): array
    {
        try {
            app(StaffProvisioner::class)->update($actor, $id, $input);
            return [];
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    }

    public function test_creation_works_against_the_bundled_schema_and_current_form_fields(): void
    {
        $this->actingAs($this->createAdmin())->postJson(route('erp.onboard'), $this->onboard([
            'dob' => '1990-01-02', 'position' => 'Teacher', 'language' => 'English',
            'custom_fields' => 'Test metadata', 'is_commission_agent' => '1',
            'commission_type' => 'sale_amount', 'blood_group' => 'O+',
            'alternate_number' => '9234567890', 'emergency_contact_number' => '9345678901',
            'service_expiry' => '2030-12-31', 'permanent_address' => '123 Test Road',
            'current_address' => '456 Test Road',
            'bank_details' => ['account_holder_name' => 'Test Staff', 'account_number' => 'TEST-ONLY',
                'bank_name' => 'Test Bank', 'bank_code' => 'TEST', 'branch' => 'Test Branch', 'tax_payer_id' => 'TEST-ID'],
        ]))->assertOk()->assertJsonStructure(['message']);

        $user = User::where('email', 'new-staff@example.invalid')->firstOrFail();
        $this->assertSame('FEMALE', $user->gender);
        $this->assertSame(1, (int) $user->business_id);
        $this->assertSame('user', $user->user_type);
        $this->assertSame('Teacher', $user->position);
        $this->assertSame('1990-01-02', $user->dob);
        $this->assertSame('English', $user->language);
        $this->assertSame('2030-12-31', $user->service_expiry);
        $this->assertSame('Test metadata', $user->custom_fields);
        $this->assertSame('sale_amount', $user->commission_type);
        $this->assertSame('O+', $user->blood_group);
        $this->assertSame('9234567890', $user->alternate_number);
        $this->assertSame('9345678901', $user->emergency_contact_number);
        $this->assertSame('123 Test Road', json_decode($user->address, true)['permanent_address']);
        $this->assertSame('TEST-ONLY', json_decode($user->bank_details, true)['account_number']);
        $this->assertTrue(Hash::check('Staff-Testing-Only-2026', $user->password));
        $this->assertFalse(Schema::hasColumn('users', 'employee_code'));
        $this->assertCount(0, $user->roles);
        $this->assertDatabaseHas('erp_memberships', ['user_id' => $user->id, 'unit_id' => $this->team(), 'role' => 'read_only']);
    }

    public function test_create_requires_a_nonempty_confirmed_strong_password(): void
    {
        $this->actingAs($this->createAdmin());
        foreach ([
            ['password' => null, 'confirm_password' => null],
            ['password' => 'short', 'confirm_password' => 'short'],
            ['password' => 'Staff-Testing-Only-2026', 'confirm_password' => null],
            ['password' => 'Staff-Testing-Only-2026', 'confirm_password' => 'Different-Password'],
            ['password' => str_repeat('é', 40), 'confirm_password' => str_repeat('é', 40)],
        ] as $password) {
            $this->postJson(route('erp.onboard'), $this->onboard($password))
                ->assertStatus(422)->assertJsonValidationErrors('password');
        }
        $this->assertSame(1, User::count());
        $this->assertSame(0, DB::table('erp_memberships')->count());
    }

    public function test_legacy_gender_values_are_normalized_to_the_enum(): void
    {
        $this->actingAs($this->createAdmin())->postJson(route('erp.onboard'), $this->onboard(['gender' => 'Others']))
            ->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'new-staff@example.invalid', 'gender' => 'OTHER']);
    }

    public function test_all_optional_profile_inputs_are_validated_without_writing(): void
    {
        $this->actingAs($this->createAdmin());
        $invalid = [
            'dob' => 'not-a-date', 'service_expiry' => 'not-a-date', 'gender' => 'INVALID',
            'position' => ['invalid'], 'language' => ['invalid'], 'custom_fields' => ['invalid'],
            'is_commission_agent' => 'yes', 'commission_type' => 'invalid', 'blood_group' => 'invalid',
            'alternate_number' => '123', 'emergency_contact_number' => '123',
            'permanent_address' => ['invalid'], 'current_address' => ['invalid'],
            'bank_details' => ['account_number' => ['invalid']],
        ];
        foreach ($invalid as $field => $value) {
            $this->postJson(route('erp.onboard'), $this->onboard([$field => $value]))
                ->assertStatus(422)->assertJsonValidationErrors($field === 'bank_details' ? 'bank_details.account_number' : $field);
        }
        $this->postJson(route('erp.onboard'), $this->onboard([
            'permanent_address' => str_repeat('a', 150), 'current_address' => str_repeat('b', 150),
        ]))->assertStatus(422)->assertJsonValidationErrors('permanent_address');
        $this->assertSame(1, User::count());
        $this->assertSame(0, DB::table('erp_memberships')->count());
    }

    public function test_privilege_and_organization_fields_are_rejected(): void
    {
        $this->actingAs($this->createAdmin());
        foreach (['business_id' => 2, 'user_type' => 'admin', 'role' => 'Admin', 'roles' => ['Admin'],
            'permissions' => ['administration'], 'status' => 0, 'employee_code' => '1', 'id' => 1] as $field => $value) {
            $this->postJson(route('erp.onboard'), $this->onboard([$field => $value]))
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->assertSame(1, User::count());
        $this->assertSame(0, DB::table('erp_memberships')->count());
    }

    /** Service level: no route edits a staff profile (see the class comment). */
    public function test_edits_preserve_privileges_business_password_and_absent_optional_metadata(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createMember(['user_type' => 'admin', 'position' => 'Existing Position', 'bank_details' => '{"account_number":"TEST-EXISTING"}']);
        $role = Role::create(['name' => 'Existing Role', 'guard_name' => 'web']);
        $user->assignRole($role);
        $hash = $user->password;
        $this->assertSame([], $this->updateProfile($admin, $user->id, $this->payload([
            'email' => $user->email, 'phone' => $user->phone, 'password' => null, 'confirm_password' => null,
        ])));
        $user->refresh();
        $this->assertSame('admin', $user->user_type);
        $this->assertSame(1, (int) $user->business_id);
        $this->assertSame($hash, $user->password);
        $this->assertSame('Existing Position', $user->position);
        $this->assertSame('{"account_number":"TEST-EXISTING"}', $user->bank_details);
        $this->assertTrue($user->hasRole('Existing Role'));
    }

    /** Service level: no route edits a staff profile (see the class comment). */
    public function test_privilege_fields_are_also_rejected_on_updates_without_mutation(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createMember(['user_type' => 'admin']);
        $before = $user->fresh()->getAttributes();
        $errors = $this->updateProfile($admin, $user->id, $this->payload([
            'business_id' => 2, 'user_type' => 'user', 'roles' => ['Admin'], 'permissions' => ['administration'],
        ]));
        foreach (['business_id', 'user_type', 'roles', 'permissions'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    /**
     * Service level for profile edits; the account screen's password reset (the only HTTP way to change
     * another account's password now) is checked for the same confirmation rule.
     */
    public function test_edits_can_change_password_only_with_matching_confirmation(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createMember();
        $hash = $user->password;
        $this->assertArrayHasKey('password', $this->updateProfile($admin, $user->id, $this->payload(['confirm_password' => 'Wrong-Confirmation'])));
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame([], $this->updateProfile($admin, $user->id, $this->payload()));
        $this->assertTrue(Hash::check('Staff-Testing-Only-2026', $user->fresh()->password));

        $this->actingAs($admin)->postJson(route('erp.accounts.update', $user->id), [
            'action' => 'reset_password', 'current_password' => 'Local-Testing-Only-2026',
            'password' => 'Another-Strong-Password', 'password_confirmation' => 'Wrong-Confirmation',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('Staff-Testing-Only-2026', $user->fresh()->password));
        $this->postJson(route('erp.accounts.update', $user->id), [
            'action' => 'reset_password', 'current_password' => 'Local-Testing-Only-2026',
            'password' => 'Another-Strong-Password', 'password_confirmation' => 'Another-Strong-Password',
        ])->assertOk();
        $this->assertTrue(Hash::check('Another-Strong-Password', $user->fresh()->password));
    }

    public function test_cross_organization_staff_are_never_listed_read_or_edited(): void
    {
        $admin = $this->createAdmin();
        $foreign = $this->createMember(['business_id' => 2, 'name' => 'Foreign Staff']);
        $before = $foreign->fresh()->getAttributes();
        $this->actingAs($admin)->getJson('/app-api/admin/accounts')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertDontSee('Foreign Staff');
        $this->getJson('/app-api/admin/accounts?q=Foreign')->assertOk()->assertJsonCount(0, 'data');
        // The read-only records list of user accounts uses the same StaffProvisioner::staffQuery boundary.
        $this->getJson('/app-api/records/users')->assertOk()->assertSee('QA Admin')->assertDontSee('Foreign Staff');
        foreach (['suspend', 'activate', 'revoke_sessions', 'promote', 'demote'] as $action) {
            $this->postJson(route('erp.accounts.update', $foreign->id), ['action' => $action, 'current_password' => 'Local-Testing-Only-2026'])->assertNotFound();
        }
        $this->postJson(route('erp.accounts.update', $foreign->id), ['action' => 'reset_password', 'current_password' => 'Local-Testing-Only-2026',
            'password' => 'Foreign-Strong-Password', 'password_confirmation' => 'Foreign-Strong-Password'])->assertNotFound();
        try {
            app(StaffProvisioner::class)->update($admin, $foreign->id, $this->payload());
            $this->fail('A foreign account must not be editable.');
        } catch (ModelNotFoundException $expected) {
        }
        $this->assertSame($before, $foreign->fresh()->getAttributes());
    }

    public function test_nonadministrators_and_foreign_administrators_cannot_provision_staff(): void
    {
        $member = $this->createMember();
        $this->actingAs($member)->postJson(route('erp.onboard'), $this->onboard())->assertForbidden();
        $foreignAdmin = $this->createMember(['business_id' => 2, 'user_type' => 'admin']);
        $this->actingAs($foreignAdmin)->postJson(route('erp.onboard'), $this->onboard())->assertForbidden();
        $this->actingAs($foreignAdmin)->getJson('/app-api/admin/accounts')->assertForbidden();
        $this->assertSame(2, User::count());
        $this->assertSame(0, DB::table('erp_memberships')->count());
    }

    /** Accounts with null legacy metadata still list and still sign in to the staff app. */
    public function test_accounts_with_null_legacy_metadata_are_listed_and_open_the_app(): void
    {
        $admin = $this->createAdmin(['address' => null, 'bank_details' => null, 'gender' => 'OTHER']);
        $this->actingAs($admin)->getJson('/app-api/admin/accounts')->assertOk()->assertJsonPath('data.0.name', 'QA Admin');
        $this->get('/app')->assertOk();
        $this->assertSame([], $this->updateProfile($admin, $admin->id, $this->payload(['email' => $admin->email, 'phone' => $admin->phone, 'gender' => 'OTHER', 'password' => null, 'confirm_password' => null])));
        $this->assertSame('OTHER', $admin->fresh()->gender);
    }

    public function test_invalid_files_are_rejected(): void
    {
        $this->actingAs($this->createAdmin())->postJson(route('erp.onboard'), $this->onboard([
            'profile_photo' => UploadedFile::fake()->createWithContent('profile.php', '<?php echo "bad";'),
        ]))->assertStatus(422)->assertJsonValidationErrors('profile_photo');
        $this->assertSame(1, User::count());
    }

    /** Service level: no route replaces a staff photo (see the class comment). */
    public function test_photo_replacement_cannot_delete_an_arbitrary_legacy_path(): void
    {
        $directory = storage_path('framework/testing/staff-public-'.uniqid());
        File::ensureDirectoryExists($directory);
        $this->app->usePublicPath($directory);
        $sentinel = $directory.'/do-not-delete.txt';
        File::put($sentinel, 'Keep this file.');
        try {
            $admin = $this->createAdmin();
            $user = $this->createMember(['profile_photo' => '/do-not-delete.txt']);
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1uoAAAAASUVORK5CYII=');
            $this->assertSame([], $this->updateProfile($admin, $user->id, $this->payload([
                'profile_photo' => UploadedFile::fake()->createWithContent('photo.png', $png),
            ])));
            $this->assertFileExists($sentinel);
            $this->assertMatchesRegularExpression('#^/uploads/profile_photo/[a-f0-9-]{36}\.png$#', $user->fresh()->profile_photo);
            $this->assertFileExists($directory.$user->fresh()->profile_photo);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_onboarding_with_a_photo_stores_it_under_a_generated_name(): void
    {
        $directory = storage_path('framework/testing/staff-public-'.uniqid());
        File::ensureDirectoryExists($directory);
        $this->app->usePublicPath($directory);
        try {
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1uoAAAAASUVORK5CYII=');
            $this->actingAs($this->createAdmin())->postJson(route('erp.onboard'), $this->onboard([
                'profile_photo' => UploadedFile::fake()->createWithContent('photo.png', $png),
            ]))->assertOk();
            $photo = User::where('email', 'new-staff@example.invalid')->value('profile_photo');
            $this->assertMatchesRegularExpression('#^/uploads/profile_photo/[a-f0-9-]{36}\.png$#', $photo);
            $this->assertFileExists($directory.$photo);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_failed_saves_remove_only_the_new_upload_and_leave_no_partial_account(): void
    {
        $directory = storage_path('framework/testing/staff-public-'.uniqid());
        File::ensureDirectoryExists($directory);
        $this->app->usePublicPath($directory);
        $admin = $this->createAdmin();
        $team = $this->team();
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(new \Illuminate\Events\Dispatcher($this->app));
        User::saving(function () { throw new \RuntimeException('Simulated database failure.'); });
        try {
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1uoAAAAASUVORK5CYII=');
            $this->actingAs($admin)->postJson(route('erp.onboard'), $this->onboard([
                'profile_photo' => UploadedFile::fake()->createWithContent('photo.png', $png),
            ]))->assertStatus(500)->assertDontSee('Simulated database failure.');
            $this->assertSame(1, User::count());
            $this->assertSame(0, DB::table('erp_memberships')->where('unit_id', $team)->count());
            $this->assertCount(0, File::allFiles($directory));
        } finally {
            User::setEventDispatcher($dispatcher);
            File::deleteDirectory($directory);
        }
    }

    public function test_duplicates_and_deleted_targets_cannot_be_used_for_provisioning(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createMember();
        $this->actingAs($admin)->postJson(route('erp.onboard'), $this->onboard(['email' => $user->email, 'phone' => $user->phone]))
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);
        $deleted = $this->createMember(['deleted_at' => now(), 'name' => 'Deleted Staff']);
        $before = $deleted->fresh()->getAttributes();
        $this->getJson('/app-api/admin/accounts')->assertOk()->assertDontSee('Deleted Staff');
        $this->postJson(route('erp.accounts.update', $deleted->id), ['action' => 'activate', 'current_password' => 'Local-Testing-Only-2026'])->assertNotFound();
        try {
            app(StaffProvisioner::class)->update($admin, $deleted->id, $this->payload());
            $this->fail('A deleted account must not be editable.');
        } catch (ModelNotFoundException $expected) {
        }
        $this->assertSame($before, $deleted->fresh()->getAttributes());
    }

    /** Database exceptions can carry password hashes and bank details: only a fixed message is logged. */
    public function test_persistence_failures_return_generic_errors_without_partial_accounts_or_logged_payloads(): void
    {
        $admin = $this->createAdmin();
        $service = Mockery::mock(StaffProvisioner::class)->makePartial();
        $service->shouldReceive('create')->once()->andThrow(new \RuntimeException('Sensitive request payload must not be logged.'));
        $this->app->instance(StaffProvisioner::class, $service);
        \Illuminate\Support\Facades\Log::spy();
        $this->actingAs($admin)->postJson(route('erp.onboard'), $this->onboard())
            ->assertStatus(500)->assertExactJson(['message' => 'The change could not be saved. Nothing was changed.']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once()->with('Unable to create a staff profile.');
        $this->assertSame(1, User::count());
        $this->assertSame(0, DB::table('erp_memberships')->count());
    }

    public function test_additive_migration_can_reapply_and_rollback_without_removing_preexisting_columns(): void
    {
        require_once database_path('migrations/2026_10_23_110000_add_staff_profile_columns_to_users.php');
        $user = $this->createAdmin(['position' => 'Keep existing data']);
        $migration = new \AddStaffProfileColumnsToUsers();
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasColumns('users', ['position', 'bank_details', 'user_type']));
        $this->assertSame('Keep existing data', $user->fresh()->position);
    }
}
