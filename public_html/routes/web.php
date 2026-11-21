<?php

use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\ParentPortalController;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| Server routes that are not screens. Every page is served by the React app;
| see routes/erp.php (staff app and its JSON) and routes/portals/*.php (sign-in,
| teacher, parent, public forms, print and records).
*/

Route::get('/ready', function () {
    try {
        DB::connection()->select('SELECT 1');
        return response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store');
    } catch (\Throwable) {
        return response()->json(['status' => 'unavailable'], 503)->header('Cache-Control', 'no-store');
    }
})->withoutMiddleware('web');
Route::get('/health', fn () => response()->json(['status' => 'ok']))->withoutMiddleware('web');

// Signed-in staff open the staff app; linked teachers open their portal.
Route::get('/', function (Request $request) {
    $user = $request->user();
    $teacher = !(new SiteAccessPolicy())->isAdministrator($user)
        && (DB::table('erp_memberships')->where('user_id', $user->id)->where('role', 'teacher')->exists()
            || DB::table('erp_teachers')->where('user_id', $user->id)->exists());
    return redirect($teacher ? '/teacher' : '/app');
})->middleware('auth')->name('home');

// Sign-in, password reset and two-factor. The pages are in routes/portals/guest.php.
Route::post('/login', [LoginController::class, 'login'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
Route::post('/password/email', [ForgotPasswordController::class, 'sendResetLinkEmail'])->middleware('guest')->name('password.email');
Route::post('/password/reset', [ResetPasswordController::class, 'reset'])->middleware('guest')->name('password.update');
Route::post('/password/confirm', [ConfirmPasswordController::class, 'confirm'])->middleware('auth');
Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware(['guest', 'throttle:6,1,two-factor-challenge'])->name('two-factor.verify');
Route::middleware('auth')->group(function () {
    foreach (['setup', 'enable', 'disable'] as $action) {
        Route::post('/account/two-factor/'.$action, [TwoFactorController::class, $action])->middleware('throttle:6,1,two-factor-settings')->name('two-factor.'.$action);
    }
});

// Family portal writes: student ID + date of birth, lockouts per student ID. Pages are in routes/portals/parent.php.
Route::prefix('parent')->name('parent.')->group(function () {
    Route::post('/login', [ParentPortalController::class, 'login'])->name('login.submit');
    Route::post('/logout', [ParentPortalController::class, 'logout'])->name('logout');
    Route::post('/disputes', [ParentPortalController::class, 'dispute'])->name('dispute');
});
