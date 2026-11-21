<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Http\Request;

/**
 * Host pages for sign-in, password reset, the two-factor challenge and account
 * security in the React app. The pages post to the Auth controllers' routes, which
 * answer JSON. The embedded session never holds a token, secret or code: the
 * reset token is already in the URL the visitor opened.
 */
class GuestController extends Controller
{
    /** Like LoginController: no settings query, so sign-in pages never depend on the database. */
    public function __construct()
    {
    }

    public function login(Request $request)
    {
        return $this->loginPage($request, null);
    }

    public function staffLogin(Request $request)
    {
        return $this->loginPage($request, 'staff');
    }

    public function superadminLogin(Request $request)
    {
        return $this->loginPage($request, 'superadmin');
    }

    public function passwordRequest(Request $request)
    {
        return $this->page($request, 'password-request', 'Reset password · ClapsLearn');
    }

    public function passwordReset(Request $request)
    {
        return $this->page($request, 'password-reset', 'Choose a new password · ClapsLearn')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function passwordConfirm(Request $request)
    {
        $intended = (string) $request->session()->get('url.intended', '/');
        return $this->page($request, 'password-confirm', 'Confirm password · ClapsLearn', [
            'intended' => $this->sameOrigin($request, $intended) ? $intended : '/',
        ]);
    }

    public function twoFactor(Request $request)
    {
        abort_unless($request->session()->has('two_factor_pending'), 403);
        return $this->page($request, 'two-factor', 'Verify sign-in · ClapsLearn');
    }

    public function security(Request $request)
    {
        (new SiteAccessPolicy())->assertSiteMember($request->user());
        return $this->page($request, 'security', 'Account security · ClapsLearn')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** Two-factor status for the signed-in user. Never the secret or the codes. */
    public function twoFactorStatus(Request $request)
    {
        $user = $request->user();
        (new SiteAccessPolicy())->assertSiteMember($user);
        $enabled = !empty($user->two_factor_secret);
        $codes = $enabled ? json_decode((string) ($user->two_factor_recovery_codes ?? '[]'), true) : [];
        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'twoFactorEnabled' => $enabled,
            'recoveryCodesLeft' => $enabled ? count(is_array($codes) ? $codes : []) : null,
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function loginPage(Request $request, ?string $portal)
    {
        return $this->page($request, 'login', match ($portal) {
            'superadmin' => 'Administrator sign-in · ClapsLearn',
            'staff' => 'Staff sign-in · ClapsLearn',
            default => 'Sign in · ClapsLearn',
        }, ['portal' => $portal]);
    }

    private function page(Request $request, string $screen, string $title, array $extra = [])
    {
        $status = $request->session()->get('status');
        return AppShellController::page([
            'kind' => 'guest',
            'screen' => $screen,
            'status' => is_string($status) ? $status : null,
        ] + $extra, $title);
    }

    private function sameOrigin(Request $request, string $url): bool
    {
        return str_starts_with($url, '/') && !str_starts_with($url, '//')
            || str_starts_with($url, $request->getSchemeAndHttpHost().'/');
    }
}
