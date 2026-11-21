<?php
// Teacher portal (React). Loaded after routes/erp.php, so GET /teacher replaces the
// classic entry (TeacherController::entry) and keeps its name `teacher.portal`.
// Access is TeacherPortalIdentity (a copy of TeacherController::identity()), not the
// staff role matrix. Writes stay on the classic POST /erp/teacher/attendance and
// /erp/teacher/results routes, which answer JSON when asked.
use App\Http\Controllers\App\TeacherPortalController as Portal;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/teacher', [Portal::class, 'page'])->name('teacher.portal');
    Route::get('/teacher/{section}', [Portal::class, 'page'])->whereIn('section', Portal::SECTIONS)->name('teacher.portal.section');
    Route::get('/teacher-api/workspace', [Portal::class, 'workspace'])->name('teacher.api.workspace');
});
