<?php
// Signed-in user's own account reads, under /app-api/account (middleware auth, names app.api.account.*).
use App\Http\Controllers\App\SecurityApiController;
use Illuminate\Support\Facades\Route;

Route::get('/security', [SecurityApiController::class, 'show'])->name('security');
