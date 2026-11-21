<?php
namespace App\Http\Controllers\App;

use App\Models\User;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Who may use the teacher portal. This is a line-for-line copy of
 * Erp\TeacherController::identity() (same checks, same order, same messages):
 * an explicitly linked active teacher, exactly one unit pool, a unit that is not
 * archived, and an explicit 'teacher' membership in that unit. The staff role
 * matrix is deliberately not used: its 'member' kind refuses teachers.
 * AppTeacherPortalTest asserts both guards refuse the same cases.
 */
class TeacherPortalIdentity
{
    /** @return array{0: object, 1: int, 2: int} [teacher(id, code, name, timezone), unitId, userId] */
    public static function resolve(User $user): array
    {
        (new SiteAccessPolicy())->assertSiteMember($user);
        // Explicit administrator-created user linkage only. Never infer identity from a name/email.
        $teacher = DB::table('erp_teachers')->where('user_id', $user->id)->where('status', 'active')->first(['id', 'code', 'name', 'timezone']);
        if (!$teacher) throw new AuthorizationException('An active, explicitly linked teacher account is required.');
        $pools = DB::table('erp_teacher_units')->where('teacher_id', $teacher->id)->get();
        if ($pools->count() !== 1) throw new AuthorizationException('Teacher must belong to exactly one unit pool.');
        $unitId = (int) $pools->first()->unit_id;
        if (DB::table('erp_units')->where('id', $unitId)->whereNotNull('archived_at')->exists()) throw new AuthorizationException('This team is archived.');
        if (!DB::table('erp_memberships')->where('user_id', $user->id)->where('unit_id', $unitId)->where('role', 'teacher')->exists()) throw new AuthorizationException('Explicit teacher membership in this unit is required.');
        return [$teacher, $unitId, (int) $user->id];
    }

    /**
     * Mirrors TeacherController::entry(): anyone with a teacher record or a
     * teacher membership, or with no teams at all, goes to the dashboard (and is
     * refused there if resolve() fails). Only the remainder see setup steps.
     */
    public static function belongsInPortal(User $user, $units): bool
    {
        return DB::table('erp_teachers')->where('user_id', $user->id)->exists()
            || DB::table('erp_memberships')->where('user_id', $user->id)->where('role', 'teacher')->exists()
            || $units->isEmpty();
    }
}
