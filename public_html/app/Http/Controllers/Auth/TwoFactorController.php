<?php
namespace App\Http\Controllers\Auth;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Erp\Services\Audit;
use App\Modules\IdentityAccess\Services\{SiteAccessPolicy,TwoFactor};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Crypt,DB,Hash};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
class TwoFactorController extends Controller
{
    public function verify(Request $request)
    {
        $v=$request->validate(['code'=>'required|string|max:100']);$pending=$request->session()->get('two_factor_pending');
        $user=$pending?User::find($pending['id']):null;
        if(!$user || $pending['expires']<time() || !hash_equals($pending['password_hash'],$user->password) || !(new TwoFactor())->consume($user->id,$v['code']))throw ValidationException::withMessages(['code'=>'Invalid or expired code. Try a new code or a recovery code.']);
        $request->session()->forget('two_factor_pending');Auth::login($user);$request->session()->regenerate();
        $teacher=!(new SiteAccessPolicy())->isAdministrator($user) && (DB::table('erp_memberships')->where('user_id',$user->id)->where('role','teacher')->exists() || DB::table('erp_teachers')->where('user_id',$user->id)->exists());
        $target=url($teacher?'/teacher':'/app');
        if($request->expectsJson())return response()->json(['redirect'=>$target]);
        return redirect($target);
    }
    public function setup(Request $request)
    {
        $this->password($request);abort_if($request->user()->two_factor_secret,422,'Two-factor authentication is already enabled.');
        $otp=TOTP::createFromSecret(rtrim(Base32::encodeUpper(random_bytes(20)),'='));
        $request->session()->put('two_factor_setup',['secret'=>Crypt::encryptString($otp->getSecret()),'expires'=>time()+600]);
        // JSON: the secret is returned once, in this response only; the React page never reads it back.
        if($request->expectsJson()){
            $otp->setLabel((string)$request->user()->email);$otp->setIssuer('ClapsLearn');
            return response()->json(['secret'=>$otp->getSecret(),'otpauthUri'=>$otp->getProvisioningUri(),'issuer'=>'ClapsLearn','account'=>$request->user()->email,'expiresInSeconds'=>600])->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer');
        }
        return redirect()->route('account.security');
    }
    public function enable(Request $request)
    {
        $this->password($request);$v=$request->validate(['code'=>'required|digits:6']);$pending=$request->session()->get('two_factor_setup');
        if(!$pending || $pending['expires']<time())throw ValidationException::withMessages(['code'=>'Start setup again; this setup has expired.']);
        $secret=Crypt::decryptString($pending['secret']);$otp=TOTP::createFromSecret($secret);
        if(!$otp->verify($v['code'],now()->timestamp,0))throw ValidationException::withMessages(['code'=>'Enter the current code from your authenticator.']);
        $codes=array_map(fn()=>bin2hex(random_bytes(12)),range(1,8));
        DB::transaction(function()use($request,$pending,$codes){
            $user=User::where('id',$request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($user->two_factor_secret,422,'Two-factor authentication is already enabled.');
            $user->two_factor_secret=$pending['secret'];$user->two_factor_recovery_codes=json_encode(array_map(fn($code)=>hash('sha256',$code),$codes));$user->two_factor_last_step=intdiv(now()->timestamp,30);$user->remember_token=Str::random(60);$user->save();
            DB::table('sessions')->where('user_id',$user->id)->where('id','!=',$request->session()->getId())->delete();
            Audit::record(null,$user->id,'account.two_factor_enabled','user',$user->id,[]);
        });
        $request->session()->forget('two_factor_setup');
        // JSON: the codes are returned once, in this response only, and are not kept in the session.
        if($request->expectsJson())return response()->json(['twoFactorEnabled'=>true,'recoveryCodes'=>$codes,'message'=>'Two-factor authentication enabled. Save your recovery codes now.'])->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer');
        $request->session()->put('two_factor_recovery_display',Crypt::encryptString(json_encode($codes)));
        return redirect()->route('account.security')->with('status','Two-factor authentication enabled. Save your recovery codes now.');
    }
    public function disable(Request $request)
    {
        $this->password($request);$request->validate(['code'=>'required|string|max:100']);
        DB::transaction(function()use($request){
            if(!(new TwoFactor())->consume($request->user()->id,$request->code))throw ValidationException::withMessages(['code'=>'Enter a fresh authenticator or recovery code.']);
            User::where('id',$request->user()->id)->update(['two_factor_secret'=>null,'two_factor_recovery_codes'=>null,'two_factor_last_step'=>null,'remember_token'=>Str::random(60)]);
            DB::table('sessions')->where('user_id',$request->user()->id)->where('id','!=',$request->session()->getId())->delete();
            Audit::record(null,$request->user()->id,'account.two_factor_disabled','user',$request->user()->id,[]);
        });
        if($request->expectsJson())return response()->json(['twoFactorEnabled'=>false,'message'=>'Two-factor authentication disabled.']);
        return redirect()->route('account.security')->with('status','Two-factor authentication disabled.');
    }
    private function password(Request $request): void
    {
        $request->validate(['current_password'=>'required|string']);
        if(!Hash::check($request->current_password,$request->user()->password))throw ValidationException::withMessages(['current_password'=>'Your current password is incorrect.']);
    }
}
