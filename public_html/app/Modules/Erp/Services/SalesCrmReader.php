<?php

namespace App\Modules\Erp\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the sales app API. The queries mirror AdmissionsController::index
 * so the sales app and the ERP's Sales CRM screen show the same leads to the same
 * people: visibility always comes from Admissions::visibleLeads().
 */
class SalesCrmReader
{
    private Admissions $admissions;

    public function __construct(private int $unit, private User $user)
    {
        $this->admissions = new Admissions();
    }

    public static function page(LengthAwarePaginator $page): array
    {
        return ['data' => array_values($page->items()), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()];
    }

    private function business(): object
    {
        return DB::table('erp_units')->where('id', $this->unit)->first(['id', 'code', 'name', 'timezone']);
    }

    private function today(): string
    {
        return now($this->business()->timezone ?: 'UTC')->toDateString();
    }

    private function visible()
    {
        return $this->admissions->visibleLeads($this->unit, (int) $this->user->id);
    }

    private function visibleIds()
    {
        return $this->visible()->reorder()->select('l.id');
    }

    public function canManage(): bool
    {
        return in_array((new TeamPermissions())->role($this->user, $this->unit), ['superadmin', 'admin', 'staff', 'team_manager'], true);
    }

    public function overview(): array
    {
        $today = $this->today();
        $business = $this->business();
        $stageSql = SalesPipeline::expression();
        $stages = array_fill_keys(array_keys(SalesPipeline::STAGES), 0);
        foreach ($this->visible()->reorder()->select([])->selectRaw($stageSql.' AS stage, COUNT(*) AS aggregate')->groupByRaw($stageSql)->get() as $row) $stages[$row->stage] = (int) $row->aggregate;
        $followups = $this->visible()->reorder()->where('l.status', 'LEAD')->select([])
            ->selectRaw('SUM(CASE WHEN l.next_follow_up_on = ? THEN 1 ELSE 0 END) AS today', [$today])
            ->selectRaw('SUM(CASE WHEN l.next_follow_up_on < ? THEN 1 ELSE 0 END) AS overdue', [$today])
            ->selectRaw('SUM(CASE WHEN l.next_follow_up_on IS NULL THEN 1 ELSE 0 END) AS unscheduled')->first();
        $monthStart = now($business->timezone ?: 'UTC')->startOfMonth()->toDateString();
        $monthEnd = now($business->timezone ?: 'UTC')->endOfMonth()->toDateString();
        $upcomingDemos = DB::table('erp_demos')->where('unit_id', $this->unit)->whereIn('lead_id', $this->visibleIds())
            ->whereIn('status', ['SCHEDULED', 'RESCHEDULED'])->where('scheduled_on', '>=', $today)->count();
        $dueReminders = DB::table('erp_lead_reminders')->where('unit_id', $this->unit)->whereIn('lead_id', $this->visibleIds())
            ->where('is_completed', false)->where('due_on', '<=', $today)->count();

        return [
            'business' => $business,
            'today' => $today,
            'stage_labels' => SalesPipeline::STAGES,
            'stages' => $stages,
            'followups' => ['today' => (int) $followups->today, 'overdue' => (int) $followups->overdue, 'unscheduled' => (int) $followups->unscheduled],
            'active_leads' => array_sum($stages) - $stages['joined'] - $stages['lost'],
            'joined_this_month' => $this->visible()->where('l.status', 'JOINED')->whereBetween('l.joined_on', [$monthStart, $monthEnd])->count(),
            'upcoming_demos' => $upcomingDemos,
            'due_reminders' => $dueReminders,
            'pending_transfers' => $this->transferQuery()->count(),
            'can_manage' => $this->canManage(),
        ];
    }

