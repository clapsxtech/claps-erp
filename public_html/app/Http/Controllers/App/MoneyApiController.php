<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{People, Reporting, TeamPermissions};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for Payments & dues. Access is enforced by TeamAuthorization (page "money")
 * before these run. Figures come from the ledger as posted and from the Reporting read
 * projections; nothing here posts or changes money. Writes go
 * through the /erp action routes (payment, request-*, receipt-*, refund-*, salary-*).
 */
class MoneyApiController extends Controller
{
    /** Ledger kinds the filter accepts; the same set Books labels. */
    public const KINDS = ['payment', 'refund', 'lesson_charge', 'teacher_accrual', 'teacher_payment', 'opening_balance', 'reversal'];
    private const DUE_SORTS = ['name' => 's.name', 'code' => 's.code', 'registered_on' => 's.registered_on', 'balance' => 'balance_paise'];
    private const REQUEST_SORTS = ['requested_on' => 'r.requested_on', 'due_on' => 'r.due_on', 'reference' => 'r.reference', 'amount' => 'r.amount_paise', 'student' => 's.name'];
    private const LEDGER_SORTS = ['occurred_at' => 'l.occurred_at', 'amount' => 'l.amount_paise'];
    private const TEACHER_SORTS = ['name' => 't.name', 'code' => 't.code'];
    /** The private tuition records' role check (TuitionController::authorizeTeam). */
    public const TUITION_ROLES = ['superadmin', 'admin', 'team_manager'];

    private function studentBalances(int $unit)
    {
        // Student-side ledger rows only.
        return DB::table('erp_ledger')->where('unit_id', $unit)->whereNotNull('student_id')->whereNull('teacher_id')
            ->select('student_id', DB::raw('SUM(amount_paise) AS balance'))->groupBy('student_id');
    }

    public function summary(Request $request, int $unit)
    {
        $balances = $this->studentBalances($unit);
        $pendingIds = DB::table('erp_payment_requests')->where('unit_id', $unit)->where('status', 'pending')->pluck('id')->all();
        $requests = collect((new Reporting())->requestSummaries($unit, $pendingIds))->where('status', 'pending');
        $teacherIds = DB::table('erp_teacher_units')->where('unit_id', $unit)->pluck('teacher_id')->all();
        $statements = collect((new Reporting())->teacherStatements($unit, $teacherIds));
        $permissions = new TeamPermissions();
        $user = $request->user();
        return response()->json([
            'totals' => [
                'dueCount' => DB::query()->fromSub($balances, 'x')->where('balance', '<', 0)->count(),
                'duePaise' => (int) DB::query()->fromSub($balances, 'x')->where('balance', '<', 0)->sum('balance'),
                'openRequestCount' => $requests->count(),
                'outstandingRequestPaise' => (int) $requests->sum('outstanding_paise'),
                'teachersOwedCount' => $statements->where('net_payable_paise', '>', 0)->count(),
                'teacherPayablePaise' => (int) $statements->sum('net_payable_paise'),
            ],
            'can' => [
                'openProfile' => $permissions->allows($user, $unit, 'detail', 'all'),
                'openTuition' => in_array($permissions->role($user, $unit), self::TUITION_ROLES, true),
            ],
        ]);
    }

