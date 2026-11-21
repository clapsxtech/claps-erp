<?php
namespace Tests\Feature;
use App\Modules\IdentityAccess\Services\TwoFactor;
use Illuminate\Support\Facades\Crypt;
use OTPHP\TOTP;
use Tests\TestCase;
class TwoFactorWorkflowTest extends TestCase
{
    public function test_enrollment_challenge_recovery_and_replay_protection(): void
    {
        $user=$this->createAdmin();$this->actingAs($user);
        $this->get(route('account.security'))->assertOk();
        $this->getJson(route('app.api.account.two-factor'))->assertOk()->assertJsonPath('twoFactorEnabled',false);
        $this->postJson(route('two-factor.setup'),['current_password'=>'wrong'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $setup=$this->postJson(route('two-factor.setup'),['current_password'=>'Local-Testing-Only-2026'])->assertOk();
        $secret=$setup->json('secret');
        $pending=session('two_factor_setup');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/',$secret,'Manual-entry secret should be 160 bits (32 characters).');
        $this->assertSame($secret,Crypt::decryptString($pending['secret']));
        $this->assertNotSame($secret,$pending['secret']);
        // The secret is handed out by the setup response only; no page or status read repeats it.
        $this->get(route('account.security'))->assertOk()->assertDontSee($secret);
        $this->getJson(route('app.api.account.two-factor'))->assertOk()->assertDontSee($secret);
        $code=TOTP::createFromSecret($secret)->at(now()->timestamp);
        $enabled=$this->postJson(route('two-factor.enable'),['current_password'=>'Local-Testing-Only-2026','code'=>$code])->assertOk()->assertJsonPath('twoFactorEnabled',true);
        $codes=$enabled->json('recoveryCodes');
        $this->assertCount(8,$codes);
        $this->assertNotSame($secret,$user->fresh()->two_factor_secret);
        $this->assertStringNotContainsString($codes[0],$user->fresh()->two_factor_recovery_codes);
        // Recovery codes are shown once (in the enable response) and never again.
        $this->assertNull(session('two_factor_recovery_display'));
        $this->get(route('account.security'))->assertOk()->assertDontSee($codes[0]);
        $this->actingAs($user->fresh()); // the test guard keeps the model it was given
        $this->getJson(route('app.api.account.two-factor'))->assertOk()->assertJsonPath('twoFactorEnabled',true)->assertJsonPath('recoveryCodesLeft',8)->assertDontSee($codes[0]);
        $this->post('/logout');
        $this->postJson('/login',['email'=>$user->email,'password'=>'Local-Testing-Only-2026','portal'=>'superadmin'])->assertOk()->assertJsonPath('redirect',route('two-factor.challenge'));
        $this->assertGuest();$this->get('/')->assertRedirect('/login');
        $this->postJson(route('two-factor.verify'),['code'=>$code])->assertUnprocessable(); // Enrollment step cannot be replayed.
        $this->postJson(route('two-factor.verify'),['code'=>$codes[0]])->assertOk()->assertJsonPath('redirect',url('/app'));$this->assertAuthenticatedAs($user);
        $this->assertFalse((new TwoFactor())->consume($user->id,$codes[0]));
        $this->postJson(route('two-factor.disable'),['current_password'=>'Local-Testing-Only-2026','code'=>$codes[1]])->assertOk()->assertJsonPath('twoFactorEnabled',false);
        $this->assertNull($user->fresh()->two_factor_secret);
    }
    public function test_password_change_or_expired_challenge_prevents_login(): void
    {
        $user=$this->createAdmin();$secret=TOTP::generate()->getSecret();
        $user->forceFill(['two_factor_secret'=>Crypt::encryptString($secret),'two_factor_recovery_codes'=>json_encode([hash('sha256','test-recovery-code')])])->save();
        $this->withSession(['two_factor_pending'=>['id'=>$user->id,'expires'=>time()-1,'password_hash'=>$user->password]])->postJson(route('two-factor.verify'),['code'=>'test-recovery-code'])->assertUnprocessable();
        $this->withSession(['two_factor_pending'=>['id'=>$user->id,'expires'=>time()+300,'password_hash'=>'old-hash']])->postJson(route('two-factor.verify'),['code'=>'test-recovery-code'])->assertUnprocessable();
        $this->assertGuest();
    }
}
