<?php
use App\Http\Controllers\App\{AccountsApiController, MoneyApiController, TuitionApiController};
use Illuminate\Support\Facades\Route;

// Payments & dues: the classic "money" page.
Route::get('/money/summary', [MoneyApiController::class, 'summary'])->name('money.summary')->defaults('erp_permission', ['page', 'money']);
Route::get('/money/dues', [MoneyApiController::class, 'dues'])->name('money.dues')->defaults('erp_permission', ['page', 'money']);
Route::get('/money/students', [MoneyApiController::class, 'students'])->name('money.students')->defaults('erp_permission', ['page', 'money']);
Route::get('/money/ledger', [MoneyApiController::class, 'ledger'])->name('money.ledger')->defaults('erp_permission', ['page', 'money']);
Route::get('/money/requests', [MoneyApiController::class, 'requests'])->name('money.requests')->defaults('erp_permission', ['page', 'money']);
Route::get('/money/teachers', [MoneyApiController::class, 'teachers'])->name('money.teachers')->defaults('erp_permission', ['page', 'money']);

// Accounts & tax: the classic "accounts" page.
Route::get('/accounts/overview', [AccountsApiController::class, 'overview'])->name('accounts.overview')->defaults('erp_permission', ['page', 'accounts']);
Route::get('/accounts/chart', [AccountsApiController::class, 'chart'])->name('accounts.chart')->defaults('erp_permission', ['page', 'accounts']);
Route::get('/accounts/journals', [AccountsApiController::class, 'journals'])->name('accounts.journals')->defaults('erp_permission', ['page', 'accounts']);
Route::get('/accounts/ledger', [AccountsApiController::class, 'ledger'])->name('accounts.ledger')->defaults('erp_permission', ['page', 'accounts']);
Route::get('/accounts/reports', [AccountsApiController::class, 'reports'])->name('accounts.reports')->defaults('erp_permission', ['page', 'accounts']);
Route::get('/accounts/invoices', [AccountsApiController::class, 'invoices'])->name('accounts.invoices')->defaults('erp_permission', ['page', 'accounts']);
Route::get('/accounts/tax', [AccountsApiController::class, 'tax'])->name('accounts.tax')->defaults('erp_permission', ['page', 'accounts']);

// Class follow-up & HR (classic /erp/units/{unit}/tuition). "detail" narrows to managers,
// read-only and admins; the controller repeats the classic explicit role check
// (superadmin / admin / team_manager only), as routes/erp.php requires for private records.
Route::get('/tuition', [TuitionApiController::class, 'index'])->name('money.tuition')->defaults('erp_permission', ['detail', 'all']);
