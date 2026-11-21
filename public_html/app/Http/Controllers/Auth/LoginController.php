<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\AuthenticatesUsers;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function showSuperadminLogin() { return view('auth.login', ['portal' => 'superadmin']); }
    public function showStaffLogin() { return view('auth.login', ['portal' => 'staff']); }

    protected function validateLogin(\Illuminate\Http\Request $request)
    {
        $request->validate(['email' => 'required|string', 'password' => 'required|string', 'portal' => 'nullable|in:superadmin,staff']);
    }

    protected function authenticated($request, $user)
    {
        $response = $this->authenticatedRedirect($request, $user);
        if (!$request->expectsJson()) return $response;
        // The React sign-in page loads the destination in full (the session and
        // CSRF token have rotated), so it gets the URL instead of a redirect.
        if ($response instanceof \Illuminate\Http\JsonResponse) return $response;
        $target = $response ? $response->getTargetUrl() : redirect()->intended($this->redirectPath())->getTargetUrl();
        return response()->json(['redirect' => $target]);
    }

    private function authenticatedRedirect($request, $user)
    {
        $policy = new \App\Modules\IdentityAccess\Services\SiteAccessPolicy();
        $superadmin = $policy->isAdministrator($user);
        if (!$policy->isSiteMember($user)
            || ($request->input('portal') === 'superadmin' && !$superadmin)
            || ($request->input('portal') === 'staff' && $superadmin)) {
            $this->guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $message = 'This account cannot access the selected login.';
            // The token just rotated; hand the page the new one so it can retry.
            if ($request->expectsJson()) return response()->json(['message' => $message, 'errors' => ['email' => [$message]], 'csrfToken' => csrf_token()], 422);
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => $message]);
        }
        if ($user->two_factor_secret) {
            $this->guard()->logout();
            $request->session()->forget('url.intended');
            $request->session()->put('two_factor_pending',['id'=>$user->id,'expires'=>time()+300,'password_hash'=>$user->password]);
            return redirect()->route('two-factor.challenge');
        }
        if ($superadmin) {
            $request->session()->forget('url.intended');
            return redirect()->route('erp.index');
        }
        if (\Illuminate\Support\Facades\DB::table('erp_memberships')->where('user_id',$user->id)->where('role','teacher')->exists()
            || \Illuminate\Support\Facades\DB::table('erp_teachers')->where('user_id',$user->id)->exists()) {
            $request->session()->forget('url.intended');
            return redirect()->route('erp.teacher.index');
        }
    }

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }
}
 
