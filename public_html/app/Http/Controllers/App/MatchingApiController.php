<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Admissions, SalesPipeline, TeacherAllocation, TeacherDirectory, TeamPermissions};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for Teacher matching (page "allocation"). Learner context, lead
 * visibility, slot validation and teacher blockers all come from TeacherAllocation
 * and Admissions, the same services TeacherAllocationController uses, so sales
 * only ever see their own enquiries. Writes go to /erp/units/{unit}/business-actions/allocation-*.
 */
class MatchingApiController extends Controller
{
    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    private function learnerRules(): array
    {
        return ['student_id' => 'nullable|integer|min:1', 'lead_id' => 'nullable|integer|min:1', 'demo_id' => 'nullable|integer|min:1'];
    }

    private function slotRules(): array
    {
        return [
            'subject_id' => 'nullable|integer|min:1', 'teacher_q' => 'nullable|string|max:100', 'level' => 'nullable|in:LP,UP,HS',
            'category' => 'nullable|in:Standard,Advanced', 'sort' => 'nullable|in:code,registered_desc,registered_asc',
            'include_unverified' => 'nullable|boolean', 'date' => 'nullable|date_format:Y-m-d', 'time' => 'nullable|string|max:5',
            'timezone' => 'nullable|timezone', 'duration_minutes' => 'nullable|integer|min:5|max:180', 'medium' => 'nullable|string|max:100',
            'grade' => 'nullable|string|max:100', 'syllabus' => 'nullable|string|max:100', 'matches_page' => 'nullable|integer|min:1|max:100000',
        ];
    }

