<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Access, TeamPermissions};
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Serves the single page that hosts the React application for every /app URL. */
class AppShellController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $policy = new SiteAccessPolicy();
        $policy->assertSiteMember($user);
        $permissions = new TeamPermissions();
        $isAdmin = $policy->isAdministrator($user);
        $units = (new Access())->units($user)->map(function ($unit) use ($user, $permissions, $isAdmin) {
            $role = $permissions->role($user, (int) $unit->id);
            $role = in_array($role, ['admin', 'staff'], true) ? 'team_manager' : $role;
            $archived = $unit->archived_at !== null;
            return [
                'id' => (int) $unit->id,
                'name' => $unit->name,
                'code' => $unit->code,
                'timezone' => $unit->timezone ?? 'Asia/Kolkata',
                'role' => $role,
                'roleLabel' => $isAdmin ? 'Administrator' : (TeamPermissions::LABELS[$role] ?? 'Member'),
                'isArchived' => $archived,
            ] + $permissions->capabilities($user, (int) $unit->id, $archived);
        })->values();
        $session = [
            'user' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'initials' => Str::upper(Str::substr($user->name, 0, 1)),
                'isAdmin' => $isAdmin,
                'isTeacher' => DB::table('erp_teachers')->where('user_id', $user->id)->exists(),
            ],
            'units' => $units,
            'sheetsAuthority' => config('sheet_transition.authority') === 'sheets',
            'sheetsEnabled' => (bool) config('sheet_transition.enabled'),
            'legacyBase' => '/erp',
        ];
        return self::page($session + ['kind' => 'staff']);
    }

    /**
     * Render the React host page with the given session JSON. Portals (teacher,
     * parent, guest, public forms) call this with their own session shape; it
     * must only contain what that visitor may see. `kind` tells the client which
     * area it is in.
     */
    public static function page(array $session, string $title = 'ClapsLearn Operations')
    {
        $dir = trim((string) env('APP_UI_DIR', 'ui'), '/');
        $manifestPath = public_path($dir.'/manifest.json');
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
        return response()->view('app.shell', ['session' => $session, 'manifest' => $manifest ?: [], 'title' => $title])
            ->header('Cache-Control', 'no-store');
    }
}
