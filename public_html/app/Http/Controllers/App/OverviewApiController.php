<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Insights, Reporting, SalesReporting, TeamPermissions, WorkQueues};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for the Overview & reports screens: Home, My tasks, Trends, Exports & checks
 * and the Sales report. TeamAuthorization checks each route's declared permission first;
 * Home and My tasks are for every unit member, so every tile and the "all tasks" view are
 * gated again here with allows(). Every response lists its fields explicitly.
 */
class OverviewApiController extends Controller
{
    private const TASK_SORTS = ['due_on' => 't.due_on', 'title' => 't.title', 'status' => 't.status', 'created' => 't.id'];
    private const HISTORY_SORTS = ['starts_at' => 'starts_at', 'external_id' => 'external_id', 'actual_minutes' => 'actual_minutes', 'status' => 'status'];

    public function home(Request $request, int $unit)
    {
        $user = $request->user();
        $permissions = new TeamPermissions();
        $can = fn (string $page) => $permissions->allows($user, $unit, 'page', $page);
        $business = DB::table('erp_units')->find($unit);
        $zone = $business->timezone;
        $now = CarbonImmutable::now($zone);
        $today = $now->toDateString();

        // The classic dashboard metrics, each only for the role that has the page it summarises.
        $metrics = [
            'activeStudents' => $can('people') ? DB::table('erp_students')->where('unit_id', $unit)->where('status', 'active')->count() : null,
            'scheduledLessons' => $can('timetable') ? DB::table('erp_lessons')->where('unit_id', $unit)->where('status', 'scheduled')->count() : null,
            'pendingApproval' => $can('attendance') ? DB::table('erp_attendance')->where('unit_id', $unit)->where('status', 'submitted')->count() : null,
            'duplicateReviews' => $can('attendance') ? DB::table('erp_duplicate_reviews')->where('unit_id', $unit)->where('status', 'pending')->count() : null,
        ];

        // Same balance rule and subquery as StudentsApiController, so the tile matches the list it opens.
        $canSeeBalance = $can('money') || $permissions->role($user, $unit) === 'sales_followups';
        $dues = null;
        if ($canSeeBalance) {
            $balances = DB::table('erp_ledger')->where('unit_id', $unit)->whereNotNull('student_id')->whereNull('teacher_id')
                ->select('student_id', DB::raw('SUM(amount_paise) AS balance'))->groupBy('student_id');
            $dues = [
                'count' => DB::query()->fromSub($balances, 'x')->where('balance', '<', 0)->count(),
                'paise' => (int) DB::query()->fromSub($balances, 'x')->where('balance', '<', 0)->sum('balance'),
            ];
        }

        $todayLessons = null;
        if ($can('timetable')) {
            $from = $now->startOfDay()->utc()->format('Y-m-d H:i:s');
            $until = $now->startOfDay()->addDay()->utc()->format('Y-m-d H:i:s');
            $lessons = DB::table('erp_lessons as l')
                ->join('erp_students as s', function ($j) { $j->on('s.id', '=', 'l.student_id')->on('s.unit_id', '=', 'l.unit_id'); })
                ->join('erp_teachers as t', 't.id', '=', 'l.teacher_id')
                ->leftJoin('erp_subjects as subject', 'subject.id', '=', 'l.subject_id')
                ->where('l.unit_id', $unit)->where('l.starts_at', '>=', $from)->where('l.starts_at', '<', $until)
                ->whereNotIn('l.status', ['cancelled', 'rescheduled']);
            $todayLessons = [
                'count' => (clone $lessons)->count(),
                'rows' => $lessons->orderBy('l.starts_at')->orderBy('l.id')->limit(8)
                    ->get(['l.id', 'l.starts_at', 'l.status', 'l.kind', 's.name as student_name', 't.name as teacher_name', 'subject.name as subject_name'])
                    ->map(fn ($r) => [
                        'id' => (int) $r->id,
                        'startsAt' => CarbonImmutable::parse($r->starts_at, 'UTC')->toIso8601ZuluString(),
                        'studentName' => $r->student_name,
                        'teacherName' => $r->teacher_name,
                        'subjectName' => $r->subject_name,
                        'kind' => $r->kind,
                        'status' => $r->status,
                    ])->values(),
            ];
        }

        // Every member sees their own open tasks in this unit.
        $mine = DB::table('erp_tasks')->where('unit_id', $unit)->where('assigned_to', $user->id)->where('status', '!=', 'completed');
        $myTasks = [
            'count' => (clone $mine)->count(),
            'overdue' => (clone $mine)->whereNotNull('due_on')->where('due_on', '<', $today)->count(),
            'rows' => $mine->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')->orderBy('due_on')->orderBy('id')->limit(5)
                ->get(['id', 'title', 'due_on', 'status'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'title' => $r->title, 'dueOn' => $r->due_on, 'status' => $r->status])->values(),
        ];

        $unitTasks = null;
        $followupsDue = null;
        if ($can('followups')) {
            $open = DB::table('erp_tasks')->where('unit_id', $unit)->where('status', '!=', 'completed');
            $unitTasks = ['count' => (clone $open)->count(), 'overdue' => (clone $open)->whereNotNull('due_on')->where('due_on', '<', $today)->count()];
            // Classic "Due follow-ups": the latest instruction per student, due today or earlier.
            $latest = DB::table('erp_followups')->where('unit_id', $unit)->select(DB::raw('MAX(id) AS id'))->groupBy('student_id');
            $followupsDue = DB::table('erp_followups')->where('unit_id', $unit)->whereIn('id', $latest)->whereNotNull('next_due_on')->where('next_due_on', '<=', $today)->count();
        }

        // Attendance work queues: the attendance page has all three; money sees only the import queue (as in the classic money page).
        $queues = null;
        if ($can('attendance') || $can('money')) {
            $work = (new WorkQueues())->attendance($unit);
            $queues = [
                'missingSubmission' => $can('attendance') ? $work['missing_submission']->total() : null,
                'studentImport' => $work['student_import']->total(),
                'teacherApproval' => $can('attendance') ? $work['teacher_approval']->total() : null,
            ];
        }

        return response()->json([
            'today' => $today,
            'timezone' => $zone,
            'metrics' => $metrics,
            'dues' => $dues,
            'todayLessons' => $todayLessons,
            'myTasks' => $myTasks,
            'unitTasks' => $unitTasks,
            'followupsDue' => $followupsDue,
            'queues' => $queues,
        ]);
    }

