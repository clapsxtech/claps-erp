<?php
use App\Http\Controllers\App\OverviewApiController;
use Illuminate\Support\Facades\Route;

// Home and My tasks are for every unit member. allows() has no "member" kind, so they
// declare the lookup every role passes (subjects); the controller then gates each tile
// and the unit-wide task view with explicit allows() checks.
Route::get('/overview/home', [OverviewApiController::class, 'home'])->name('overview.home')->defaults('erp_permission', ['member', 'any']);
Route::get('/overview/tasks', [OverviewApiController::class, 'tasks'])->name('overview.tasks')->defaults('erp_permission', ['member', 'any']);
Route::get('/overview/insights', [OverviewApiController::class, 'insights'])->name('overview.insights')->defaults('erp_permission', ['page', 'insights']);
Route::get('/overview/exports', [OverviewApiController::class, 'exports'])->name('overview.exports')->defaults('erp_permission', ['page', 'migration']);
Route::get('/overview/exports/history', [OverviewApiController::class, 'history'])->name('overview.history')->defaults('erp_permission', ['page', 'migration']);
Route::get('/overview/sales-report', [OverviewApiController::class, 'salesReport'])->name('overview.sales-report')->defaults('erp_permission', ['page', 'admissions']);
