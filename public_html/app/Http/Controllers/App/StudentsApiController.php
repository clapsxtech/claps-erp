<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{IndianStates, People, StudentProfile, TeamPermissions};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for the Students screens. Access is enforced by TeamAuthorization
 * (page "people" for the list, "detail" for a profile) before these run. Every
 * response lists its fields explicitly; nothing is passed through with select *.
 */
class StudentsApiController extends Controller
{
    private const SORTS = ['name' => 's.name', 'code' => 's.code', 'registered_on' => 's.registered_on', 'grade' => 's.grade', 'balance' => 'balance_paise', 'status' => 's.status'];

    public function index(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,stopped,paused,inactive',
            'priority' => 'nullable|in:super,high,medium',
            'due' => 'nullable|boolean',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $business = DB::table('erp_units')->find($unit);
        $permissions = new TeamPermissions();
        $user = $request->user();
        // Balances are for money roles, and
        // for sales, who answer parents' "how much do I owe?" questions.
        $canSeeBalance = $permissions->allows($user, $unit, 'page', 'money') || $permissions->role($user, $unit) === 'sales_followups';
        $canOpenProfile = $permissions->allows($user, $unit, 'detail', 'all');

        $balances = DB::table('erp_ledger')->where('unit_id', $unit)->whereNotNull('student_id')->whereNull('teacher_id')
            ->select('student_id', DB::raw('SUM(amount_paise) AS balance'))->groupBy('student_id');
        $teachers = DB::table('erp_enrollments as e')->join('erp_teachers as t', 't.id', '=', 'e.teacher_id')
            ->where('e.unit_id', $unit)->where('e.status', 'active')
            ->select('e.student_id', DB::raw('COUNT(*) AS active_classes'))->groupBy('e.student_id');
        $query = DB::table('erp_students as s')
            ->leftJoinSub($balances, 'b', 's.id', '=', 'b.student_id')
            ->leftJoinSub($teachers, 'c', 's.id', '=', 'c.student_id')
            ->where('s.unit_id', $unit)
            ->select('s.id', 's.code', 's.name', 's.grade', 's.syllabus', 's.status', 's.registered_on', DB::raw('COALESCE(b.balance,0) AS balance_paise'), DB::raw('COALESCE(c.active_classes,0) AS active_classes'));

        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('s.name', 'like', '%'.$search.'%')->orWhere('s.code', 'like', '%'.$search.'%'));
        }
        if (!empty($v['status'])) $query->where('s.status', $v['status']);
        if (!empty($v['due']) && $canSeeBalance) $query->whereRaw('COALESCE(b.balance,0) < 0');
        if (!empty($v['priority'])) {
            $month = CarbonImmutable::now($business->timezone)->startOfMonth();
            match ($v['priority']) {
                'super' => $query->where('s.registered_on', '>=', $month->toDateString()),
                'high' => $query->whereBetween('s.registered_on', [$month->subMonth()->toDateString(), $month->subDay()->toDateString()]),
                'medium' => $query->where('s.registered_on', '<', $month->subMonth()->toDateString()),
            };
        }
        $sort = $v['sort'] ?? 'name';
        if ($sort === 'balance' && !$canSeeBalance) $sort = 'name';
        $query->orderBy(DB::raw(self::SORTS[$sort]), $v['dir'] ?? 'asc')->orderBy('s.id');

        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $people = new People();
        $rows = collect($page->items())->map(fn ($row) => [
            'id' => (int) $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'grade' => $row->grade,
            'syllabus' => $row->syllabus,
            'status' => $row->status,
            'registeredOn' => $row->registered_on,
            'priority' => $people->priority($row->registered_on, $business->timezone),
            'activeClasses' => (int) $row->active_classes,
            'balancePaise' => $canSeeBalance ? (int) $row->balance_paise : null,
        ]);
        $totals = $canSeeBalance ? [
            'dueCount' => DB::query()->fromSub($balances, 'x')->where('balance', '<', 0)->count(),
            'duePaise' => (int) DB::query()->fromSub($balances, 'x')->where('balance', '<', 0)->sum('balance'),
        ] : null;
        return response()->json([
            'data' => $rows,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $v['dir'] ?? 'asc'],
            'can' => ['seeBalance' => $canSeeBalance, 'openProfile' => $canOpenProfile],
            'totals' => $totals,
        ]);
    }

    /** Paginated profile sections and their page sizes (StudentProfile::read). */
    private const SECTIONS = ['assignments', 'agreements', 'lessons', 'attendance', 'ledger', 'requests', 'followups', 'tasks'];

    public function show(Request $request, int $unit, int $student)
    {
        // Each section pages on its own; malformed page numbers are refused with 422.
        $request->validate(collect(self::SECTIONS)->mapWithKeys(fn ($section) => [$section.'_page' => 'nullable|integer|min:1|max:1000000'])->all());
        $p = (new StudentProfile())->read($unit, $student);
        $s = $p['student'];
        $permissions = new TeamPermissions();
        $user = $request->user();
        // Rates are money: shown to roles with the money page only.
        $canSeeMoney = $permissions->allows($user, $unit, 'page', 'money');
        $canUpdate = $permissions->allows($user, $unit, 'action', 'student-update');
        $page = fn ($paginator, array $fields) => collect($paginator->items())->map(fn ($row) => collect($fields)->mapWithKeys(fn ($f) => [$f => $row->$f ?? null])->all())->values();
        $sheet = $p['sheetProfile'];
        $sheetFields = ['section' => 'section', 'country' => 'country', 'whatsapp_number' => 'whatsappNumber', 'student_contact' => 'studentContact', 'email' => 'email', 'father_name' => 'fatherName', 'mother_name' => 'motherName', 'school' => 'school', 'preferred_medium' => 'preferredMedium', 'preferred_time' => 'preferredTime', 'network_connectivity' => 'networkConnectivity', 'source' => 'source', 'source_external_id' => 'sourceExternalId'];
        $assignedTo = $p['labels']['assigned_to'];
        return response()->json([
            'student' => [
                'id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name, 'grade' => $s->grade, 'syllabus' => $s->syllabus,
                'status' => $s->status, 'registeredOn' => $s->registered_on, 'timezone' => $s->timezone,
                'guardianContact' => $s->guardian_contact, 'meetingUrl' => RecordsDatasets::safeLink($s->meeting_url),
                // GST place of supply when it differs from the team's state.
                'billingState' => $sheet->billing_state ?? null,
            ],
            'sheetProfile' => $sheet ? collect($sheetFields)->mapWithKeys(fn ($key, $column) => [$key => $sheet->$column])->all()
                + ['hourRatePaise' => $canSeeMoney && $sheet->hour_rate_paise !== null ? (int) $sheet->hour_rate_paise : null] : null,
            'admissionHandoffs' => $p['admissionHandoffs']->map(fn ($h) => [
                'id' => (int) $h->id, 'source' => $h->source, 'joinedOn' => $h->joined_on,
                'learningRequirement' => $h->learning_requirement, 'concern' => $h->concern,
                'aboutStudent' => $h->about_student, 'aboutParent' => $h->about_parent,
            ])->values(),
            'balances' => $p['balances']->map(fn ($b) => ['currency' => $b->currency, 'balancePaise' => (int) $b->balance_paise])->values(),
            'assignments' => collect($p['assignments']->items())->map(fn ($a) => [
                'id' => (int) $a->id, 'subject_name' => $a->subject_name, 'teacher_name' => $a->teacher_name,
                'starts_on' => $a->starts_on, 'ends_on' => $a->ends_on, 'status' => $a->status,
                'studentRatePaise' => $canSeeMoney ? (int) $a->student_rate_paise : null,
            ])->values(),
            'agreements' => $page($p['agreements'], ['id', 'subject_name', 'teacher_name', 'weekday', 'start_time', 'duration_minutes', 'timezone', 'status']),
            'lessons' => $page($p['lessons'], ['id', 'starts_at', 'subject_name', 'teacher_name', 'kind', 'status']),
            'attendance' => $page($p['attendance'], ['id', 'starts_at', 'subject_name', 'teacher_name', 'actual_minutes', 'status']),
            'ledger' => $page($p['ledger'], ['id', 'occurred_at', 'kind', 'reference', 'amount_paise', 'currency', 'reverses_id']),
            // A request is not a receipt: it never changes the balance.
            'requests' => collect($p['requests']->items())->map(fn ($r) => [
                'id' => (int) $r->id, 'reference' => $r->reference, 'requestedOn' => $r->requested_on, 'dueOn' => $r->due_on,
                'requestedPaise' => (int) $p['requestSummaries'][$r->id]['requested_paise'],
                'coveredPaise' => (int) $p['requestSummaries'][$r->id]['covered_paise'],
                'outstandingPaise' => (int) $p['requestSummaries'][$r->id]['outstanding_paise'],
                'status' => $p['requestSummaries'][$r->id]['status'],
            ])->values(),
            'followups' => $page($p['followups'], ['id', 'created_at', 'contact_channel', 'note', 'outcome', 'next_due_on']),
            // Assignee names only for current members of this unit.
            'tasks' => collect($p['tasks']->items())->map(fn ($t) => [
                'id' => (int) $t->id, 'title' => $t->title, 'status' => $t->status, 'due_on' => $t->due_on, 'notes' => $t->notes,
                'assignedTo' => $t->assigned_to ? ($assignedTo[$t->assigned_to] ?? 'Unassigned or unavailable account') : null,
            ])->values(),
            'pages' => collect(self::SECTIONS)->mapWithKeys(fn ($section) => [$section => [
                'page' => $p[$section]->currentPage(), 'perPage' => $p[$section]->perPage(), 'total' => $p[$section]->total(), 'param' => $section.'_page',
            ]])->all(),
            'states' => $canUpdate ? IndianStates::names() : null,
            'can' => ['seeMoney' => $canSeeMoney, 'updateStudent' => $canUpdate],
        ]);
    }
}