    /** Collection queue: students who owe money (negative balance). */
    public function dues(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::DUE_SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $business = DB::table('erp_units')->find($unit);
        $query = DB::table('erp_students as s')->joinSub($this->studentBalances($unit), 'b', 's.id', '=', 'b.student_id')
            ->where('s.unit_id', $unit)->where('b.balance', '<', 0)
            ->select('s.id', 's.code', 's.name', 's.grade', 's.status', 's.registered_on', DB::raw('b.balance AS balance_paise'));
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('s.name', 'like', '%'.$search.'%')->orWhere('s.code', 'like', '%'.$search.'%'));
        $sort = $v['sort'] ?? 'balance';
        $dir = $v['dir'] ?? 'asc';
        $query->orderBy(DB::raw(self::DUE_SORTS[$sort]), $dir)->orderBy('s.id');
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $people = new People();
        return response()->json([
            'data' => collect($page->items())->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'grade' => $row->grade,
                'status' => $row->status,
                'registeredOn' => $row->registered_on,
                'priority' => $people->priority($row->registered_on, $business->timezone),
                'balancePaise' => (int) $row->balance_paise,
            ]),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir],
        ]);
    }

    /** Student picker for the payment, request and refund forms: by id (deep links) or by name/code. */
    public function students(Request $request, int $unit)
    {
        $v = $request->validate(['q' => 'nullable|string|max:100', 'id' => 'nullable|integer|min:1']);
        $query = DB::table('erp_students as s')->leftJoinSub($this->studentBalances($unit), 'b', 's.id', '=', 'b.student_id')
            ->where('s.unit_id', $unit)->select('s.id', 's.code', 's.name', 's.status', DB::raw('COALESCE(b.balance,0) AS balance_paise'));
        if (!empty($v['id'])) $query->where('s.id', $v['id']);
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('s.name', 'like', '%'.$search.'%')->orWhere('s.code', 'like', '%'.$search.'%'));
        return response()->json([
            'data' => $query->orderBy('s.name')->orderBy('s.id')->limit(20)->get()->map(fn ($row) => [
                'id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name, 'status' => $row->status, 'balancePaise' => (int) $row->balance_paise,
            ]),
        ]);
    }

    /** Every posting, newest first, filtered server-side. Rows are never editable; corrections are reversals. */
    public function ledger(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'kind' => 'nullable|in:'.implode(',', self::KINDS),
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'student' => 'nullable|integer|min:1',
            'teacher' => 'nullable|integer|min:1',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::LEDGER_SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $from = $v['from'] ?? null; $to = $v['to'] ?? null;
        if ($from && $to && $from > $to) [$from, $to] = [$to, $from];
        $search = trim((string) ($v['q'] ?? ''));
        $filter = function ($q) use ($unit, $v, $from, $to, $search) {
            $q->where('l.unit_id', $unit);
            if (!empty($v['kind'])) $q->where('l.kind', $v['kind']);
            if ($from) $q->where('l.occurred_at', '>=', $from.' 00:00:00');
            if ($to) $q->where('l.occurred_at', '<=', $to.' 23:59:59');
            if (!empty($v['student'])) $q->where('l.student_id', $v['student']);
            if (!empty($v['teacher'])) $q->where('l.teacher_id', $v['teacher']);
            if ($search !== '') {
                $q->where(fn ($w) => $w->where('l.reference', 'like', '%'.$search.'%')
                    ->orWhereIn('l.student_id', DB::table('erp_students')->where('unit_id', $unit)->where(fn ($s) => $s->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'))->select('id'))
                    ->orWhereIn('l.teacher_id', DB::table('erp_teachers')->where(fn ($s) => $s->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'))->select('id')));
            }
            return $q;
        };
        $sort = $v['sort'] ?? 'occurred_at';
        $dir = $v['dir'] ?? 'desc';
        $query = $filter(DB::table('erp_ledger as l'))
            ->leftJoin('erp_students as s', fn ($j) => $j->on('s.id', '=', 'l.student_id')->on('s.unit_id', '=', 'l.unit_id'))
            ->leftJoin('erp_teachers as t', 't.id', '=', 'l.teacher_id')
            ->leftJoin('erp_ledger as r', 'r.reverses_id', '=', 'l.id')
            ->select('l.id', 'l.occurred_at', 'l.kind', 'l.reference', 'l.amount_paise', 'l.currency', 'l.student_id', 'l.teacher_id', 'l.reverses_id', 's.name as student_name', 's.code as student_code', 't.name as teacher_name', 'r.id as reversed_by_id')
            ->orderBy(DB::raw(self::LEDGER_SORTS[$sort]), $dir)->orderBy('l.id', $dir);
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $rows = collect($page->items());

        // Totals of the whole filtered set (not just this page), INR postings only.
        $totals = $filter(DB::table('erp_ledger as l'))->where('l.currency', 'INR')
            ->selectRaw('COUNT(*) AS entries, COALESCE(SUM(CASE WHEN l.amount_paise > 0 THEN l.amount_paise ELSE 0 END),0) AS credits, COALESCE(SUM(CASE WHEN l.amount_paise < 0 THEN l.amount_paise ELSE 0 END),0) AS debits, COALESCE(SUM(l.amount_paise),0) AS net')
            ->first();

        // Running net of the filtered set in date order, for the rows on this page.
        $running = [];
        if ($sort === 'occurred_at' && $rows->isNotEmpty()) {
            $chronological = $dir === 'asc' ? $rows->values() : $rows->reverse()->values();
            $first = $chronological->first();
            $before = (int) $filter(DB::table('erp_ledger as l'))->where('l.currency', 'INR')
                ->where(fn ($q) => $q->where('l.occurred_at', '<', $first->occurred_at)->orWhere(fn ($w) => $w->where('l.occurred_at', $first->occurred_at)->where('l.id', '<', $first->id)))
                ->sum('l.amount_paise');
            $sum = $before;
            foreach ($chronological as $row) {
                if ($row->currency === 'INR') $sum += (int) $row->amount_paise;
                $running[$row->id] = $sum;
            }
        }

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'occurredAt' => $row->occurred_at,
                'kind' => $row->kind,
                'reference' => $row->reference,
                'amountPaise' => (int) $row->amount_paise,
                'currency' => $row->currency,
                'studentId' => $row->student_id !== null ? (int) $row->student_id : null,
                'studentName' => $row->student_name,
                'studentCode' => $row->student_code,
                'teacherId' => $row->teacher_id !== null ? (int) $row->teacher_id : null,
                'teacherName' => $row->teacher_name,
                'reversesId' => $row->reverses_id !== null ? (int) $row->reverses_id : null,
                'reversedById' => $row->reversed_by_id !== null ? (int) $row->reversed_by_id : null,
                'runningPaise' => $running[$row->id] ?? null,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir],
            'totals' => ['entries' => (int) $totals->entries, 'creditsPaise' => (int) $totals->credits, 'debitsPaise' => (int) $totals->debits, 'netPaise' => (int) $totals->net],
            'kinds' => self::KINDS,
        ]);
    }

    public function requests(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:pending,cancelled',
            'student' => 'nullable|integer|min:1',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::REQUEST_SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $query = DB::table('erp_payment_requests as r')->join('erp_students as s', fn ($j) => $j->on('s.id', '=', 'r.student_id')->on('s.unit_id', '=', 'r.unit_id'))
            ->where('r.unit_id', $unit)
            ->select('r.id', 'r.reference', 'r.student_id', 'r.requested_on', 'r.due_on', 's.name as student_name', 's.code as student_code');
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('r.reference', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')->orWhere('s.code', 'like', '%'.$search.'%'));
        if (!empty($v['status'])) $query->where('r.status', $v['status']);
        if (!empty($v['student'])) $query->where('r.student_id', $v['student']);
        $sort = $v['sort'] ?? 'requested_on';
        $dir = $v['dir'] ?? 'desc';
        $query->orderBy(DB::raw(self::REQUEST_SORTS[$sort]), $dir)->orderBy('r.id', $dir);
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $summaries = (new Reporting())->requestSummaries($unit, collect($page->items())->pluck('id')->all());
        return response()->json([
            'data' => collect($page->items())->map(function ($row) use ($summaries) {
                $s = $summaries[$row->id];
                return [
                    'id' => (int) $row->id,
                    'reference' => $row->reference,
                    'studentId' => (int) $row->student_id,
                    'studentName' => $row->student_name,
                    'studentCode' => $row->student_code,
                    'requestedOn' => $row->requested_on,
                    'dueOn' => $row->due_on,
                    'requestedPaise' => (int) $s['requested_paise'],
                    'coveredPaise' => (int) $s['covered_paise'],
                    'outstandingPaise' => (int) $s['outstanding_paise'],
                    'status' => $s['status'],
                ];
            })->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir],
        ]);
    }

    /** Teacher pay: earned, paid and owed per teacher from Reporting::teacherStatements. */
    public function teachers(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::TEACHER_SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $query = DB::table('erp_teachers as t')->join('erp_teacher_units as u', 'u.teacher_id', '=', 't.id')->where('u.unit_id', $unit)->select('t.id', 't.code', 't.name', 't.status');
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('t.name', 'like', '%'.$search.'%')->orWhere('t.code', 'like', '%'.$search.'%'));
        $sort = $v['sort'] ?? 'name';
        $dir = $v['dir'] ?? 'asc';
        $query->orderBy(DB::raw(self::TEACHER_SORTS[$sort]), $dir)->orderBy('t.id');
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $statements = (new Reporting())->teacherStatements($unit, collect($page->items())->pluck('id')->all());
        return response()->json([
            'data' => collect($page->items())->map(function ($row) use ($statements) {
                $s = $statements[$row->id];
                return [
                    'id' => (int) $row->id,
                    'code' => $row->code,
                    'name' => $row->name,
                    'status' => $row->status,
                    'earnedPaise' => (int) $s['earned_paise'],
                    'paidPaise' => (int) $s['paid_paise'],
                    'otherAdjustmentsPaise' => (int) $s['other_adjustments_paise'],
                    'netPayablePaise' => (int) $s['net_payable_paise'],
                ];
            })->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir],
        ]);
    }
}
