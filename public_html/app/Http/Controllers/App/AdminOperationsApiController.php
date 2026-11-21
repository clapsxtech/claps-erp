<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\OperationalHealth;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for "Operations & audit" and the migration status panel. Superadmin only
 * (route middleware 'admin', asserted again here). Audit payloads and migration
 * manifests/digests are never returned.
 */
class AdminOperationsApiController extends Controller
{
    public function operations(Request $request)
    {
        (new SiteAccessPolicy())->assertAdministrator($request->user());
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'unit' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        // Always 200, even when a check fails: a 503 would hide the checks exactly when
        // they matter. The status is in the body.
        $health = (new OperationalHealth())->inspect();
        $query = DB::table('erp_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->leftJoin('erp_units as t', 't.id', '=', 'a.unit_id')
            ->select('a.id', 'a.unit_id', 'a.actor_id', 'a.action', 'a.entity_type', 'a.entity_id', 'a.created_at', 'u.name as actor_name', 't.name as unit_name');
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($w) => $w->where('a.action', 'like', '%'.$search.'%')->orWhere('a.entity_type', 'like', '%'.$search.'%'));
        if (!empty($v['unit'])) $query->where('a.unit_id', (int) $v['unit']);
        $page = $query->orderByDesc('a.id')->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $events = collect($page->items())->map(fn ($e) => [
            'id' => (int) $e->id,
            'createdAt' => $e->created_at,
            'actorId' => $e->actor_id !== null ? (int) $e->actor_id : null,
            'actorName' => $e->actor_id !== null ? ($e->actor_name ?? 'Unavailable account') : null,
            'unitId' => $e->unit_id !== null ? (int) $e->unit_id : null,
            'unitName' => $e->unit_name,
            'action' => $e->action,
            'entityType' => $e->entity_type,
            'entityId' => $e->entity_id !== null ? (int) $e->entity_id : null,
        ])->values();
        return response()->json([
            'health' => $health,
            'events' => $events,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => 'id', 'dir' => 'desc'],
        ])->header('Cache-Control', 'no-store');
    }

    public function migration(Request $request)
    {
        (new SiteAccessPolicy())->assertAdministrator($request->user());
        $batches = DB::table('erp_migration_batches as b')->leftJoin('users as u', 'u.id', '=', 'b.actor_id')
            ->orderByDesc('b.id')->limit(50)
            ->get(['b.id', 'b.source_id', 'b.cutoff', 'b.created_at', 'b.actor_id', 'u.name as actor_name'])
            ->map(fn ($b) => [
                'id' => (int) $b->id,
                'sourceId' => $b->source_id,
                'cutoff' => $b->cutoff,
                'createdAt' => $b->created_at,
                'actorName' => $b->actor_name ?? 'Unavailable account',
            ])->values();
        return response()->json([
            'batches' => $batches,
            'historyRows' => DB::table('erp_migration_history')->count(),
        ])->header('Cache-Control', 'no-store');
    }
}
