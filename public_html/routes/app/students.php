<?php
use App\Http\Controllers\App\StudentsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/students', [StudentsApiController::class, 'index'])->name('students')->defaults('erp_permission', ['page', 'people']);
Route::get('/students/{student}', [StudentsApiController::class, 'show'])->whereNumber('student')->name('student')->defaults('erp_permission', ['detail', 'all']);
