<?php
// Family portal (React). Loaded after routes/web.php, so these GETs replace the classic
// Blade pages at the same URLs and keep their route names. Sign-in, sign-out and disputes
// stay on the classic POST routes in web.php, which answer JSON when asked. No `auth`
// middleware: families sign in with the student ID and date of birth, and every action
// checks the parent_portal session through ParentPortalController (idle timeout, DOB fingerprint).
use App\Http\Controllers\App\ParentPortalAppController as Portal;
use Illuminate\Support\Facades\Route;

Route::prefix('parent')->name('parent.')->group(function () {
    Route::get('/login', [Portal::class, 'loginPage'])->name('login');
    Route::get('/statement', [Portal::class, 'statementPage'])->name('statement');
    Route::get('/documents/{type}/{id}', [Portal::class, 'documentPage'])->whereIn('type', ['receipt', 'request', 'invoice'])->whereNumber('id')->name('document');
    Route::get('/{section?}', [Portal::class, 'homePage'])->whereIn('section', ['overview', 'classes', 'results', 'fees'])->name('home');
});

// JSON reads for the signed-in family only (401 when the portal session is missing or idle).
Route::prefix('parent-api')->name('parent.api.')->group(function () {
    Route::get('/sections/{section}', [Portal::class, 'section'])->whereIn('section', ['overview', 'classes', 'results', 'fees'])->name('section');
    Route::get('/statement', [Portal::class, 'statement'])->name('statement');
    Route::get('/documents/{type}/{id}', [Portal::class, 'document'])->whereIn('type', ['receipt', 'request', 'invoice'])->whereNumber('id')->name('document');
});
