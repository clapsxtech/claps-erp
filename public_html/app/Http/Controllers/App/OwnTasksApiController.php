<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\TeamPermissions;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /app-api/me/tasks: the signed-in user's own assigned tasks across every
 * current team, for the teacher portal and for staff whose only membership is a
 * legacy role ('sales', 'teacher') that has no unit screens. Uses the same
 * membership roles Operations::ownedTask accepts for completion: tasks and
 * checklist items only, never student, assignee or money data.
 */
class OwnTasksApiController extends Controller
{
    /** Membership roles that may hold own tasks (Operations::ownedTask). */
    private static function roles(): array
    {
        return array_merge(['admin', 'staff', 'sales', 'teacher'], TeamPermissions::ROLES);
    }

    public function index(Request $request)
    {
        // status=open narrows the list (the teacher portal's Today card); the default is every status.
        $v = $request->validate(['status' => 'nullable|in:open,all', 'page' => 'nullable|integer|min:1|max:100000']);
        $user = $request->user();
        $policy = new SiteAccessPolicy();
        $policy->assertSiteMember($user);
        // Superadmins reach this without a team membership; they see only tasks assigned to them.
        if (!DB::table('erp_memberships')->where('user_id', $user->id)->whereIn('role', self::roles())->exists() && !$policy->isAdministrator($user)) {
            throw new AuthorizationException('Current staff membership is required.');
        }

        $remaining = DB::table('erp_task_items')->whereNull('completed_at')->select('task_id', 'unit_id', DB::raw('COUNT(*) AS remaining'))->groupBy('task_id', 'unit_id');
        $page = DB::table('erp_tasks as t')
            ->join('erp_memberships as m', function ($j) { $j->on('m.unit_id', '=', 't.unit_id')->on('m.user_id', '=', 't.assigned_to'); })
            ->join('erp_units as u', 'u.id', '=', 't.unit_id')
            ->leftJoinSub($remaining, 'r', function ($j) { $j->on('r.task_id', '=', 't.id')->on('r.unit_id', '=', 't.unit_id'); })
            ->whereNull('u.archived_at')
            ->when(($v['status'] ?? 'all') === 'open', fn ($q) => $q->where('t.status', '!=', 'completed'))
            ->where('t.assigned_to', $user->id)->where('m.user_id', $user->id)->whereIn('m.role', self::roles())
            ->orderByRaw('CASE WHEN t.due_on IS NULL THEN 1 ELSE 0 END')->orderBy('t.due_on')->orderBy('t.id')
            ->select('t.id', 't.unit_id', 'u.name as unit_name', 't.title', 't.notes', 't.due_on', 't.status', 'm.role', DB::raw('COALESCE(r.remaining,0) AS remaining_items'))
            ->paginate(25, ['*'], 'page', (int) ($v['page'] ?? 1));

        $rows = collect($page->items());
        // Guard corrupted cross-unit item rows as well as normal task scoping.
        $taskUnits = $rows->pluck('unit_id', 'id');
        $items = DB::table('erp_task_items')->whereIn('task_id', $taskUnits->keys())->orderBy('id')->get(['id', 'unit_id', 'task_id', 'title', 'completed_at'])
            ->filter(fn ($item) => isset($taskUnits[$item->task_id]) && (int) $taskUnits[$item->task_id] === (int) $item->unit_id)->groupBy('task_id');

        return response()->json([
            'data' => $rows->map(fn ($t) => [
                'id' => (int) $t->id,
                'unitId' => (int) $t->unit_id,
                'unitName' => $t->unit_name,
                'title' => $t->title,
                'notes' => $t->notes,
                'dueOn' => $t->due_on,
                'status' => $t->status,
                'remainingItems' => (int) $t->remaining_items,
                // POST /erp/my-tasks/... refuses read-only members (Operations::ownedTask).
                'canComplete' => $t->role !== 'read_only',
                'items' => collect($items->get($t->id, []))->map(fn ($item) => [
                    'id' => (int) $item->id,
                    'title' => $item->title,
                    'completedAt' => $item->completed_at ? CarbonImmutable::parse($item->completed_at, 'UTC')->toIso8601ZuluString() : null,
                ])->values(),
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'status' => $v['status'] ?? 'all'],
        ]);
    }
}
