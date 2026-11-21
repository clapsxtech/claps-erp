<?php
// Sign-in and account-security screens of the React app. Only the GET pages are
// defined here; their URLs and route names are the ones redirects, the reset
// e-mail and Authenticate use.
// Every POST is in routes/web.php, on its Auth controller, with its throttles:
//   POST /login                     LoginController (ThrottlesLogins: 5 a minute per email+IP)
//   POST /password/email            ForgotPasswordController (broker throttle)
//   POST /password/reset            ResetPasswordController
//   POST /password/confirm          ConfirmPasswordController (auth)
//   POST /two-factor                TwoFactorController@verify (guest, throttle:6,1,two-factor-challenge)
//   POST /account/two-factor/{setup,enable,disable}  TwoFactorController (auth, throttle:6,1,two-factor-settings)
// Each of those answers JSON when the request expects JSON.
use App\Http\Controllers\App\GuestController;
use Illuminate\Support\Facades\Route;

// LoginController applies `guest` to its pages in its constructor; same here.
Route::middleware('guest')->group(function () {
    Route::get('/login', [GuestController::class, 'login'])->name('login');
    Route::get('/staff/login', [GuestController::class, 'staffLogin'])->name('staff.login');
    Route::get('/superadmin/login', [GuestController::class, 'superadminLogin'])->name('superadmin.login');
    Route::get('/backend', [GuestController::class, 'login']);
    Route::get('/two-factor', [GuestController::class, 'twoFactor'])->name('two-factor.challenge');
});

// The reset pages carry no `guest` middleware, so a signed-in person can still
// open a link from an e-mail.
Route::get('/password/reset', [GuestController::class, 'passwordRequest'])->name('password.request');
Route::get('/password/reset/{token}', [GuestController::class, 'passwordReset'])->name('password.reset');

Route::middleware('auth')->group(function () {
    Route::get('/password/confirm', [GuestController::class, 'passwordConfirm'])->name('password.confirm');
    Route::get('/account/security', [GuestController::class, 'security'])->name('account.security');
    // Status only. The secret and recovery codes are returned solely by the POSTs
    // that create them (/account/two-factor/setup and /enable), never by a GET.
    Route::get('/app-api/account/two-factor', [GuestController::class, 'twoFactorStatus'])->name('app.api.account.two-factor');
});