    public function tasks(Request $request, int $unit)
    {
        $v = $request->validate([
            'scope' => 'nullable|in:mine,all',
            'status' => 'nullable|in:open,completed,all',
            'q' => 'nullable|string|max:100',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::TASK_SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $user = $request->user();
        $permissions = new TeamPermissions();
        $business = DB::table('erp_units')->find($unit);
        // The unit-wide task register is the classic Follow-ups page; everyone else sees only their own.
        $canSeeAll = $permissions->allows($user, $unit, 'page', 'followups');
        $scope = $v['scope'] ?? 'mine';
        abort_if($scope === 'all' && !$canSeeAll, 403, 'Your team role does not allow this operation.');
        $status = $v['status'] ?? 'open';
        $role = $permissions->role($user, $unit);
        $writable = !$business->archived_at;

        $remaining = DB::table('erp_task_items')->where('unit_id', $unit)->whereNull('completed_at')
            ->select('task_id', DB::raw('COUNT(*) AS remaining'))->groupBy('task_id');
        $query = DB::table('erp_tasks as t')
            ->leftJoinSub($remaining, 'r', 't.id', '=', 'r.task_id')
            ->leftJoin('erp_students as s', function ($j) { $j->on('s.id', '=', 't.student_id')->on('s.unit_id', '=', 't.unit_id'); })
            ->leftJoin('users as a', 'a.id', '=', 't.assigned_to')
            ->where('t.unit_id', $unit)
            ->select('t.id', 't.title', 't.notes', 't.due_on', 't.status', 't.assigned_to', 's.name as student_name', 'a.name as assignee_name', DB::raw('COALESCE(r.remaining,0) AS remaining_items'));
        if ($scope === 'mine') $query->where('t.assigned_to', $user->id);
        if ($status === 'open') $query->where('t.status', '!=', 'completed');
        if ($status === 'completed') $query->where('t.status', 'completed');
        $search = trim((string) ($v['q'] ?? ''));
        // Notes are shown only on the assignee's own-task view (classic Follow-ups lists no notes), so only "mine" searches them.
        if ($search !== '') $query->where(fn ($q) => $scope === 'mine' ? $q->where('t.title', 'like', '%'.$search.'%')->orWhere('t.notes', 'like', '%'.$search.'%') : $q->where('t.title', 'like', '%'.$search.'%'));
        $sort = $v['sort'] ?? 'due_on';
        $dir = $v['dir'] ?? 'asc';
        if ($sort === 'due_on') $query->orderByRaw('CASE WHEN t.due_on IS NULL THEN 1 ELSE 0 END');
        $query->orderBy(DB::raw(self::TASK_SORTS[$sort]), $dir)->orderBy('t.id');
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));

        $ids = collect($page->items())->pluck('id')->all();
        $items = DB::table('erp_task_items')->where('unit_id', $unit)->whereIn('task_id', $ids)->orderBy('id')
            ->get(['id', 'task_id', 'title', 'completed_at'])->groupBy('task_id');
        $rows = collect($page->items())->map(fn ($row) => [
            'id' => (int) $row->id,
            'title' => $row->title,
            'notes' => (int) $row->assigned_to === (int) $user->id ? $row->notes : null,
            'dueOn' => $row->due_on,
            'status' => $row->status,
            'remainingItems' => (int) $row->remaining_items,
            'assignedToMe' => (int) $row->assigned_to === (int) $user->id,
            // Student and assignee labels belong to the Follow-ups register; the own-task portal shows neither.
            'studentName' => $canSeeAll ? $row->student_name : null,
            'assigneeName' => $canSeeAll ? $row->assignee_name : null,
            'items' => collect($items->get($row->id, []))->map(fn ($item) => [
                'id' => (int) $item->id,
                'title' => $item->title,
                'completedAt' => $item->completed_at ? CarbonImmutable::parse($item->completed_at, 'UTC')->toIso8601ZuluString() : null,
            ])->values(),
        ]);

        $mineOpen = DB::table('erp_tasks')->where('unit_id', $unit)->where('assigned_to', $user->id)->where('status', '!=', 'completed')->count();
        return response()->json([
            'data' => $rows,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir],
            'scope' => $scope,
            'counts' => [
                'mineOpen' => $mineOpen,
                'allOpen' => $canSeeAll ? DB::table('erp_tasks')->where('unit_id', $unit)->where('status', '!=', 'completed')->count() : null,
            ],
            'can' => [
                'seeAll' => $canSeeAll,
                'create' => $writable && $permissions->allows($user, $unit, 'action', 'task'),
                // Unit routes (JSON): business-actions/task-update and task-item-complete.
                'complete' => $writable && $permissions->allows($user, $unit, 'action', 'task-update'),
                'completeItem' => $writable && $permissions->allows($user, $unit, 'action', 'task-item-complete'),
                // Own-task routes: any current member except read-only (Operations::ownedTask).
                'completeOwn' => $writable && $role !== 'read_only',
            ],
        ]);
    }

    public function insights(Request $request, int $unit)
    {
        $canSeeMoney = (new TeamPermissions())->allows($request->user(), $unit, 'page', 'money');
        $money = fn ($value) => $canSeeMoney ? (int) $value : null;
        $months = collect((new Insights())->monthly($unit, 6))->map(fn ($m) => [
            'month' => $m->month,
            'label' => $m->label,
            'short' => $m->short,
            'enquiries' => (int) $m->enquiries,
            'demos' => (int) $m->demos,
            'joined' => (int) $m->joined,
            'conversion' => $m->conversion === null ? null : (float) $m->conversion,
            'newStudents' => (int) $m->new_students,
            'activeStudents' => (int) $m->active_students,
            'hours' => (float) $m->hours,
            'cancelled' => (int) $m->cancelled,
            'noShows' => (int) $m->no_shows,
            'noShowRate' => $m->no_show_rate === null ? null : (float) $m->no_show_rate,
            'billedPaise' => $money($m->billed),
            'collectedPaise' => $money($m->collected),
            'refundsPaise' => $money($m->refunds),
            'teacherCostPaise' => $money($m->teacher_cost),
            'marginPaise' => $money($m->margin),
            'duesPaise' => $money($m->dues),
        ])->values();
        return response()->json(['months' => $months, 'can' => ['seeMoney' => $canSeeMoney]]);
    }

    public function exports(Request $request, int $unit)
    {
        $user = $request->user();
        $permissions = new TeamPermissions();
        $report = new Reporting();
        $r = $report->reconciliation($unit);
        $canSeeMoney = $permissions->allows($user, $unit, 'page', 'money');
        $datasets = collect(array_keys($report->datasets()))->map(fn ($dataset) => [
            'dataset' => $dataset,
            'count' => (int) ($r['counts'][$dataset] ?? 0),
            'canExport' => $permissions->allows($user, $unit, 'export', $dataset),
        ])->values();
        return response()->json([
            'generatedAt' => $r['generated_at_utc'],
            'datasets' => $datasets,
            'ledgerByKind' => $canSeeMoney ? collect($r['ledger_by_kind'])->map(fn ($row) => [
                'kind' => $row->kind, 'currency' => $row->currency, 'entries' => (int) $row->entries, 'amountPaise' => (int) $row->amount_paise,
            ])->values() : null,
            'attendanceMinutes' => (int) $r['attendance_minutes'],
            'archivedHistoricalMinutes' => (int) $r['archived_historical_minutes'],
            'pendingDuplicateReviews' => (int) $r['pending_duplicate_reviews'],
            'sourceReconciled' => (bool) $r['source_reconciled'],
            'notice' => $r['notice'],
            'reconciliationUrl' => route('erp.reconciliation', $unit),
        ]);
    }

    public function history(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:128',
            'status' => 'nullable|string|max:32',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::HISTORY_SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $query = DB::table('erp_migration_history')->where('unit_id', $unit)
            ->select('id', 'external_id', 'enrollment_id', 'starts_at', 'actual_minutes', 'status', 'financial_effect');
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where('external_id', 'like', '%'.$search.'%');
        if (!empty($v['status'])) $query->where('status', $v['status']);
        $sort = $v['sort'] ?? 'starts_at';
        $dir = $v['dir'] ?? 'desc';
        $query->orderBy(self::HISTORY_SORTS[$sort], $dir)->orderBy('id', $dir);
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $rows = collect($page->items())->map(fn ($row) => [
            'id' => (int) $row->id,
            'externalId' => $row->external_id,
            'enrollmentId' => (int) $row->enrollment_id,
            'startsAt' => CarbonImmutable::parse($row->starts_at, 'UTC')->toIso8601ZuluString(),
            'actualMinutes' => (int) $row->actual_minutes,
            'status' => $row->status,
            'financialEffect' => $row->financial_effect,
        ]);
        $statuses = DB::table('erp_migration_history')->where('unit_id', $unit)->distinct()->orderBy('status')->pluck('status')->values();
        return response()->json([
            'data' => $rows,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir],
            'statuses' => $statuses,
        ]);
    }

    public function salesReport(Request $request, int $unit)
    {
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $input = array_filter(['report_from' => $request->query('from'), 'report_to' => $request->query('to')], fn ($value) => $value !== null && $value !== '');
        // The service scopes leads to what this user may see and only adds collections for money roles.
        $r = (new SalesReporting())->read($unit, $request->user(), $input);
        $outcome = fn ($row, string $name) => [
            $name => $row->$name,
            'enquiries' => (int) $row->enquiries,
            'demoLeads' => (int) $row->demo_leads,
            'joined' => (int) $row->joined,
            'lost' => (int) $row->lost,
        ];
        return response()->json([
            'from' => $r['dates']['report_from'],
            'to' => $r['dates']['report_to'],
            'timezone' => $r['zone'],
            'staff' => $r['staff']->map(fn ($row) => [
                'name' => $row->name,
                'enquiries' => (int) $row->enquiries,
                'repeats' => (int) $row->repeats,
                'contacts' => (int) $row->contacts,
                'demos' => (int) $row->demos,
                'feedback' => (int) $row->feedback,
                'registrations' => (int) $row->registrations,
            ])->values(),
            'due' => [
                'today' => (int) ($r['due']->today ?? 0),
                'recent' => (int) ($r['due']->recent ?? 0),
                'older' => (int) ($r['due']->older ?? 0),
                'unscheduled' => (int) ($r['due']->unscheduled ?? 0),
            ],
            'counsellors' => $r['counsellors']->map(fn ($row) => $outcome($row, 'counsellor'))->values(),
            'campaigns' => $r['campaigns']->map(fn ($row) => $outcome($row, 'source'))->values(),
            'collections' => $r['collections'] === null ? null : $r['collections']->map(fn ($row) => ['currency' => $row->currency, 'netPaise' => (int) $row->net_paise])->values(),
        ]);
    }
}
