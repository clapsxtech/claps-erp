<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Reporting, TeacherDirectory, TeamPermissions};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for the Teachers screens (page "people"). Same rule as the classic
 * people register: roles without the Money page see a teacher's code, name and
 * status but never their earnings, payments or class rates.
 */
class TeachersApiController extends Controller
{
    private const SORTS = ['code' => null, 'name' => 't.name', 'status' => 't.status', 'tt' => 'tt_minutes', 'classes' => 'active_classes'];

    public function index(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,inactive,stopped',
            'level' => 'nullable|in:'.implode(',', array_keys(TeacherDirectory::LEVELS)),
            'category' => 'nullable|in:'.implode(',', TeacherDirectory::CATEGORIES),
            'subject_id' => 'nullable|integer|min:1',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $business = DB::table('erp_units')->find($unit);
        $canSeeMoney = (new TeamPermissions())->allows($request->user(), $unit, 'page', 'money');
        $today = CarbonImmutable::now($business->timezone)->toDateString();

        $classes = DB::table('erp_enrollments')->where('unit_id', $unit)->where('status', 'active')
            ->select('teacher_id', DB::raw('COUNT(*) AS active_classes'))->groupBy('teacher_id');
        $query = DB::table('erp_teachers as t')->join('erp_teacher_units as u', 'u.teacher_id', '=', 't.id')
            ->leftJoinSub(TeacherDirectory::weeklyMinutes($unit, $today), 'tt', 'tt.teacher_id', '=', 't.id')
            ->leftJoinSub($classes, 'c', 'c.teacher_id', '=', 't.id')
            ->where('u.unit_id', $unit)
            ->select('t.id', 't.code', 't.name', 't.status', 't.levels', 't.category', DB::raw('COALESCE(tt.minutes,0) AS tt_minutes'), DB::raw('COALESCE(c.active_classes,0) AS active_classes'));

        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('t.name', 'like', '%'.$search.'%')->orWhere('t.code', 'like', '%'.$search.'%'));
        if (!empty($v['status'])) $query->where('t.status', $v['status']);
        // Level codes LP, UP and HS never overlap as substrings of the stored list.
        if (!empty($v['level'])) $query->where('t.levels', 'like', '%'.$v['level'].'%');
        if (!empty($v['category'])) $query->where('t.category', $v['category']);
        if (!empty($v['subject_id'])) $query->whereExists(fn ($e) => $e->selectRaw('1')->from('erp_eligibilities')->whereColumn('teacher_id', 't.id')->where('subject_id', $v['subject_id']));

