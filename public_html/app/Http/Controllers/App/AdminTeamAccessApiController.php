<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\TeamPermissions;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON read for the unit "Team & access" screen (page "staff"). TeamAuthorization
 * checks ['page','staff'], which only superadmins pass; the controller asserts it again
 * because the screen lists accounts across the organization. Writes go to the
 * business-actions routes (membership-grant/revoke, teacher-link/unlink).
 */
class AdminTeamAccessApiController extends Controller
{
    /** Roles the grant form offers, in order. */
    private const GRANT_ROLES = [
        'team_manager' => 'Team manager',
        'academics' => 'Academics / attendance',
        'finance' => 'Finance',
        'sales_followups' => 'Sales / follow-ups',
        'read_only' => 'Read-only',
        'teacher' => 'Teacher (linked teacher login)',
        'admin' => 'Unit administrator (legacy)',
        'staff' => 'Staff (legacy, same as team manager)',
        'sales' => 'Sales (legacy)',
    ];

    public function show(Request $request, int $unit)
    {
        (new SiteAccessPolicy())->assertAdministrator($request->user());
        $team = DB::table('erp_units')->where('id', $unit)->select('id', 'code', 'name', 'archived_at')->first();
        abort_unless($team, 404);

        // Left join: revocation must still list memberships of suspended, deleted or missing accounts.
        $members = DB::table('erp_memberships as m')->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.unit_id', $unit)
            ->orderBy('u.name')->orderBy('m.user_id')
            ->get(['m.user_id', 'm.role', 'm.updated_at', 'u.name', 'u.email', 'u.status', 'u.deleted_at', 'u.business_id'])
            ->map(fn ($row) => [
                'userId' => (int) $row->user_id,
                'name' => $row->name ?? 'Unavailable account',
                'email' => $row->email,
                'role' => $row->role,
                'roleLabel' => self::GRANT_ROLES[$row->role] ?? $row->role,
                'isActive' => $row->name !== null && (int) $row->status === 1 && $row->deleted_at === null && (int) $row->business_id === (int) config('erp.business_id'),
                'isSelf' => (int) $row->user_id === (int) $request->user()->id,
                'updatedAt' => $row->updated_at,
            ])->values();

        $teachers = DB::table('erp_teachers as t')->join('erp_teacher_units as p', 'p.teacher_id', '=', 't.id')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->where('p.unit_id', $unit)
            ->orderBy('t.name')
            ->get(['t.id', 't.code', 't.name', 't.status', 't.user_id', 'u.name as user_name'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'status' => $row->status,
                'linkedUserId' => $row->user_id !== null ? (int) $row->user_id : null,
                'linkedUserName' => $row->user_id !== null ? ($row->user_name ?? 'Unavailable account') : null,
            ])->values();

        // Same filter as the "users" lookup: active accounts of this organization.
        $accounts = DB::table('users')->where('business_id', config('erp.business_id'))->where('status', 1)->whereNull('deleted_at')
            ->orderBy('name')->limit(2000)
            ->get(['id', 'name', 'email'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'email' => $row->email])->values();

        return response()->json([
            'team' => ['id' => (int) $team->id, 'code' => $team->code, 'name' => $team->name, 'isArchived' => $team->archived_at !== null],
            'members' => $members,
            'teachers' => $teachers,
            'accounts' => $accounts,
            'roles' => collect(self::GRANT_ROLES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ])->header('Cache-Control', 'no-store');
    }
}
