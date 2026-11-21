<?php
use App\Http\Controllers\App\{DirectoryApiController, PlanningApiController};
use Illuminate\Support\Facades\Route;

// Sales teacher directory under Admissions (classic erp/crm/teachers.blade.php).
Route::get('/teacher-directory', [DirectoryApiController::class, 'index'])->name('people.teacher-directory')->defaults('erp_permission', ['page', 'admissions']);

// Timetable planning: the availability list the classic planning workspace showed.
// (Lives here only because this agent owns this file; it belongs with routes/app/classes.php.)
Route::get('/timetable/availability', [PlanningApiController::class, 'availability'])->name('classes.timetable.availability')->defaults('erp_permission', ['page', 'timetable']);
