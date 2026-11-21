<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Erp\Services\{IndianStates, TeamPermissions};
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for the global "Teams & accounts" screen (classic AdministrationController::index).
 * Route middleware 'admin' already requires a superadmin; each action asserts it again.
 * Account rows are explicit arrays: never a serialized User (password, 2FA, tokens).
 * Writes stay on the classic /erp/administration POST routes.
 */
class AdminTeamsApiController extends Controller
{
    public function teams(Request $request)
    {
        (new SiteAccessPolicy())->assertAdministrator($request->user());
        $members = DB::table('erp_memberships')->select('unit_id', DB::raw('COUNT(*) AS total'))->groupBy('unit_id')->pluck('total', 'unit_id');
        $teams = DB::table('erp_units')->orderBy('name')
            ->get(['id', 'code', 'name', 'timezone', 'phone', 'legal_name', 'address', 'state', 'tax_mode', 'tax_id', 'invoice_prefix', 'archived_at'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'timezone' => $t->timezone,
                'phone' => $t->phone,
                'legalName' => $t->legal_name,
                'address' => $t->address,
                'state' => $t->state,
                'taxMode' => $t->tax_mode === 'gst' ? 'gst' : 'none',
                'taxId' => $t->tax_id,
                'invoicePrefix' => $t->invoice_prefix,
                'isArchived' => $t->archived_at !== null,
                'archivedAt' => $t->archived_at,
                'memberCount' => (int) ($members[$t->id] ?? 0),
            ])->values();
        return response()->json([
            'data' => $teams,
            'states' => IndianStates::names(),
            'roles' => collect(TeamPermissions::LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function accounts(Request $request)
    {
        $policy = new SiteAccessPolicy();
        $policy->assertAdministrator($request->user());
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,suspended',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $query = User::query()->where('business_id', config('erp.business_id'))->whereNull('deleted_at')->select('id', 'name', 'email', 'status', 'user_type', 'business_id', 'deleted_at');
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($w) => $w->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        if (($v['status'] ?? '') === 'active') $query->where('status', 1);
        if (($v['status'] ?? '') === 'suspended') $query->where('status', '!=', 1);
        $page = $query->orderBy('name')->orderBy('id')->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $ids = collect($page->items())->pluck('id');
        $memberships = DB::table('erp_memberships as m')->join('erp_units as t', 't.id', '=', 'm.unit_id')->whereIn('m.user_id', $ids)
            ->orderBy('t.name')->get(['m.user_id', 'm.unit_id', 'm.role', 't.name'])->groupBy('user_id');
        $rows = collect($page->items())->map(fn (User $u) => [
            'id' => (int) $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'isActive' => (int) $u->status === 1,
            'isAdmin' => $policy->isAdministrator($u),
            'isOwner' => (int) $u->id === 1,
            'isSelf' => (int) $u->id === (int) $request->user()->id,
            'memberships' => $memberships->get($u->id, collect())->map(fn ($m) => [
                'unitId' => (int) $m->unit_id,
                'unitName' => $m->name,
                'role' => $m->role,
                'roleLabel' => TeamPermissions::LABELS[$m->role] ?? $m->role,
            ])->values(),
        ])->values();
        return response()->json([
            'data' => $rows,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => 'name', 'dir' => 'asc'],
        ])->header('Cache-Control', 'no-store');
    }
}
