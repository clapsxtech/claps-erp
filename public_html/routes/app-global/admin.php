<?php
// Global superadmin JSON reads for the React app, under /app-api/admin (middleware auth + admin,
// route names app.api.admin.*). Required from routes/erp.php; not a unit-scoped route file.
use App\Http\Controllers\App\{AdminOperationsApiController, AdminTeamsApiController};
use Illuminate\Support\Facades\Route;

Route::get('/teams', [AdminTeamsApiController::class, 'teams'])->name('teams');
Route::get('/accounts', [AdminTeamsApiController::class, 'accounts'])->name('accounts');
Route::get('/accounts/{id}/profile', [AdminTeamsApiController::class, 'profile'])->whereNumber('id')->name('accounts.profile');
Route::get('/operations', [AdminOperationsApiController::class, 'operations'])->name('operations');
Route::get('/migration', [AdminOperationsApiController::class, 'migration'])->name('migration');
