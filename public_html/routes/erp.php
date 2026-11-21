<?php

use App\Http\Controllers\App\AppShellController;
use App\Http\Controllers\Erp\AcademicsController;
use App\Http\Controllers\Erp\AdministrationController;
use App\Http\Controllers\Erp\BusinessController;
use App\Http\Controllers\Erp\MigrationCenterController;
use App\Http\Controllers\Erp\OwnTasksController;
use App\Http\Controllers\Erp\SheetTransitionController;
use App\Http\Controllers\Erp\TeacherController;
use App\Http\Controllers\Erp\TuitionController;
use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Middleware\TeamAuthorization;
use Illuminate\Support\Facades\Route;

/*
| The staff app. /app/* serves the React host page; everything else here is the
| JSON backend it calls. Writes under /erp are the business actions (validation,
| permissions, audit and money rules live in their services); reads are under
| /app-api. TeamAuthorization applies the role matrix to every unit route.
*/

Route::get('/app/{path?}', [AppShellController::class, 'show'])->where('path', '.*')->middleware('auth')->name('app.shell');

Route::middleware(['auth', TeamAuthorization::class])->prefix('erp')->name('erp.')->group(function () {
    Route::post('/my-tasks/{task}/complete', [OwnTasksController::class, 'complete'])->whereNumber('task')->name('tasks.complete');
    Route::post('/my-tasks/{task}/items/{item}/complete', [OwnTasksController::class, 'completeItem'])->whereNumber('task')->whereNumber('item')->name('tasks.item.complete');
    Route::post('/teacher/attendance', [TeacherController::class, 'submit'])->name('teacher.submit');
    Route::post('/teacher/results', [TeacherController::class, 'result'])->name('teacher.result');
    Route::get('/units/{unit}/academics/export', [AcademicsController::class, 'export'])->whereNumber('unit')->name('academics.export');
    Route::post('/units/{unit}/business-actions/{action}', [BusinessController::class, 'mutate'])->whereNumber('unit')->name('business.mutate');
    Route::get('/units/{unit}/exports/{dataset}', [BusinessController::class, 'export'])->whereNumber('unit')->name('export');
    Route::get('/units/{unit}/reconciliation', [BusinessController::class, 'reconciliation'])->whereNumber('unit')->name('reconciliation');
    Route::get('/units/{unit}/lookup', [BusinessController::class, 'lookup'])->whereNumber('unit')->name('lookup');
    Route::post('/units/{unit}/{action}', [WorkspaceController::class, 'mutate'])->whereNumber('unit')->name('mutate');
});

// Private records have their own explicit role check and must never enter teacher exports.
Route::middleware('auth')->post('/erp/units/{unit}/tuition/{operation}', [TuitionController::class, 'mutate'])->whereNumber('unit')->name('erp.tuition.mutate');

Route::get('/erp/from-sheets', [SheetTransitionController::class, 'index'])->middleware('auth')->name('erp.sheets');

Route::middleware(['auth', 'admin'])->prefix('erp/administration')->name('erp.')->group(function () {
    Route::post('/teams', [AdministrationController::class, 'team'])->name('teams.create');
    Route::post('/teams/{id}', [AdministrationController::class, 'team'])->whereNumber('id')->name('teams.update');
    Route::post('/teams/{id}/archive', [AdministrationController::class, 'archive'])->whereNumber('id')->name('teams.archive');
    Route::post('/onboard', [AdministrationController::class, 'onboard'])->name('onboard');
    Route::post('/accounts/{id}/profile', [AdministrationController::class, 'profile'])->whereNumber('id')->middleware('throttle:20,1,admin-accounts')->name('accounts.profile');
    Route::post('/accounts/{id}', [AdministrationController::class, 'account'])->whereNumber('id')->middleware('throttle:20,1,admin-accounts')->name('accounts.update');
});

Route::middleware(['auth', 'admin'])->prefix('erp/migration-center')->name('erp.migration.')->group(function () {
    Route::post('/preview', [MigrationCenterController::class, 'preview'])->middleware('throttle:10,1,migration-preview')->name('preview');
    Route::post('/execute', [MigrationCenterController::class, 'execute'])->middleware('throttle:5,1,migration-execute')->name('execute');
});

Route::middleware(['auth', TeamAuthorization::class])->prefix('app-api/units/{unit}')->whereNumber('unit')->name('app.api.')->group(function () {
    // Each module keeps its JSON routes in routes/app/<module>.php. Every route
    // must declare ->defaults('erp_permission', [kind, value]); see TeamAuthorization.
    foreach (glob(__DIR__.'/app/*.php') as $moduleRoutes) require $moduleRoutes;
});
require __DIR__.'/app-global/me.php';
Route::middleware(['auth', 'admin'])->prefix('app-api/admin')->name('app.api.admin.')->group(fn () => require __DIR__.'/app-global/admin.php');
