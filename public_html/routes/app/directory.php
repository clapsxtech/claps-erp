<?php
use App\Http\Controllers\App\DirectoryApiController;
use Illuminate\Support\Facades\Route;

// Sales teacher directory under Admissions.
Route::get('/teacher-directory', [DirectoryApiController::class, 'index'])->name('people.teacher-directory')->defaults('erp_permission', ['page', 'admissions']);