        $sort = $v['sort'] ?? 'code';
        $dir = $v['dir'] ?? 'asc';
        if ($sort === 'code') {
            $query->orderByRaw('LENGTH(t.code) '.$dir)->orderBy('t.code', $dir);
        } else {
            $query->orderBy(DB::raw(self::SORTS[$sort]), $dir);
        }
        $query->orderBy('t.id');

        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $ids = collect($page->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $statements = $canSeeMoney ? (new Reporting())->teacherStatements($unit, $ids) : [];
        $subjects = DB::table('erp_eligibilities as e')->join('erp_subjects as s', 's.id', '=', 'e.subject_id')->whereIn('e.teacher_id', $ids ?: [0])
            ->orderBy('s.name')->get(['e.teacher_id', 's.name'])->groupBy('teacher_id');

        $rows = collect($page->items())->map(function ($row) use ($statements, $canSeeMoney, $subjects) {
            $statement = $statements[$row->id] ?? null;
            return [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'status' => $row->status,
                'levels' => array_values(array_filter(explode(',', (string) $row->levels))),
                'category' => $row->category,
                'subjects' => ($subjects[$row->id] ?? collect())->pluck('name')->unique()->values()->all(),
                'ttHours' => TeacherDirectory::hours((int) $row->tt_minutes),
                'activeClasses' => (int) $row->active_classes,
                'earnedPaise' => $canSeeMoney ? (int) $statement['earned_paise'] : null,
                'paidPaise' => $canSeeMoney ? (int) $statement['paid_paise'] : null,
                'netPayablePaise' => $canSeeMoney ? (int) $statement['net_payable_paise'] : null,
            ];
        });

        return response()->json([
            'data' => $rows,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir],
            'can' => ['seeMoney' => $canSeeMoney],
            'options' => [
                'levels' => collect(TeacherDirectory::LEVELS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
                'categories' => TeacherDirectory::CATEGORIES,
                'subjects' => DB::table('erp_subjects')->whereIn('id', DB::table('erp_eligibilities')->whereIn('teacher_id', DB::table('erp_teacher_units')->where('unit_id', $unit)->select('teacher_id'))->select('subject_id'))
                    ->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->name])->values(),
            ],
        ]);
    }

    public function show(Request $request, int $unit, int $teacher)
    {
        $t = DB::table('erp_teachers as t')->join('erp_teacher_units as u', 'u.teacher_id', '=', 't.id')
            ->where('u.unit_id', $unit)->where('t.id', $teacher)
            ->select('t.id', 't.code', 't.name', 't.status', 't.timezone', 't.levels', 't.category', 't.created_at')->first();
        abort_unless($t, 404);
        $business = DB::table('erp_units')->find($unit);
        $canSeeMoney = (new TeamPermissions())->allows($request->user(), $unit, 'page', 'money');
        $today = CarbonImmutable::now($business->timezone)->toDateString();
        $minutes = (int) DB::query()->fromSub(TeacherDirectory::weeklyMinutes($unit, $today), 'x')->where('teacher_id', $t->id)->value('minutes');

        $eligibilities = DB::table('erp_eligibilities as e')->join('erp_subjects as s', 's.id', '=', 'e.subject_id')
            ->where('e.teacher_id', $t->id)->orderBy('s.name')->orderBy('e.grade')
            ->get(['s.name as subject', 'e.grade', 'e.syllabus'])
            ->map(fn ($r) => ['subject' => $r->subject, 'grade' => $r->grade, 'syllabus' => $r->syllabus])->values();
        // Class rates are money; only the fields below leave the server.
        $classes = DB::table('erp_enrollments as e')->join('erp_students as s', 's.id', '=', 'e.student_id')->leftJoin('erp_subjects as sub', 'sub.id', '=', 'e.subject_id')
            ->where('e.unit_id', $unit)->where('e.teacher_id', $t->id)
            ->orderByRaw("CASE WHEN e.status = 'active' THEN 0 ELSE 1 END")->orderByDesc('e.starts_on')->limit(50)
            ->get(['e.id', 's.id as student_id', 's.name as student_name', 'sub.name as subject_name', 'e.starts_on', 'e.ends_on', 'e.status'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'studentId' => (int) $r->student_id, 'studentName' => $r->student_name, 'subjectName' => $r->subject_name, 'startsOn' => $r->starts_on, 'endsOn' => $r->ends_on, 'status' => $r->status])->values();
        $statement = $canSeeMoney ? (new Reporting())->teacherStatements($unit, [(int) $t->id])[(int) $t->id] : null;

        return response()->json([
            'teacher' => [
                'id' => (int) $t->id, 'code' => $t->code, 'name' => $t->name, 'status' => $t->status, 'timezone' => $t->timezone,
                'levels' => array_values(array_filter(explode(',', (string) $t->levels))), 'category' => $t->category,
                'ttHours' => TeacherDirectory::hours($minutes),
            ],
            'eligibilities' => $eligibilities,
            'classes' => $classes,
            'statement' => $statement ? [
                'earnedPaise' => (int) $statement['earned_paise'],
                'paidPaise' => (int) $statement['paid_paise'],
                'otherAdjustmentsPaise' => (int) $statement['other_adjustments_paise'],
                'netPayablePaise' => (int) $statement['net_payable_paise'],
            ] : null,
            'can' => ['seeMoney' => $canSeeMoney, 'openStudent' => (new TeamPermissions())->allows($request->user(), $unit, 'detail', 'all')],
            'options' => [
                'levels' => collect(TeacherDirectory::LEVELS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
                'categories' => TeacherDirectory::CATEGORIES,
            ],
        ]);
    }
}
