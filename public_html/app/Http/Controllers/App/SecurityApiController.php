<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Http\Request;

/**
 * Read-only status for "Account security". Enrolment, recovery codes and disabling stay
 * on the classic server-rendered /account/security screen: this endpoint never returns
 * the secret or codes and never touches the two_factor_setup / two_factor_recovery_display
 * session keys (the classic screen pulls the one-time codes from there).
 */
class SecurityApiController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        (new SiteAccessPolicy())->assertSiteMember($user);
        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'twoFactorEnabled' => !empty($user->two_factor_secret),
            'classicUrl' => '/account/security',
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
