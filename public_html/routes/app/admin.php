<?php
use App\Http\Controllers\App\AdminTeamAccessApiController;
use Illuminate\Support\Facades\Route;

// Team & access for one unit. Page "staff" is superadmin-only in TeamPermissions::allows.
Route::get('/admin/team', [AdminTeamAccessApiController::class, 'show'])->name('admin.team')->defaults('erp_permission', ['page', 'staff']);