    public function leads(array $filters): array
    {
        $today = $this->today();
        $stageSql = SalesPipeline::expression();
        $query = $this->admissions->visibleLeads($this->unit, (int) $this->user->id, $filters['q'] ?? null, $filters['status'] ?? null)->selectRaw($stageSql.' AS crm_stage');
        if (!empty($filters['stage'])) $query->whereRaw($stageSql.' = ?', [$filters['stage']]);
        if (!empty($filters['hot'])) $query->where('l.is_hot', true);
        if (!empty($filters['followup'])) {
            $query->where('l.status', 'LEAD');
            if ($filters['followup'] === 'unscheduled') $query->whereNull('l.next_follow_up_on');
            else $query->where('l.next_follow_up_on', $filters['followup'] === 'today' ? '=' : '<', $today);
            $query->reorder()->orderBy('l.next_follow_up_on')->orderByDesc('l.is_hot')->orderBy('l.id');
        }

        return self::page($query->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1)));
    }

    public function lead(int $leadId): ?array
    {
        $lead = $this->visible()->selectRaw(SalesPipeline::expression().' AS crm_stage')->where('l.id', $leadId)->first();
        if (!$lead) return null;
        $activities = DB::table('erp_lead_activities as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.unit_id', $this->unit)->where('a.lead_id', $lead->id)->select('a.*', 'u.name as actor_name')
            ->orderByDesc('a.occurred_at')->orderByDesc('a.id')->limit(100)->get();
        $demos = DB::table('erp_demos as d')->leftJoin('erp_teachers as t', 't.id', '=', 'd.teacher_id')->leftJoin('erp_subjects as s', 's.id', '=', 'd.subject_id')
            ->leftJoinSub(SalesPipeline::teacherPhones(), 'tp', 'tp.teacher_id', '=', 'd.teacher_id')
            ->where('d.unit_id', $this->unit)->where('d.lead_id', $lead->id)
            ->select('d.*', 't.name as teacher_name', 'tp.phone as teacher_phone', 's.name as subject_name')
            ->orderByDesc('d.scheduled_on')->orderByDesc('d.id')->limit(50)->get();
        $reminders = DB::table('erp_lead_reminders')->where('unit_id', $this->unit)->where('lead_id', $lead->id)
            ->orderBy('is_completed')->orderBy('due_on')->orderBy('due_time')->limit(50)->get();
        $transfers = $this->transferQuery()->where('x.lead_id', $lead->id)
            ->select('x.*', 'l.name as lead_name', 'sender.name as sender_name', 'recipient.name as recipient_name')->orderByDesc('x.id')->get();

        return ['lead' => $lead, 'contact' => SalesPipeline::contact($lead), 'activities' => $activities, 'demos' => $demos, 'reminders' => $reminders, 'transfers' => $transfers];
    }

    public function demos(string $scope, int $page): array
    {
        $query = DB::table('erp_demos as d')
            ->join('erp_leads as l', function ($join) { $join->on('l.id', '=', 'd.lead_id')->on('l.unit_id', '=', 'd.unit_id'); })
            ->leftJoin('erp_teachers as t', 't.id', '=', 'd.teacher_id')->leftJoin('erp_subjects as s', 's.id', '=', 'd.subject_id')
            ->leftJoinSub(SalesPipeline::teacherPhones(), 'tp', 'tp.teacher_id', '=', 'd.teacher_id')
            ->where('d.unit_id', $this->unit)->whereIn('d.lead_id', $this->visibleIds())
            ->select('d.*', 'l.name as lead_name', 'l.phone', 'l.country', 'l.grade as lead_grade', 'l.syllabus as lead_syllabus', 't.name as teacher_name', 'tp.phone as teacher_phone', 's.name as subject_name');
        if ($scope === 'upcoming') $query->whereIn('d.status', ['SCHEDULED', 'RESCHEDULED'])->where('d.scheduled_on', '>=', $this->today())->orderBy('d.scheduled_on')->orderBy('d.scheduled_time');
        else $query->orderByDesc('d.scheduled_on')->orderByDesc('d.id');

        return self::page($query->paginate(20, ['*'], 'page', $page));
    }

    public function reminders(bool $dueOnly, int $page): array
    {
        $query = DB::table('erp_lead_reminders as r')->join('erp_leads as l', function ($join) { $join->on('l.id', '=', 'r.lead_id')->on('l.unit_id', '=', 'r.unit_id'); })
            ->where('r.unit_id', $this->unit)->whereIn('r.lead_id', $this->visibleIds())->where('r.is_completed', false)
            ->select('r.*', 'l.name as lead_name', 'l.phone')->orderBy('r.due_on')->orderBy('r.due_time')->orderBy('r.id');
        if ($dueOnly) $query->where('r.due_on', '<=', $this->today());

        return self::page($query->paginate(20, ['*'], 'page', $page));
    }

    /** Recipients may review a transfer invitation before they gain access to the lead itself. */
    private function transferQuery()
    {
        $query = DB::table('erp_lead_transfers as x')->join('erp_leads as l', function ($join) { $join->on('l.id', '=', 'x.lead_id')->on('l.unit_id', '=', 'x.unit_id'); })
            ->leftJoin('users as sender', 'sender.id', '=', 'x.from_user_id')->leftJoin('users as recipient', 'recipient.id', '=', 'x.to_user_id')
            ->where('x.unit_id', $this->unit)->where('x.status', 'pending');
        if (!$this->canManage()) {
            $actor = (int) $this->user->id;
            $query->where(fn ($q) => $q->where('x.from_user_id', $actor)->orWhere('x.to_user_id', $actor));
        }

        return $query;
    }

    public function transfers(int $page): array
    {
        return self::page($this->transferQuery()->select('x.*', 'l.name as lead_name', 'sender.name as sender_name', 'recipient.name as recipient_name')->orderByDesc('x.id')->paginate(20, ['*'], 'page', $page));
    }

    public function teachers(array $filters): array
    {
        $directory = (new TeacherDirectory())->list($this->unit, $filters, $this->business()->timezone ?: 'UTC');
        $subjects = DB::table('erp_subjects')->whereIn('id', DB::table('erp_eligibilities')->whereIn('teacher_id', DB::table('erp_teacher_units')->where('unit_id', $this->unit)->select('teacher_id'))->select('subject_id'))->orderBy('name')->get(['id', 'name']);
        $result = $directory instanceof LengthAwarePaginator ? self::page($directory) : ['data' => $directory];

        return $result + ['subjects' => $subjects, 'levels' => TeacherDirectory::LEVELS, 'categories' => TeacherDirectory::CATEGORIES];
    }

    public function members(): array
    {
        return DB::table('users as u')->join('erp_memberships as m', 'm.user_id', '=', 'u.id')
            ->where('m.unit_id', $this->unit)->whereIn('m.role', ['sales_followups', 'team_manager', 'admin', 'staff'])
            ->where('u.status', 1)->whereNull('u.deleted_at')->select('u.id', 'u.name')->distinct()->orderBy('u.name')->get()->all();
    }
}