    /** Lead search, ready-to-allocate handoffs, and the chosen learner's context and demos. */
    public function index(Request $request, int $unit)
    {
        $p = $request->validate($this->learnerRules() + ['q' => 'nullable|string|max:100']);
        $user = $request->user();
        $service = new TeacherAllocation();
        $permissions = new TeamPermissions();
        $can = fn (string $action) => $permissions->allows($user, $unit, 'action', $action);
        $business = DB::table('erp_units')->find($unit);
        $tz = $business->timezone;

        $context = (!empty($p['student_id']) || !empty($p['lead_id'])) ? $service->context($unit, $user, $p) : null;
        $leads = (new Admissions())->visibleLeads($unit, $user->id, $p['q'] ?? null)->whereIn('l.status', ['LEAD', 'JOINED'])->limit(30)->get()
            ->map(fn ($lead) => ['id' => (int) $lead->id, 'name' => SalesPipeline::name($lead)])->values();

        // Only roles that can allocate see the handoff list.
        $handoffs = $can('allocation-regular')
            ? DB::table('erp_demos as d')->join('erp_leads as l', 'l.id', '=', 'd.lead_id')->leftJoin('erp_teachers as t', 't.id', '=', 'd.teacher_id')->leftJoin('erp_subjects as s', 's.id', '=', 'd.subject_id')
                ->where('d.unit_id', $unit)->where('d.status', 'ATTENDED')->where('d.feedback_outcome', 'CONTINUE')->whereNull('d.enrollment_id')->where('l.status', 'LEAD')
                ->orderBy('d.starts_at')->limit(50)->get(['d.id', 'd.lead_id', 'd.starts_at', 'l.name as lead_name', 't.name as teacher_name', 's.name as subject_name'])
                ->map(fn ($r) => [
                    'demoId' => (int) $r->id, 'leadId' => (int) $r->lead_id, 'leadName' => $r->lead_name,
                    'when' => $r->starts_at ? CarbonImmutable::parse($r->starts_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i') : null,
                    'teacherName' => $r->teacher_name, 'subjectName' => $r->subject_name,
                ])->values()
            : collect();

        $demos = $this->demos($unit, $context);
        $seedDemo = !empty($p['demo_id']) ? $demos->firstWhere('id', (int) $p['demo_id']) : null;
        $handoff = $context ? DB::table('erp_leads')->where('unit_id', $unit)->where($context->lead_id ? 'id' : 'student_id', $context->lead_id ?: $context->student_id)
            ->select('learning_requirement', 'concern', 'about_student', 'about_parent')->orderByDesc('id')->first() : null;

        return response()->json([
            'leads' => $leads,
            'handoffs' => $handoffs,
            'context' => $context ? [
                'studentId' => $context->student_id, 'leadId' => $context->lead_id, 'name' => $context->name,
                'grade' => $context->grade, 'syllabus' => $context->syllabus, 'timezone' => $context->timezone, 'status' => $context->status,
            ] : null,
            'handoff' => $handoff ? [
                'learningRequirement' => $handoff->learning_requirement, 'concern' => $handoff->concern,
                'aboutStudent' => $handoff->about_student, 'aboutParent' => $handoff->about_parent,
            ] : null,
            'demos' => $demos->map(function ($d) use ($context, $can, $tz) {
                // Next step per demo: enrolled -> timetable; attended and continuing -> allocate; otherwise feedback.
                $next = null;
                $allocate = null;
                if ($d->enrollment_id) {
                    $next = 'timetable';
                } elseif ($d->status === 'ATTENDED' && $d->feedback_outcome === 'CONTINUE' && $can('allocation-regular')) {
                    $next = 'allocate';
                    $first = collect(range(1, 60))->map(fn ($weeks) => CarbonImmutable::parse($d->starts_at, 'UTC')->setTimezone($d->timezone ?: $tz)->addWeeks($weeks))->first(fn ($start) => $start->isFuture());
                    $allocate = [
                        'lead_id' => (string) $context->lead_id, 'subject_id' => (string) $d->subject_id, 'grade' => (string) $d->matching_grade,
                        'syllabus' => (string) $d->matching_syllabus, 'medium' => (string) $d->matching_medium, 'timezone' => $first?->timezoneName ?? '',
                        'duration_minutes' => (string) $d->duration_minutes, 'date' => $first?->toDateString() ?? '', 'time' => $first?->format('H:i') ?? '',
                        'teacher_q' => (string) $d->teacher_name,
                    ];
                } elseif ($can('allocation-feedback') && !in_array($d->status, ['CANCELLED', 'NO_SHOW'], true)) {
                    $next = 'feedback';
                }
                return [
                    'id' => (int) $d->id, 'teacherName' => $d->teacher_name, 'scheduledOn' => $d->scheduled_on, 'scheduledTime' => $d->scheduled_time,
                    'status' => $d->status, 'feedbackOutcome' => $d->feedback_outcome, 'next' => $next, 'allocate' => $allocate,
                ];
            })->values(),
            'seed' => $context ? [
                'subject_id' => isset($seedDemo?->subject_id) ? (string) $seedDemo->subject_id : '',
                'medium' => (string) ($seedDemo?->matching_medium ?? ''),
                'timezone' => (string) ($seedDemo?->timezone ?? $context->timezone ?? $tz),
                'duration_minutes' => (string) ($seedDemo?->duration_minutes ?? 60),
                'grade' => (string) ($context->grade ?? ''),
                'syllabus' => (string) ($context->syllabus ?? ''),
            ] : null,
            'subjects' => DB::table('erp_subjects')->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->values(),
            'options' => [
                'media' => TeacherAllocation::MEDIA,
                'levels' => collect(TeacherDirectory::LEVELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'categories' => TeacherDirectory::CATEGORIES,
                'sorts' => collect(TeacherDirectory::SORTS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            ],
            'timezone' => $tz,
        ]);
    }

    /** Teachers for one slot, with the service's blockers; a bad slot is a 422 from TeacherAllocation::slot. */
    public function teachers(Request $request, int $unit)
    {
        $p = $request->validate($this->learnerRules() + $this->slotRules());
        $user = $request->user();
        $permissions = new TeamPermissions();
        $matching = (new TeacherAllocation())->matches($unit, $user, $p);
        $context = $matching['context'];
        $slot = $matching['slot'];
        $page = $matching['teachers'];
        $ids = collect($page->items())->pluck('id')->all();
        $stats = DB::table('erp_demos')->where('unit_id', $unit)->whereIn('teacher_id', $ids)
            ->select('teacher_id')->selectRaw("COUNT(*) AS booked, SUM(CASE WHEN status IN ('ATTENDED','JOINED') THEN 1 ELSE 0 END) AS attended, SUM(CASE WHEN enrollment_id IS NOT NULL THEN 1 ELSE 0 END) AS allocated")
            ->groupBy('teacher_id')->get()->keyBy('teacher_id');
        $demos = $this->demos($unit, $context);
        $canDemo = $context->lead_id && $permissions->allows($user, $unit, 'action', 'allocation-demo');
        $canRegular = $permissions->allows($user, $unit, 'action', 'allocation-regular');
        $canQualify = $permissions->allows($user, $unit, 'action', 'allocation-qualify');

        return response()->json([
            'slot' => [
                'subject_id' => (string) $slot['subject_id'], 'date' => $slot['date'], 'time' => $slot['time'], 'timezone' => $slot['timezone'],
                'duration_minutes' => (string) $slot['duration_minutes'], 'medium' => $slot['medium'], 'grade' => $slot['grade'], 'syllabus' => $slot['syllabus'],
            ],
            'weekday' => CarbonImmutable::parse($slot['date'])->format('l'),
            'data' => collect($page->items())->map(function ($t) use ($stats, $demos, $slot, $context, $canDemo, $canRegular, $canQualify) {
                $s = $stats[$t->id] ?? null;
                $successful = $demos->filter(fn ($d) => $d->feedback_outcome === 'CONTINUE' && $d->status === 'ATTENDED' && $d->teacher_id == $t->id && $d->subject_id == $slot['subject_id'])
                    ->map(fn ($d) => ['id' => (int) $d->id, 'label' => $d->scheduled_on.' · '.$d->teacher_name])->values();
                $ready = empty($t->blockers);
                return [
                    'id' => (int) $t->id, 'code' => $t->code, 'name' => $t->name, 'timezone' => $t->timezone, 'levels' => $t->levels, 'category' => $t->category,
                    'verifiedAt' => $t->verified_at, 'blockers' => array_values($t->blockers), 'localTime' => $t->local_time,
                    'hours' => (float) $t->hours, 'ttHours' => $t->tt_hours,
                    'demoStats' => ['booked' => (int) ($s->booked ?? 0), 'attended' => (int) ($s->attended ?? 0), 'allocated' => (int) ($s->allocated ?? 0)],
                    'can' => [
                        'prepare' => !$ready && $canQualify,
                        'demo' => $ready && (bool) $canDemo,
                        'regular' => $ready && $canRegular && (!$context->lead_id || $successful->isNotEmpty()),
                        'needsFeedback' => $ready && $canRegular && $context->lead_id && $successful->isEmpty(),
                    ],
                    'successfulDemos' => $successful,
                ];
            })->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** Confirmed teaching details for one teacher; only for roles that may confirm them. */
    public function prepare(Request $request, int $unit, int $teacher)
    {
        $row = DB::table('erp_teachers as t')->join('erp_teacher_units as u', 'u.teacher_id', '=', 't.id')->where('u.unit_id', $unit)->where('t.id', $teacher)
            ->select('t.id', 't.code', 't.name', 't.status', 't.timezone')->first();
        abort_unless($row, 404);
        $profile = DB::table('erp_teacher_matching_profiles')->where('teacher_id', $row->id)->first(['mediums', 'verified_at']);
        return response()->json([
            'teacher' => ['id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name, 'status' => $row->status, 'timezone' => $row->timezone],
            'mediums' => json_decode($profile->mediums ?? '[]', true) ?: [],
            'verifiedAt' => $profile->verified_at ?? null,
            'windows' => DB::table('erp_availability')->where('unit_id', $unit)->where('teacher_id', $row->id)->orderBy('weekday')
                ->get(['id', 'weekday', 'start_time', 'end_time', 'timezone', 'starts_on', 'ends_on'])
                ->map(fn ($w) => ['id' => (int) $w->id, 'weekday' => self::WEEKDAYS[(int) $w->weekday] ?? (string) $w->weekday, 'startTime' => $w->start_time, 'endTime' => $w->end_time, 'timezone' => $w->timezone, 'startsOn' => $w->starts_on, 'endsOn' => $w->ends_on])->values(),
            'eligibilities' => DB::table('erp_eligibilities as e')->join('erp_subjects as s', 's.id', '=', 'e.subject_id')->where('e.teacher_id', $row->id)
                ->get(['s.name', 'e.grade', 'e.syllabus'])->map(fn ($e) => ['subject' => $e->name, 'grade' => $e->grade, 'syllabus' => $e->syllabus])->values(),
        ]);
    }

    /** Latest 30 timed demos for an enquiry. */
    private function demos(int $unit, ?object $context)
    {
        if (!$context?->lead_id) return collect();
        return DB::table('erp_demos as d')->leftJoin('erp_teachers as t', 't.id', '=', 'd.teacher_id')->where('d.unit_id', $unit)->where('d.lead_id', $context->lead_id)->whereNotNull('d.starts_at')
            ->select('d.id', 'd.teacher_id', 'd.subject_id', 'd.starts_at', 'd.timezone', 'd.duration_minutes', 'd.scheduled_on', 'd.scheduled_time', 'd.status', 'd.feedback_outcome', 'd.enrollment_id', 'd.matching_medium', 'd.matching_grade', 'd.matching_syllabus', 't.name as teacher_name')
            ->orderByDesc('d.starts_at')->limit(30)->get();
    }
}
