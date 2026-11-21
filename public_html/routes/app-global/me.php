<?php
// The signed-in user's own JSON reads, under /app-api/me (route names app.api.me.*). Not unit-scoped:
// TeamAuthorization only asserts site membership here, so the controller checks membership itself.
// Loaded from routes/erp.php; used by both the staff app and the teacher portal.
use App\Http\Controllers\App\OwnTasksApiController;
use App\Http\Middleware\TeamAuthorization;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', TeamAuthorization::class])->prefix('app-api/me')->name('app.api.me.')->group(function () {
    // Own assigned tasks across teams (only tasks assigned to the signed-in user, in teams where they still hold a role). Completion stays on POST /erp/my-tasks/*.
    Route::get('/tasks', [OwnTasksApiController::class, 'index'])->name('tasks');
});
