<?php
// Records & administration (React /app/records): read-only JSON and CSV for the classic
// /admin record screens. Superadmin only, the same 'admin' middleware the classic group uses.
use App\Http\Controllers\App\RecordsApiController;
use App\Http\Controllers\App\RecordsDatasets;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('app-api/records')->name('app.api.records.')->group(function () {
    $sets = implode('|', array_map(fn ($slug) => preg_quote($slug, '/'), RecordsDatasets::tableSlugs()));
    Route::get('/', [RecordsApiController::class, 'overview'])->name('overview');
    Route::get('/settings', [RecordsApiController::class, 'settings'])->name('settings');
    Route::get('/{set}', [RecordsApiController::class, 'index'])->where('set', $sets)->name('index');
    Route::get('/{set}/export', [RecordsApiController::class, 'export'])->where('set', $sets)->name('export');
});
