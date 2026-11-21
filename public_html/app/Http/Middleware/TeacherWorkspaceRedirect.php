<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Linked teachers who open an old public form link are sent to the matching section of their portal. */
class TeacherWorkspaceRedirect
{
    private const DESTINATIONS = ['/timetableReport' => 'timetable', '/timetable' => 'timetable', '/attendance' => 'attendance', '/resultform' => 'results'];

    public function handle(Request $request, Closure $next)
    {
        $section = self::DESTINATIONS[$request->getPathInfo()] ?? null;
        if ($section && $request->isMethod('get') && $request->user()
            && !(new \App\Modules\IdentityAccess\Services\SiteAccessPolicy())->isAdministrator($request->user())
            && (DB::table('erp_memberships')->where('user_id', $request->user()->id)->where('role', 'teacher')->exists()
                || DB::table('erp_teachers')->where('user_id', $request->user()->id)->exists())) {
            return redirect('/teacher/'.$section);
        }
        return $next($request);
    }
}
