<?php
use App\Http\Controllers\App\PrintController;
use App\Http\Middleware\TeamAuthorization;
use Illuminate\Support\Facades\Route;

// Printable staff documents (React app). The host page serves every /print URL;
// the documents load from the JSON reads below, which apply these checks:
// erp.receipt / erp.request.print need the money page, erp.invoice the accounts
// page, and the monthly statement keeps its own manager-only role check in the
// controller (the declared 'detail' check is only the first gate).
Route::get('/print/{path?}', [PrintController::class, 'host'])->where('path', '.*')->middleware('auth')->name('print.host');

Route::middleware(['auth', TeamAuthorization::class])->prefix('app-api/units/{unit}/print')->whereNumber('unit')->name('app.api.print.')->group(function () {
    Route::get('/receipt/{entry}', [PrintController::class, 'receipt'])->whereNumber('entry')->name('receipt')->defaults('erp_permission', ['page', 'money']);
    Route::get('/request/{id}', [PrintController::class, 'request'])->whereNumber('id')->name('request')->defaults('erp_permission', ['page', 'money']);
    Route::get('/invoice/{id}', [PrintController::class, 'invoice'])->whereNumber('id')->name('invoice')->defaults('erp_permission', ['page', 'accounts']);
    Route::get('/statement', [PrintController::class, 'statement'])->name('statement')->defaults('erp_permission', ['detail', 'all']);
});
