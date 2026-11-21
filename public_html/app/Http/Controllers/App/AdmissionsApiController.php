<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Admissions, SalesPipeline, TeamPermissions};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for the Admissions CRM (page "admissions"). Every lead query goes
 * through Admissions::visibleLeads(), so sales users only ever see the leads
 * they own; stages come from SalesPipeline::expression() and contact links from
 * SalesPipeline::contact().
 */
class AdmissionsApiController extends Controller
{
    private const SORTS = ['enquiry_date' => 'l.enquiry_date', 'name' => 'l.name', 'next_follow_up' => 'l.next_follow_up_on', 'updated' => 'l.updated_at'];
    private const ACTIONS = ['create-lead', 'update-lead', 'followup', 'reminder', 'complete-reminder', 'convert', 'schedule-demo', 'update-demo', 'transfer', 'resolve-transfer'];

    private function context(Request $request, int $unit): array
    {
        $business = DB::table('erp_units')->find($unit);
        $permissions = new TeamPermissions();
        $user = $request->user();
        // Archived teams and the Sheets read-only period accept no writes.
        $readOnly = (bool) $business->archived_at || config('sheet_transition.authority') === 'sheets';
        $can = [];
        foreach (self::ACTIONS as $action) {
            $can[lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action))))] = !$readOnly && $permissions->allows($user, $unit, 'action', 'admission-'.$action);
        }
        $can['manage'] = in_array($permissions->role($user, $unit), ['superadmin', 'admin', 'staff', 'team_manager'], true);
        $can['openStudent'] = $permissions->allows($user, $unit, 'detail', 'all');
        $can['matching'] = $permissions->allows($user, $unit, 'page', 'allocation');
        return [$business, CarbonImmutable::now($business->timezone)->toDateString(), $can];
    }

    private function lead(object $l, string $today): array
    {
        $contact = SalesPipeline::contact($l);
        $due = $l->status !== 'LEAD' ? 'closed' : (!$l->next_follow_up_on ? 'unscheduled' : ($l->next_follow_up_on < $today ? 'overdue' : ($l->next_follow_up_on === $today ? 'today' : 'upcoming')));
        return [
            'id' => (int) $l->id,
            'name' => SalesPipeline::name($l),
            'grade' => $l->grade,
            'subject' => $l->subject,
            'syllabus' => $l->syllabus,
            'source' => $l->source,
            'status' => $l->status,
            'stage' => $l->crm_stage,
            'stageLabel' => SalesPipeline::STAGES[$l->crm_stage] ?? $l->crm_stage,
            'isHot' => (bool) $l->is_hot,
            'enquiryDate' => $l->enquiry_date,
            'nextFollowUpOn' => $l->next_follow_up_on,
            'followUpState' => $due,
            'interestStatus' => $l->interest_status,
            'assignedName' => $l->assigned_name,
            'phone' => $l->phone,
            'dial' => $contact['dial'],
            'whatsapp' => $contact['whatsapp'],
        ];
    }

    private function pageMeta($page, string $sort, string $dir): array
    {
        return ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir];
    }

    public function index(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'stage' => 'nullable|in:'.implode(',', array_keys(SalesPipeline::STAGES)),
            'status' => 'nullable|in:LEAD,JOINED,LOST',
            'followup' => 'nullable|in:today,overdue,unscheduled',
            'hot' => 'nullable|boolean',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        [$business, $today, $can] = $this->context($request, $unit);
        $actor = (int) $request->user()->id;
        $admissions = new Admissions();
        $stageSql = SalesPipeline::expression();

        $stageCounts = array_fill_keys(array_keys(SalesPipeline::STAGES), 0);
        foreach ($admissions->visibleLeads($unit, $actor)->reorder()->select([])->selectRaw($stageSql.' AS stage, COUNT(*) AS aggregate')->groupByRaw($stageSql)->get() as $row) $stageCounts[$row->stage] = (int) $row->aggregate;
        $followupCounts = $admissions->visibleLeads($unit, $actor)->reorder()->where('l.status', 'LEAD')->select([])
            ->selectRaw('SUM(CASE WHEN l.next_follow_up_on = ? THEN 1 ELSE 0 END) AS today', [$today])
            ->selectRaw('SUM(CASE WHEN l.next_follow_up_on < ? THEN 1 ELSE 0 END) AS overdue', [$today])
            ->selectRaw('SUM(CASE WHEN l.next_follow_up_on IS NULL THEN 1 ELSE 0 END) AS unscheduled')->first();

        $query = $admissions->visibleLeads($unit, $actor, $v['q'] ?? null, $v['status'] ?? null)->selectRaw($stageSql.' AS crm_stage');
        if (!empty($v['stage'])) $query->whereRaw($stageSql.' = ?', [$v['stage']]);
        if (!empty($v['hot'])) $query->where('l.is_hot', true);
        if (!empty($v['followup'])) {
            $query->where('l.status', 'LEAD');
            if ($v['followup'] === 'unscheduled') $query->whereNull('l.next_follow_up_on');
            else $query->where('l.next_follow_up_on', $v['followup'] === 'today' ? '=' : '<', $today);
        }
        $defaultSort = !empty($v['followup']) && $v['followup'] !== 'unscheduled' ? 'next_follow_up' : 'enquiry_date';
        $sort = $v['sort'] ?? $defaultSort;
        $dir = $v['dir'] ?? ($sort === 'enquiry_date' || $sort === 'updated' ? 'desc' : 'asc');
        $query->reorder()->orderBy(DB::raw(self::SORTS[$sort]), $dir)->orderByDesc('l.is_hot')->orderByDesc('l.id');
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));

        $transfers = DB::table('erp_lead_transfers')->where('unit_id', $unit)->where('status', 'pending');
        if (!$can['manage']) $transfers->where(fn ($q) => $q->where('from_user_id', $actor)->orWhere('to_user_id', $actor));
        $month = CarbonImmutable::now($business->timezone);

        return response()->json([
            'data' => collect($page->items())->map(fn ($l) => $this->lead($l, $today))->values(),
            'meta' => $this->pageMeta($page, $sort, $dir),
            'stages' => collect(SalesPipeline::STAGES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label, 'count' => $stageCounts[$key]])->values(),
            'totals' => [
                'all' => array_sum($stageCounts),
                'active' => array_sum($stageCounts) - $stageCounts['joined'] - $stageCounts['lost'],
                'dueToday' => (int) $followupCounts->today,
                'overdue' => (int) $followupCounts->overdue,
                'unscheduled' => (int) $followupCounts->unscheduled,
                'joinedThisMonth' => $admissions->visibleLeads($unit, $actor)->reorder()->where('l.status', 'JOINED')->whereBetween('l.joined_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])->count(),
                'pendingTransfers' => $transfers->count(),
                'remindersDue' => DB::table('erp_lead_reminders')->where('unit_id', $unit)->whereIn('lead_id', $admissions->visibleLeads($unit, $actor)->reorder()->select('l.id'))->where('is_completed', false)->where('due_on', '<=', $today)->count(),
            ],
            'can' => $can,
            'today' => $today,
        ]);
    }

    public function show(Request $request, int $unit, int $lead)
    {
        [$business, $today, $can] = $this->context($request, $unit);
        $actor = (int) $request->user()->id;
        $l = (new Admissions())->visibleLeads($unit, $actor)->selectRaw(SalesPipeline::expression().' AS crm_stage')->where('l.id', $lead)->first();
        abort_unless($l, 404);

        $activities = DB::table('erp_lead_activities as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.unit_id', $unit)->where('a.lead_id', $l->id)
            ->orderByDesc('a.occurred_at')->orderByDesc('a.id')->limit(50)
            ->get(['a.id', 'a.kind', 'a.channel', 'a.outcome', 'a.summary', 'a.metadata', 'a.occurred_at', 'u.name as actor_name'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'kind' => $a->kind, 'channel' => $a->channel, 'outcome' => $a->outcome, 'summary' => $a->summary, 'details' => $this->activityDetails($a->metadata), 'occurredAt' => $a->occurred_at, 'actorName' => $a->actor_name])->values();
        $demos = DB::table('erp_demos as d')->leftJoin('erp_teachers as t', 't.id', '=', 'd.teacher_id')->leftJoin('erp_subjects as s', 's.id', '=', 'd.subject_id')
            ->where('d.unit_id', $unit)->where('d.lead_id', $l->id)->orderByDesc('d.scheduled_on')->orderByDesc('d.id')->limit(20)
            ->get(['d.id', 'd.scheduled_on', 'd.scheduled_time', 'd.status', 'd.meet_link', 'd.starts_at', 'd.feedback_outcome', 'd.feedback_note', 't.name as teacher_name', 's.name as subject_name'])
            ->map(fn ($d) => $this->demo($d))->values();
        $reminders = DB::table('erp_lead_reminders')->where('unit_id', $unit)->where('lead_id', $l->id)->where('is_completed', false)
            ->orderBy('due_on')->orderBy('due_time')->get(['id', 'due_on', 'due_time', 'text'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'dueOn' => $r->due_on, 'dueTime' => $r->due_time, 'text' => $r->text, 'isOverdue' => $r->due_on < $today])->values();
        $transfers = $this->transferQuery($unit, $actor, $can['manage'])->where('x.lead_id', $l->id)->orderByDesc('x.id')->limit(10)->get()
            ->map(fn ($x) => $this->transfer($x, $actor, $can))->values();
        $members = $can['transfer'] ? DB::table('users as u')->join('erp_memberships as m', 'm.user_id', '=', 'u.id')
            ->where('m.unit_id', $unit)->whereIn('m.role', ['sales_followups', 'team_manager', 'admin', 'staff'])
            ->where('u.status', 1)->whereNull('u.deleted_at')->where('u.id', '!=', $actor)->orderBy('u.name')->get(['u.id', 'u.name'])
            ->map(fn ($m) => ['value' => (string) $m->id, 'label' => $m->name])->values() : [];

        return response()->json([
            'lead' => $this->lead($l, $today) + [
                'rawName' => $l->name,
                'email' => $l->email,
                'country' => $l->country,
                'studentPhone' => $l->student_phone,
                'notes' => $l->notes,
                'lostReason' => $l->lost_reason,
                'joinedOn' => $l->joined_on,
                'admissionCode' => $l->admission_code,
                'studentId' => $l->student_id ? (int) $l->student_id : null,
                'studentCode' => $l->canonical_student_code,
                'learningRequirement' => $l->learning_requirement,
                'concern' => $l->concern,
                'aboutStudent' => $l->about_student,
                'aboutParent' => $l->about_parent,
                'awaitingAllocation' => Admissions::awaitingAllocation($unit, (int) $l->id),
                'joinedMessage' => $l->status === 'JOINED' ? SalesPipeline::whatsappMessage(null, SalesPipeline::joinedMessage($l, $business)) : null,
            ],
            'activities' => $activities,
            'demos' => $demos,
            'reminders' => $reminders,
            'transfers' => $transfers,
            'members' => $members,
            'can' => $can,
            'today' => $today,
            'timezone' => $business->timezone,
        ]);
    }

    /** Activity metadata keys shown as "Recorded details"; anything else (internal ids) stays on the server. */
    private const ACTIVITY_DETAILS = ['source' => 'Source', 'enquiry_date' => 'Enquiry date', 'external_id' => 'CRM reference', 'status' => 'Lead status', 'next_due_on' => 'Next follow-up', 'admission_code' => 'Admission code'];
    /** Lead fields whose edits are shown from a "changes" record; ids such as assigned_to are left out. */
    private const CHANGE_FIELDS = ['name' => 'Student name', 'phone' => 'Parent phone', 'student_phone' => 'Student phone', 'email' => 'Email', 'country' => 'Country', 'grade' => 'Class', 'subject' => 'Subject', 'syllabus' => 'Syllabus', 'source' => 'Source', 'enquiry_date' => 'Enquiry date', 'status' => 'Status', 'interest_status' => 'Interest', 'next_follow_up_on' => 'Next follow-up', 'lost_reason' => 'Lost reason', 'is_hot' => 'Hot lead', 'notes' => 'Notes', 'learning_requirement' => 'Learning needs', 'concern' => 'Parent concerns', 'about_student' => 'About the student', 'about_parent' => 'Parent preferences'];

    /** Whitelisted, display-ready details from erp_lead_activities.metadata: [{label, value}]. */
    private function activityDetails(?string $metadata): array
    {
        $data = $metadata ? json_decode($metadata, true) : null;
        if (!is_array($data)) return [];
        $text = fn ($value) => is_scalar($value) && trim((string) $value) !== '' ? (is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value) : null;
        $details = [];
        foreach (self::ACTIVITY_DETAILS as $key => $label) {
            $value = $text($data[$key] ?? null);
            if ($value !== null) $details[] = ['label' => $label, 'value' => $value];
        }
        foreach (is_array($data['changes'] ?? null) ? $data['changes'] : [] as $field => $change) {
            if (!isset(self::CHANGE_FIELDS[$field]) || !is_array($change)) continue;
            $details[] = ['label' => self::CHANGE_FIELDS[$field], 'value' => ($text($change['from'] ?? null) ?? '—').' → '.($text($change['to'] ?? null) ?? '—')];
        }
        return $details;
    }

    private function demo(object $d): array
    {
        return [
            'id' => (int) $d->id, 'scheduledOn' => $d->scheduled_on, 'scheduledTime' => $d->scheduled_time, 'status' => $d->status,
            'meetLink' => $d->meet_link && preg_match('#^https?://#i', $d->meet_link) ? $d->meet_link : null,
            // Demos booked through Teacher matching are managed there (cancel and rebook, feedback).
            'isMatched' => !empty($d->starts_at), 'feedbackOutcome' => $d->feedback_outcome ?? null, 'feedbackNote' => $d->feedback_note ?? null,
            'teacherName' => $d->teacher_name, 'subjectName' => $d->subject_name,
        ];
    }

    /**
     * Call and WhatsApp links for a demo: the parent's WhatsApp always
     * (without an international number the sender picks the chat), Call only with a number, and the teacher's
     * links only when the demo has a teacher with a phone in their recruitment record.
     */
    private function demoContacts(object $d, object $business): array
    {
        $parent = SalesPipeline::contact($d);
        $teacher = SalesPipeline::teacherContact($d->teacher_phone ?? null);
        $hasTeacher = !empty($d->teacher_id);
        return [
            'parentDial' => $parent['dial'] !== '' ? $parent['dial'] : null,
            'parentWhatsapp' => SalesPipeline::whatsappMessage($parent['whatsapp'], SalesPipeline::demoParentMessage($d, $business)),
            'hasTeacher' => $hasTeacher,
            'teacherDial' => $hasTeacher ? $teacher['dial'] : null,
            'teacherWhatsapp' => $hasTeacher && $teacher['dial'] ? SalesPipeline::whatsappMessage($teacher['whatsapp'], SalesPipeline::demoTeacherMessage($d, $business)) : null,
            'isUpcoming' => in_array($d->status, ['SCHEDULED', 'RESCHEDULED'], true),
        ];
    }

    private function transferQuery(int $unit, int $actor, bool $manage)
    {
        // Recipients may review a transfer invitation before they gain access to the lead itself.
        $q = DB::table('erp_lead_transfers as x')->join('erp_leads as l', function ($join) { $join->on('l.id', '=', 'x.lead_id')->on('l.unit_id', '=', 'x.unit_id'); })
            ->leftJoin('erp_students as s', function ($join) { $join->on('s.id', '=', 'l.student_id')->on('s.unit_id', '=', 'l.unit_id'); })
            ->leftJoin('users as sender', 'sender.id', '=', 'x.from_user_id')->leftJoin('users as recipient', 'recipient.id', '=', 'x.to_user_id')
            ->where('x.unit_id', $unit)->where('x.status', 'pending')
            ->select('x.id', 'x.lead_id', 'x.from_user_id', 'x.to_user_id', 'x.created_at', 'l.name as lead_name', 's.name as canonical_student_name', 'sender.name as sender_name', 'recipient.name as recipient_name');
        if (!$manage) $q->where(fn ($w) => $w->where('x.from_user_id', $actor)->orWhere('x.to_user_id', $actor));
        return $q;
    }

    private function transfer(object $x, int $actor, array $can): array
    {
        $resolve = $can['resolveTransfer'];
        return [
            'id' => (int) $x->id, 'leadId' => (int) $x->lead_id, 'leadName' => SalesPipeline::name($x, 'lead_name'),
            'fromName' => $x->sender_name, 'toName' => $x->recipient_name, 'createdAt' => $x->created_at,
            'canAccept' => $resolve && ($can['manage'] || (int) $x->to_user_id === $actor),
            'canDecline' => $resolve && ($can['manage'] || (int) $x->to_user_id === $actor),
            'canCancel' => $resolve && ($can['manage'] || (int) $x->from_user_id === $actor),
        ];
    }

    public function demos(Request $request, int $unit)
    {
        $v = $request->validate(['q' => 'nullable|string|max:100', 'scope' => 'nullable|in:upcoming,all', 'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|in:25,50,100']);
        [$business, $today, $can] = $this->context($request, $unit);
        $visible = (new Admissions())->visibleLeads($unit, (int) $request->user()->id)->reorder()->select('l.id');
        $query = DB::table('erp_demos as d')->join('erp_leads as l', function ($join) { $join->on('l.id', '=', 'd.lead_id')->on('l.unit_id', '=', 'd.unit_id'); })
            ->leftJoin('erp_students as st', function ($join) { $join->on('st.id', '=', 'l.student_id')->on('st.unit_id', '=', 'l.unit_id'); })
            ->leftJoin('erp_teachers as t', 't.id', '=', 'd.teacher_id')->leftJoin('erp_subjects as s', 's.id', '=', 'd.subject_id')
            ->leftJoinSub(SalesPipeline::teacherPhones(), 'tp', 'tp.teacher_id', '=', 'd.teacher_id')
            ->where('d.unit_id', $unit)->whereIn('d.lead_id', $visible)
            // Every column the demo WhatsApp messages read (SalesPipeline::demoParentMessage/demoTeacherMessage).
            ->select('d.id', 'd.lead_id', 'd.teacher_id', 'd.scheduled_on', 'd.scheduled_time', 'd.status', 'd.meet_link', 'd.starts_at', 'd.timezone', 'd.duration_minutes', 'd.matching_grade', 'd.matching_syllabus', 'd.matching_medium', 'd.feedback_outcome', 'd.feedback_note',
                'l.name as lead_name', 'l.phone', 'l.country', 'l.grade as lead_grade', 'l.syllabus as lead_syllabus', 'st.name as canonical_student_name', 't.name as teacher_name', 'tp.phone as teacher_phone', 's.name as subject_name');
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('l.name', 'like', '%'.$search.'%')->orWhere('l.phone', 'like', '%'.$search.'%'));
        $scope = $v['scope'] ?? 'upcoming';
        if ($scope === 'upcoming') $query->whereIn('d.status', ['SCHEDULED', 'RESCHEDULED'])->where('d.scheduled_on', '>=', $today)->orderBy('d.scheduled_on')->orderBy('d.scheduled_time');
        else $query->orderByDesc('d.scheduled_on')->orderByDesc('d.id');
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        return response()->json([
            'data' => collect($page->items())->map(fn ($d) => $this->demo($d) + ['leadId' => (int) $d->lead_id, 'leadName' => SalesPipeline::name($d, 'lead_name')] + $this->demoContacts($d, $business))->values(),
            'meta' => $this->pageMeta($page, 'scheduled_on', $scope === 'upcoming' ? 'asc' : 'desc'),
            'can' => $can,
            'today' => $today,
        ]);
    }

    public function reminders(Request $request, int $unit)
    {
        $v = $request->validate(['q' => 'nullable|string|max:100', 'scope' => 'nullable|in:due,all', 'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|in:25,50,100']);
        [, $today, $can] = $this->context($request, $unit);
        $visible = (new Admissions())->visibleLeads($unit, (int) $request->user()->id)->reorder()->select('l.id');
        $query = DB::table('erp_lead_reminders as r')->join('erp_leads as l', function ($join) { $join->on('l.id', '=', 'r.lead_id')->on('l.unit_id', '=', 'r.unit_id'); })
            ->leftJoin('erp_students as st', function ($join) { $join->on('st.id', '=', 'l.student_id')->on('st.unit_id', '=', 'l.unit_id'); })
            ->where('r.unit_id', $unit)->whereIn('r.lead_id', $visible)->where('r.is_completed', false)
            ->select('r.id', 'r.lead_id', 'r.due_on', 'r.due_time', 'r.text', 'l.name as lead_name', 'st.name as canonical_student_name')
            ->orderBy('r.due_on')->orderBy('r.due_time')->orderBy('r.id');
        if (($v['scope'] ?? 'due') === 'due') $query->where('r.due_on', '<=', $today);
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('l.name', 'like', '%'.$search.'%')->orWhere('r.text', 'like', '%'.$search.'%'));
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => ['id' => (int) $r->id, 'leadId' => (int) $r->lead_id, 'leadName' => SalesPipeline::name($r, 'lead_name'), 'dueOn' => $r->due_on, 'dueTime' => $r->due_time, 'text' => $r->text, 'isOverdue' => $r->due_on < $today])->values(),
            'meta' => $this->pageMeta($page, 'due_on', 'asc'),
            'can' => $can,
            'today' => $today,
        ]);
    }

    public function transfers(Request $request, int $unit)
    {
        $v = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|in:25,50,100']);
        [, $today, $can] = $this->context($request, $unit);
        $actor = (int) $request->user()->id;
        $query = $this->transferQuery($unit, $actor, $can['manage'])->orderByDesc('x.id');
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where('l.name', 'like', '%'.$search.'%');
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => $this->transfer($x, $actor, $can))->values(),
            'meta' => $this->pageMeta($page, 'created_at', 'desc'),
            'can' => $can,
            'today' => $today,
        ]);
    }
}
