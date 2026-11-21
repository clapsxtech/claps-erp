<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{TeacherRecruitment, TeamPermissions};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for Teacher hiring (page "teacher-recruitment"). Candidates form one
 * site-wide pool. Only managers see applications,
 * review conflicts and a candidate's history; everyone else with the page sees
 * the registered directory (REGISTERED and APPROVED profiles).
 */
class RecruitmentApiController extends Controller
{
    private const SORTS = ['name' => 'c.name', 'stage' => 'c.stage', 'code' => 'c.teacher_code', 'updated' => 'c.updated_at'];
    private const DIRECTORY = ['REGISTERED', 'APPROVED'];

    public function index(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'stage' => 'nullable|in:'.implode(',', TeacherRecruitment::STAGES),
            'subject' => 'nullable|string|max:100',
            'syllabus' => 'nullable|string|max:100',
            'medium' => 'nullable|string|max:100',
            'availability' => 'nullable|string|max:100',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $manage = (new TeacherRecruitment())->canManage($request->user(), $unit);
        $business = DB::table('erp_units')->find($unit);
        $base = fn () => DB::table('erp_teacher_candidates as c')->where('c.business_id', config('erp.business_id'));

        $query = $base()->leftJoin('erp_teachers as t', 't.id', '=', 'c.teacher_id');
        if (!$manage) $query->whereIn('c.stage', self::DIRECTORY);
        if (empty($v['stage'])) $query->whereNull('c.merged_into_id');
        else $query->where('c.stage', $v['stage']);
        if (!empty($v['q'])) $query->where(function ($q) use ($v) { foreach (['c.name', 'c.phone', 'c.teacher_code'] as $field) $q->orWhere($field, 'like', '%'.$v['q'].'%'); });
        foreach (['subject' => 'subjects', 'syllabus' => 'syllabuses', 'medium' => 'medium', 'availability' => 'availability'] as $filter => $field) {
            if (!empty($v[$filter])) $query->where('c.'.$field, 'like', '%'.$v[$filter].'%');
        }
        $sort = $v['sort'] ?? 'name';
        $dir = $v['dir'] ?? ($sort === 'updated' ? 'desc' : 'asc');
        $query->orderBy(DB::raw(self::SORTS[$sort]), $dir)->orderBy('c.id')
            ->select('c.id', 'c.name', 'c.teacher_code', 'c.phone', 'c.email', 'c.qualification', 'c.subjects', 'c.grades', 'c.syllabuses', 'c.medium', 'c.availability', 'c.stage', 'c.source_status', 't.status as operational_status');
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));

        $counts = $base()->whereNull('c.merged_into_id')->when(!$manage, fn ($q) => $q->whereIn('c.stage', self::DIRECTORY))
            ->select('c.stage', DB::raw('COUNT(*) AS aggregate'))->groupBy('c.stage')->pluck('aggregate', 'stage');
        $stages = $manage ? TeacherRecruitment::STAGES : self::DIRECTORY;

        return response()->json([
            'data' => collect($page->items())->map(fn ($c) => [
                'id' => (int) $c->id, 'name' => $c->name, 'teacherCode' => $c->teacher_code, 'phone' => $c->phone, 'email' => $c->email,
                'qualification' => $c->qualification, 'subjects' => $c->subjects, 'grades' => $c->grades, 'syllabuses' => $c->syllabuses,
                'medium' => $c->medium, 'availability' => $c->availability, 'stage' => $c->stage, 'sourceStatus' => $c->source_status,
                'operationalStatus' => $c->operational_status,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir],
            'stages' => collect($stages)->map(fn ($s) => ['value' => $s, 'count' => (int) ($counts[$s] ?? 0)])->values(),
            'can' => ['manage' => $manage, 'write' => $manage && !$business->archived_at && config('sheet_transition.authority') !== 'sheets'],
        ]);
    }

    public function show(Request $request, int $unit, int $candidate)
    {
        $service = new TeacherRecruitment();
        abort_unless($service->canManage($request->user(), $unit), 403, 'Only team managers review teacher applications.');
        $c = DB::table('erp_teacher_candidates')->where('business_id', config('erp.business_id'))->where('id', $candidate)->first();
        abort_unless($c, 404);
        $business = DB::table('erp_units')->find($unit);
        $duplicates = collect();
        if ($c->phone || $c->teacher_code) {
            $duplicates = DB::table('erp_teacher_candidates')->where('business_id', config('erp.business_id'))->whereNull('merged_into_id')->where('id', '!=', $c->id)
                ->where(function ($q) use ($c) { if ($c->phone) $q->orWhere('phone', $c->phone); if ($c->teacher_code) $q->orWhere('teacher_code', $c->teacher_code); })
                ->limit(20)->get(['id', 'name', 'phone', 'teacher_code', 'stage'])
                ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name, 'phone' => $d->phone, 'teacherCode' => $d->teacher_code, 'stage' => $d->stage])->values();
        }
        $ids = DB::table('erp_teacher_candidates')->where('business_id', config('erp.business_id'))->where('merged_into_id', $c->id)->pluck('id')->push($c->id)->all();
        $activities = DB::table('erp_teacher_candidate_activities as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->whereIn('a.candidate_id', $ids)->orderByDesc('a.id')->limit(30)
            ->get(['a.id', 'a.kind', 'a.note', 'a.next_follow_up_on', 'a.created_at', 'u.name as actor_name'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'kind' => $a->kind, 'note' => $a->note, 'nextFollowUpOn' => $a->next_follow_up_on, 'createdAt' => $a->created_at, 'actorName' => $a->actor_name])->values();
        // Only these payload keys are shown.
        $sources = DB::table('erp_teacher_candidate_sources')->whereIn('candidate_id', $ids)->limit(30)->get(['id', 'kind', 'source_key', 'payload', 'created_at'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'kind' => $s->kind, 'sourceKey' => $s->source_key, 'createdAt' => $s->created_at,
                'fields' => array_intersect_key(json_decode($s->payload, true) ?: [], array_flip(['name', 'code', 'phone', 'email', 'source_status', 'submitted_at']))])->values();
        $permissions = new TeamPermissions();

        return response()->json([
            'candidate' => [
                'id' => (int) $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'teacherCode' => $c->teacher_code,
                'teacherId' => $c->teacher_id ? (int) $c->teacher_id : null, 'stage' => $c->stage, 'mergedIntoId' => $c->merged_into_id ? (int) $c->merged_into_id : null,
                'qualification' => $c->qualification, 'subjects' => $c->subjects, 'grades' => $c->grades, 'syllabuses' => $c->syllabuses,
                'medium' => $c->medium, 'availability' => $c->availability, 'sourceStatus' => $c->source_status,
                'cvUrl' => $c->cv_url && preg_match('#^https?://#i', $c->cv_url) ? $c->cv_url : null, 'registeredAt' => $c->registered_at,
            ],
            'duplicates' => $duplicates,
            'activities' => $activities,
            'sources' => $sources,
            'can' => [
                'manage' => !$business->archived_at && config('sheet_transition.authority') !== 'sheets',
                'qualify' => $c->teacher_id !== null && !$business->archived_at && $permissions->allows($request->user(), $unit, 'action', 'allocation-qualify'),
                'openTeacher' => $c->teacher_id !== null && $permissions->allows($request->user(), $unit, 'page', 'people')
                    && DB::table('erp_teacher_units')->where('unit_id', $unit)->where('teacher_id', $c->teacher_id)->exists(),
            ],
        ]);
    }
}
