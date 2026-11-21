<?php
namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\{Crypt, DB, Hash, Notification};
use OTPHP\TOTP;
use Tests\TestCase;

/** React sign-in, reset, two-factor and account-security screens over the classic auth routes. Synthetic data only. */
class AppGuestTest extends TestCase
{
    private const PASSWORD = 'Local-Testing-Only-2026';

    private function hostSession(string $html): array
    {
        $start = strpos($html, '<script type="application/json" id="erp-session">');
        $this->assertNotFalse($start);
        $start += strlen('<script type="application/json" id="erp-session">');
        return json_decode(html_entity_decode(substr($html, $start, strpos($html, '</script>', $start) - $start)), true);
    }

    public function test_sign_in_pages_serve_the_app_with_the_portal_and_keep_their_names(): void
    {
        foreach (['/login' => null, '/backend' => null, '/staff/login' => 'staff', '/superadmin/login' => 'superadmin'] as $url => $portal) {
            $response = $this->get($url)->assertOk()->assertSee('id="erp-root"', false)->assertHeader('Cache-Control');
            $session = $this->hostSession($response->getContent());
            $this->assertSame(['kind', 'screen', 'status', 'portal'], array_keys($session));
            $this->assertSame(['guest', 'login', $portal], [$session['kind'], $session['screen'], $session['portal']]);
        }
        $this->assertSame(url('/login'), route('login'));
        $this->assertSame(url('/password/reset/abc'), route('password.reset', ['token' => 'abc']));
        $this->assertSame(url('/two-factor'), route('two-factor.challenge'));
        $this->assertSame(url('/account/security'), route('account.security'));
        $this->get('/app/u/1/students')->assertRedirect('/login');

        $this->actingAs($this->createMember());
        $this->get('/login')->assertRedirect('/');
        $this->get('/staff/login')->assertRedirect('/');
    }

    public function test_json_login_returns_the_destination_for_each_kind_of_account(): void
    {
        $staff = $this->createMember(['email' => 'staff-json@example.invalid']);
        $this->get('/app/u/1/students')->assertRedirect('/login'); // Stores the intended URL.
        $this->postJson('/login', ['email' => $staff->email, 'password' => self::PASSWORD, 'remember' => true])
            ->assertOk()->assertExactJson(['redirect' => url('/app/u/1/students')]);
        $this->assertAuthenticatedAs($staff);
        $this->post('/logout');

        $admin = $this->createAdmin();
        $this->postJson('/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'portal' => 'superadmin'])
            ->assertOk()->assertExactJson(['redirect' => route('erp.index')]);
        $this->post('/logout');

        $teacher = $this->createMember(['email' => 'teacher-json@example.invalid']);
        $unit = DB::table('erp_units')->insertGetId(['code' => 'G-T', 'name' => 'Guest Unit', 'timezone' => 'UTC']);
        DB::table('erp_memberships')->insert(['unit_id' => $unit, 'user_id' => $teacher->id, 'role' => 'teacher']);
        $this->postJson('/login', ['email' => $teacher->email, 'password' => self::PASSWORD])
            ->assertOk()->assertExactJson(['redirect' => route('erp.teacher.index')]);
        $this->post('/logout');

        // Normal form posts keep redirecting.
        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect(route('erp.index'));
    }

    public function test_json_login_failures_use_the_classic_messages_and_throttle(): void
    {
        $user = $this->createMember(['email' => 'fail@example.invalid']);
        $this->postJson('/login', ['email' => '', 'password' => ''])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', trans('auth.failed'));
        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD, 'portal' => 'superadmin'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'This account cannot access the selected login.')
            ->assertJsonStructure(['csrfToken']);
        $this->assertGuest();

        // The mismatch above signed in and cleared the counter; five fresh failures lock out.
        for ($i = 0; $i < 5; $i++) $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(429)->assertJsonStructure(['errors' => ['email']]);
        $this->assertGuest();
    }

