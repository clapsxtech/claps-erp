<?php
// People area: teachers, admissions CRM, follow-ups and teacher hiring. Writes use
// the classic action routes; see resources/app/README.md.
use App\Http\Controllers\App\{AdmissionsApiController, FollowupsApiController, RecruitmentApiController, TeachersApiController};
use Illuminate\Support\Facades\Route;

Route::get('/teachers', [TeachersApiController::class, 'index'])->name('people.teachers')->defaults('erp_permission', ['page', 'people']);
Route::get('/teachers/{teacher}', [TeachersApiController::class, 'show'])->whereNumber('teacher')->name('people.teacher')->defaults('erp_permission', ['page', 'people']);

Route::get('/admissions', [AdmissionsApiController::class, 'index'])->name('people.admissions')->defaults('erp_permission', ['page', 'admissions']);
Route::get('/admissions/demos', [AdmissionsApiController::class, 'demos'])->name('people.admissions.demos')->defaults('erp_permission', ['page', 'admissions']);
Route::get('/admissions/reminders', [AdmissionsApiController::class, 'reminders'])->name('people.admissions.reminders')->defaults('erp_permission', ['page', 'admissions']);
Route::get('/admissions/transfers', [AdmissionsApiController::class, 'transfers'])->name('people.admissions.transfers')->defaults('erp_permission', ['page', 'admissions']);
Route::get('/admissions/{lead}', [AdmissionsApiController::class, 'show'])->whereNumber('lead')->name('people.admissions.lead')->defaults('erp_permission', ['page', 'admissions']);

Route::get('/followups', [FollowupsApiController::class, 'index'])->name('people.followups')->defaults('erp_permission', ['page', 'followups']);

Route::get('/recruitment', [RecruitmentApiController::class, 'index'])->name('people.recruitment')->defaults('erp_permission', ['page', 'teacher-recruitment']);
Route::get('/recruitment/{candidate}', [RecruitmentApiController::class, 'show'])->whereNumber('candidate')->name('people.recruitment.candidate')->defaults('erp_permission', ['page', 'teacher-recruitment']);
