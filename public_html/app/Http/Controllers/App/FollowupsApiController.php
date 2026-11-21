<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{People, TeamPermissions};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for the Follow-ups screen (page "followups"). Three views of the
 * classic page: the due queue (latest instruction per student, due today or
 * earlier), students by registration priority, and the conversation history.
 */
class FollowupsApiController extends Controller
{
    private const SORTS = [
        'due' => ['next_due_on' => 'f.next_due_on', 'name' => 's.name', 'registered_on' => 's.registered_on'],
        'students' => ['name' => 's.name', 'code' => 's.code', 'registered_on' => 's.registered_on', 'next_due_on' => 'lf.next_due_on', 'balance' => 'balance_paise'],
        'history' => ['created_at' => 'f.created_at', 'name' => 's.name', 'next_due_on' => 'f.next_due_on'],
    ];
    private const DEFAULT_SORT = ['due' => ['next_due_on', 'asc'], 'students' => ['registered_on', 'desc'], 'history' => ['created_at', 'desc']];

    public function index(Request $request, int $unit)
    {
        $view = in_array($request->query('view'), ['due', 'students', 'history'], true) ? $request->query('view') : 'due';
        $v = $request->validate([
            'view' => 'nullable|in:due,students,history',
            'q' => 'nullable|string|max:100',
            'priority' => 'nullable|in:super,high,medium',
            'student' => 'nullable|integer|min:1',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::SORTS[$view])),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $business = DB::table('erp_units')->find($unit);
        $permissions = new TeamPermissions();
        $user = $request->user();
        // Same rule as the classic students register on this page.
        $canSeeBalance = $permissions->allows($user, $unit, 'page', 'money') || $permissions->role($user, $unit) === 'sales_followups';
        $today = CarbonImmutable::now($business->timezone)->toDateString();
        $month = CarbonImmutable::now($business->timezone)->startOfMonth();
        $latestIds = DB::table('erp_followups')->where('unit_id', $unit)->select(DB::raw('MAX(id) AS id'))->groupBy('student_id');
        $latest = DB::table('erp_followups')->whereIn('id', $latestIds)->select('id', 'student_id', 'contact_channel', 'note', 'outcome', 'next_due_on', 'created_at');

        if ($view === 'due') {
            $query = DB::table('erp_followups as f')->join('erp_students as s', function ($j) { $j->on('s.id', '=', 'f.student_id')->on('s.unit_id', '=', 'f.unit_id'); })
                ->where('f.unit_id', $unit)->whereIn('f.id', $latestIds)->whereNotNull('f.next_due_on')->where('f.next_due_on', '<=', $today)
                ->select('s.id as student_id', 's.code', 's.name', 's.registered_on', 's.status', 'f.id', 'f.contact_channel', 'f.note', 'f.outcome', 'f.next_due_on', 'f.created_at');
        } elseif ($view === 'history') {
            $query = DB::table('erp_followups as f')->join('erp_students as s', function ($j) { $j->on('s.id', '=', 'f.student_id')->on('s.unit_id', '=', 'f.unit_id'); })
                ->leftJoin('users as u', 'u.id', '=', 'f.created_by')
                ->where('f.unit_id', $unit)
                ->select('s.id as student_id', 's.code', 's.name', 's.registered_on', 's.status', 'f.id', 'f.contact_channel', 'f.note', 'f.outcome', 'f.next_due_on', 'f.created_at', 'u.name as created_by_name');
        } else {
            $balances = DB::table('erp_ledger')->where('unit_id', $unit)->whereNotNull('student_id')->whereNull('teacher_id')
                ->select('student_id', DB::raw('SUM(amount_paise) AS balance'))->groupBy('student_id');
            $query = DB::table('erp_students as s')->leftJoinSub($balances, 'b', 's.id', '=', 'b.student_id')->leftJoinSub($latest, 'lf', 'lf.student_id', '=', 's.id')
                ->where('s.unit_id', $unit)
                ->select('s.id as student_id', 's.code', 's.name', 's.registered_on', 's.status', 'lf.id', 'lf.contact_channel', 'lf.note', 'lf.outcome', 'lf.next_due_on', 'lf.created_at', DB::raw('COALESCE(b.balance,0) AS balance_paise'));
        }

        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('s.name', 'like', '%'.$search.'%')->orWhere('s.code', 'like', '%'.$search.'%'));
        if (!empty($v['priority'])) {
            match ($v['priority']) {
                'super' => $query->where('s.registered_on', '>=', $month->toDateString()),
                'high' => $query->whereBetween('s.registered_on', [$month->subMonth()->toDateString(), $month->subDay()->toDateString()]),
                'medium' => $query->where('s.registered_on', '<', $month->subMonth()->toDateString()),
            };
        }
        [$sort, $dir] = self::DEFAULT_SORT[$view];
        $sort = $v['sort'] ?? $sort;
        $dir = $v['dir'] ?? (isset($v['sort']) ? 'asc' : $dir);
        if ($sort === 'balance' && !$canSeeBalance) $sort = 'registered_on';
        $query->orderBy(DB::raw(self::SORTS[$view][$sort]), $dir)->orderBy('s.id');
        if ($view !== 'students') $query->orderByDesc('f.id');

        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $people = new People();
        $rows = collect($page->items())->map(fn ($row) => [
            'id' => $view === 'students' ? (int) $row->student_id : (int) $row->id,
            'studentId' => (int) $row->student_id,
            'code' => $row->code,
            'name' => $row->name,
            'status' => $row->status,
            'priority' => $people->priority($row->registered_on, $business->timezone),
            'lastChannel' => $row->contact_channel,
            'lastNote' => $row->note,
            'lastOutcome' => $row->outcome,
            'lastContactAt' => $row->created_at,
            'nextDueOn' => $row->next_due_on,
            'isOverdue' => $row->next_due_on !== null && $row->next_due_on < $today,
            'createdByName' => $view === 'history' ? $row->created_by_name : null,
            'balancePaise' => $view === 'students' && $canSeeBalance ? (int) $row->balance_paise : null,
        ]);

        $dueBase = DB::table('erp_followups')->where('unit_id', $unit)->whereIn('id', $latestIds)->whereNotNull('next_due_on');
        $selected = null;
        if (!empty($v['student'])) {
            $s = DB::table('erp_students')->where('unit_id', $unit)->where('id', $v['student'])->first(['id', 'name', 'code']);
            if ($s) $selected = ['id' => (int) $s->id, 'label' => $s->name.' · '.$s->code];
        }
        return response()->json([
            'data' => $rows,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir, 'view' => $view],
            'can' => ['seeBalance' => $canSeeBalance, 'openProfile' => $permissions->allows($user, $unit, 'detail', 'all')],
            'counts' => [
                'dueToday' => (clone $dueBase)->where('next_due_on', $today)->count(),
                'overdue' => (clone $dueBase)->where('next_due_on', '<', $today)->count(),
                'loggedToday' => DB::table('erp_followups')->where('unit_id', $unit)->where('created_at', '>=', CarbonImmutable::now($business->timezone)->startOfDay()->utc()->format('Y-m-d H:i:s'))->count(),
            ],
            'today' => $today,
            'student' => $selected,
        ]);
    }
}