    public function test_inactive_accounts_cannot_sign_in(): void
    {
        $user = $this->createMember(['email' => 'inactive@example.invalid', 'status' => 0]);
        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'This account cannot access the selected login.');
        $this->assertGuest();
    }

    public function test_two_factor_accounts_go_through_the_challenge(): void
    {
        $user = $this->createMember(['email' => 'tfa@example.invalid']);
        $secret = TOTP::generate()->getSecret();
        $user->forceFill(['two_factor_secret' => Crypt::encryptString($secret), 'two_factor_recovery_codes' => json_encode([hash('sha256', 'synthetic-recovery')])])->save();

        $this->get('/two-factor')->assertForbidden();
        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()->assertExactJson(['redirect' => route('two-factor.challenge')]);
        $this->assertGuest();
        $page = $this->get('/two-factor')->assertOk()->assertSee('id="erp-root"', false);
        $this->assertStringNotContainsString($secret, $page->getContent());
        $this->assertSame('two-factor', $this->hostSession($page->getContent())['screen']);

        $this->postJson('/two-factor', ['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/two-factor', ['code' => TOTP::createFromSecret($secret)->now()])
            ->assertOk()->assertExactJson(['redirect' => route('erp.index')]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_two_factor_challenge_keeps_its_throttle(): void
    {
        $user = $this->createMember();
        $this->withSession(['two_factor_pending' => ['id' => $user->id, 'expires' => time() + 300, 'password_hash' => $user->password]]);
        for ($i = 0; $i < 6; $i++) $this->postJson('/two-factor', ['code' => '111111'])->assertStatus(422);
        $this->postJson('/two-factor', ['code' => '111111'])->assertStatus(429);
    }

    public function test_password_reset_request_and_reset_with_token(): void
    {
        Notification::fake();
        $user = $this->createMember(['email' => 'reset@example.invalid']);
        $this->get('/password/reset')->assertOk()->assertSee('id="erp-root"', false);
        $this->postJson('/password/email', ['email' => 'nobody@example.invalid'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/password/email', ['email' => $user->email])->assertOk()->assertJsonPath('message', trans('passwords.sent'));

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;
            return true;
        });
        $page = $this->get('/password/reset/'.$token.'?email='.urlencode($user->email))->assertOk()->assertSee('id="erp-root"', false);
        $this->assertSame('password-reset', $this->hostSession($page->getContent())['screen']);

        $this->postJson('/password/reset', ['token' => 'wrong-token', 'email' => $user->email, 'password' => 'Another-Synthetic-2026', 'password_confirmation' => 'Another-Synthetic-2026'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/password/reset', ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/password/reset', ['token' => $token, 'email' => $user->email, 'password' => 'Another-Synthetic-2026', 'password_confirmation' => 'Another-Synthetic-2026'])
            ->assertOk()->assertJsonPath('message', trans('passwords.reset'));
        $this->assertTrue(Hash::check('Another-Synthetic-2026', $user->fresh()->password));
        // A reset never signs in: that would skip the two-factor challenge.
        $this->assertGuest();
    }

    public function test_password_confirm_page_requires_sign_in(): void
    {
        $this->get('/password/confirm')->assertRedirect('/login');
        $this->actingAs($this->createMember())->get('/password/confirm')->assertOk()->assertSee('id="erp-root"', false);
        $this->postJson('/password/confirm', ['password' => 'wrong'])->assertStatus(422);
        $this->postJson('/password/confirm', ['password' => self::PASSWORD])->assertNoContent();
    }

    public function test_account_security_requires_sign_in_and_lists_only_status_fields(): void
    {
        $this->get('/account/security')->assertRedirect('/login');
        $this->getJson('/app-api/account/two-factor')->assertUnauthorized();

        $user = $this->createMember(['name' => 'Synthetic Security']);
        $this->actingAs($user);
        $this->get('/account/security')->assertOk()->assertSee('id="erp-root"', false)->assertHeader('Referrer-Policy', 'no-referrer');
        $json = $this->getJson('/app-api/account/two-factor')->assertOk()->json();
        $this->assertSame(['name', 'email', 'twoFactorEnabled', 'recoveryCodesLeft'], array_keys($json));
        $this->assertFalse($json['twoFactorEnabled']);
        $this->assertNull($json['recoveryCodesLeft']);

        $user->forceFill(['status' => 0])->save();
        $this->getJson('/app-api/account/two-factor')->assertForbidden();
    }

    public function test_secret_and_recovery_codes_appear_only_in_the_creating_posts(): void
    {
        $user = $this->createMember(['email' => 'secrets@example.invalid']);
        $this->actingAs($user);

        $this->postJson('/account/two-factor/setup', ['current_password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('current_password');
        $setup = $this->postJson('/account/two-factor/setup', ['current_password' => self::PASSWORD])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $secret = $setup->json('secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringContainsString('secret='.$secret, $setup->json('otpauthUri'));
        $this->assertSame(['secret', 'otpauthUri', 'issuer', 'account', 'expiresInSeconds'], array_keys($setup->json()));
        $this->assertNoSecretsInReads([$secret]);

        $this->postJson('/account/two-factor/enable', ['current_password' => self::PASSWORD, 'code' => '12345'])->assertStatus(422)->assertJsonValidationErrors('code');
        $enable = $this->postJson('/account/two-factor/enable', ['current_password' => self::PASSWORD, 'code' => TOTP::createFromSecret($secret)->now()])
            ->assertOk()->assertJsonPath('twoFactorEnabled', true);
        $codes = $enable->json('recoveryCodes');
        $this->assertCount(8, $codes);
        $this->assertNull(session('two_factor_recovery_display'));
        $this->assertNull(session('two_factor_setup'));
        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->actingAs($user = $user->fresh()); // A real request reloads the user; the test guard keeps the old instance.
        $this->assertNoSecretsInReads(array_merge([$secret], $codes));
        $this->getJson('/app-api/account/two-factor')->assertJsonPath('twoFactorEnabled', true)->assertJsonPath('recoveryCodesLeft', 8);

        \Illuminate\Support\Facades\Cache::flush(); // Six settings posts a minute; start a fresh window.
        $this->postJson('/account/two-factor/setup', ['current_password' => self::PASSWORD])->assertStatus(422);
        $this->postJson('/account/two-factor/disable', ['current_password' => self::PASSWORD, 'code' => 'not-a-code'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/account/two-factor/disable', ['current_password' => self::PASSWORD, 'code' => $codes[0]])
            ->assertOk()->assertJsonPath('twoFactorEnabled', false);
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_account_two_factor_posts_keep_their_throttle(): void
    {
        $this->actingAs($this->createMember());
        for ($i = 0; $i < 6; $i++) $this->postJson('/account/two-factor/setup', ['current_password' => 'wrong'])->assertStatus(422);
        $this->postJson('/account/two-factor/setup', ['current_password' => 'wrong'])->assertStatus(429);
    }

    private function assertNoSecretsInReads(array $secrets): void
    {
        foreach (['/account/security', '/app/account/security', '/app-api/account/two-factor', '/app-api/account/security'] as $url) {
            $body = $this->get($url)->assertOk()->getContent();
            foreach ($secrets as $secret) $this->assertStringNotContainsString($secret, $body, "$url must not expose a secret");
        }
    }
}
