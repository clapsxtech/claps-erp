<?php
use App\Http\Controllers\App\{AcademicsApiController, AttendanceApiController, MatchingApiController, TimetableApiController};
use Illuminate\Support\Facades\Route;

// Classes area: Timetable, Attendance, Teacher matching and Results. Writes reuse
// the classic POST routes (WorkspaceController::mutate and business-actions).
Route::get('/timetable', [TimetableApiController::class, 'week'])->name('classes.timetable')->defaults('erp_permission', ['page', 'timetable']);
Route::get('/timetable/plans', [TimetableApiController::class, 'plans'])->name('classes.timetable.plans')->defaults('erp_permission', ['page', 'timetable']);

Route::get('/attendance', [AttendanceApiController::class, 'index'])->name('classes.attendance')->defaults('erp_permission', ['page', 'attendance']);
Route::get('/attendance/queues/{queue}', [AttendanceApiController::class, 'queue'])->whereIn('queue', ['approval', 'import', 'missing'])->name('classes.attendance.queue')->defaults('erp_permission', ['page', 'attendance']);
Route::get('/attendance/reviews', [AttendanceApiController::class, 'reviews'])->name('classes.attendance.reviews')->defaults('erp_permission', ['page', 'attendance']);

Route::get('/matching', [MatchingApiController::class, 'index'])->name('classes.matching')->defaults('erp_permission', ['page', 'allocation']);
Route::get('/matching/teachers', [MatchingApiController::class, 'teachers'])->name('classes.matching.teachers')->defaults('erp_permission', ['page', 'allocation']);
Route::get('/matching/prepare/{teacher}', [MatchingApiController::class, 'prepare'])->whereNumber('teacher')->name('classes.matching.prepare')->defaults('erp_permission', ['action', 'allocation-qualify']);

Route::get('/academics', [AcademicsApiController::class, 'marks'])->name('classes.academics')->defaults('erp_permission', ['page', 'academics']);
Route::get('/academics/eligibility', [AcademicsApiController::class, 'eligibility'])->name('classes.academics.eligibility')->defaults('erp_permission', ['page', 'academics']);
