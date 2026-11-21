<?php
// Public, no-login teacher forms served by the React app. The URLs and route
// names are the classic ones, so bookmarks, emails and route() calls keep
// working. Loaded after routes/web.php inside the `web` group, so these
// definitions replace the classic ones for the same method and URI; the web
// group keeps TeacherWorkspaceRedirect (linked teachers go to their workspace),
// SheetAuthority and CSRF. Every POST keeps the shared teacher-credentials
// throttle, and each still runs the classic controller, so DOB verification,
// submission keys and the integration outbox are unchanged.
use App\Http\Controllers\App\PublicFormsController;
use App\Http\Controllers\MarkListFrontentController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\TimeTableController;
use App\Http\Controllers\WorkHourController;
use Illuminate\Support\Facades\Route;

Route::get('/attendance', [PublicFormsController::class, 'attendance'])->name('attendance');
Route::get('/resultform', [PublicFormsController::class, 'result'])->name('resultform');
Route::get('/performance', [PublicFormsController::class, 'performance'])->name('performenceform');
Route::get('/timetable', [PublicFormsController::class, 'timetable'])->name('timetable');
Route::get('/timetableReport', [PublicFormsController::class, 'timetable'])->name('timetableReport');

Route::middleware('throttle:teacher-credentials')->group(function () {
    Route::post('/studentinfom', [WorkHourController::class, 'getStudentinfo'])->name('studentinfom');
    Route::post('/upload-workhour', [WorkHourController::class, 'store'])->name('upload-workhour');
    Route::post('/studentinforesult', [MarkListFrontentController::class, 'getStudentinfo'])->name('studentinforesult');
    Route::post('/upload-result', [MarkListFrontentController::class, 'store'])->name('upload-result');
    Route::post('/submit-application', [MarkListFrontentController::class, 'store'])->name('submit-application');
    Route::post('/performnce-upload', [PerformanceController::class, 'store'])->name('performnce-upload');
    Route::post('/get-teacher-dob', [TimeTableController::class, 'getTeacherDOB'])->name('get-teacher-dob');
});
