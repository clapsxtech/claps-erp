<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

/*
| Sales app API (sales.clapslearn.in). Reachable only through the Worker's
| SalesGateway service binding; see App\Http\Middleware\SalesGatewayOnly.
*/
Route::prefix('sales')->name('api.sales.')
    ->withoutMiddleware('throttle:api')
    ->middleware(\App\Http\Middleware\SalesGatewayOnly::class)
    ->group(function () {
        Route::post('/login', [\App\Http\Controllers\Api\SalesController::class, 'login'])->name('login');
        Route::middleware([\App\Http\Middleware\SalesApiAuth::class, 'throttle:sales-api'])->group(function () {
            Route::get('/me', [\App\Http\Controllers\Api\SalesController::class, 'me'])->name('me');
            Route::prefix('/units/{unit}')->whereNumber('unit')->group(function () {
                Route::get('/overview', [\App\Http\Controllers\Api\SalesController::class, 'overview'])->name('overview');
                Route::get('/leads', [\App\Http\Controllers\Api\SalesController::class, 'leads'])->name('leads');
                Route::get('/leads/{lead}', [\App\Http\Controllers\Api\SalesController::class, 'lead'])->whereNumber('lead')->name('lead');
                Route::get('/demos', [\App\Http\Controllers\Api\SalesController::class, 'demos'])->name('demos');
                Route::get('/reminders', [\App\Http\Controllers\Api\SalesController::class, 'reminders'])->name('reminders');
                Route::get('/transfers', [\App\Http\Controllers\Api\SalesController::class, 'transfers'])->name('transfers');
                Route::get('/teachers', [\App\Http\Controllers\Api\SalesController::class, 'teachers'])->name('teachers');
                Route::get('/members', [\App\Http\Controllers\Api\SalesController::class, 'members'])->name('members');
                Route::post('/actions/{action}', [\App\Http\Controllers\Api\SalesController::class, 'act'])->where('action', '[a-z-]+')->name('act');
            });
        });
    });
