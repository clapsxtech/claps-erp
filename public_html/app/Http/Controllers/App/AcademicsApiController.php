<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\TeamPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for Results (page "academics"). The marks query is a read-only copy of
 * AcademicsController::results: every join is unit-scoped and the teacher must be in
 * the unit's pool, so a mark pointing at another unit's enrollment never appears.
 */
class AcademicsApiController extends Controller
{
    private const SORTS = ['assessed_on' => 'm.assessed_on', 'student' => 's.name', 'subject' => 'subject.name', 'assessment' => 'm.assessment'];

    public function marks(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'from' => 'nullable|date_format:Y-m-d',
            'through' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''),
            'sort' => 'nullable|in:'.implode(',', array_keys(self::SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $query = DB::table('erp_marks as m')
            ->join('erp_enrollments as e', function ($join) { $join->on('e.id', '=', 'm.enrollment_id')->on('e.unit_id', '=', 'm.unit_id'); })
            ->join('erp_students as s', function ($join) { $join->on('s.id', '=', 'e.student_id')->on('s.unit_id', '=', 'e.unit_id'); })
            ->join('erp_teachers as t', 't.id', '=', 'e.teacher_id')
            ->join('erp_teacher_units as pool', function ($join) { $join->on('pool.teacher_id', '=', 't.id')->on('pool.unit_id', '=', 'e.unit_id'); })
            ->join('erp_subjects as subject', 'subject.id', '=', 'e.subject_id')
            ->where('m.unit_id', $unit)
            ->select('m.id', 'm.assessment', 'm.assessed_on', 'm.score', 'm.maximum', 'm.notes', 's.id as student_id', 's.name as student_name', 's.code as student_code', 't.name as teacher_name', 'subject.name as subject_name');
        if (!empty($v['q'])) $query->where(function ($query) use ($v) {
            foreach (['s.name', 's.code', 't.name', 'subject.name', 'm.assessment'] as $field) $query->orWhere($field, 'like', '%'.$v['q'].'%');
        });
        if (!empty($v['from'])) $query->where('m.assessed_on', '>=', $v['from']);
        if (!empty($v['through'])) $query->where('m.assessed_on', '<=', $v['through']);
        $sort = $v['sort'] ?? 'assessed_on';
        $dir = $v['dir'] ?? 'desc';
        $query->orderBy(self::SORTS[$sort], $dir)->orderBy('m.id', $dir);
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => [
                'id' => (int) $r->id,
                'assessedOn' => $r->assessed_on,
                'studentId' => (int) $r->student_id,
                'studentName' => $r->student_name,
                'studentCode' => $r->student_code,
                'teacherName' => $r->teacher_name,
                'subjectName' => $r->subject_name,
                'assessment' => $r->assessment,
                'score' => (int) $r->score,
                'maximum' => (int) $r->maximum,
                'percentage' => round(100 * $r->score / max(1, $r->maximum), 1),
                'notes' => $r->notes,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir],
            // Same rule as academics.blade.php: student names link to profiles only for detail roles.
            'can' => ['openProfile' => (new TeamPermissions())->allows($request->user(), $unit, 'detail', 'all')],
        ]);
    }

    public function eligibility(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $query = DB::table('erp_eligibilities as e')
            ->join('erp_teacher_units as pool', 'pool.teacher_id', '=', 'e.teacher_id')
            ->join('erp_teachers as t', 't.id', '=', 'e.teacher_id')
            ->join('erp_subjects as subject', 'subject.id', '=', 'e.subject_id')
            ->where('pool.unit_id', $unit)
            ->select('e.id', 'e.grade', 'e.syllabus', 't.name as teacher_name', 'subject.name as subject_name');
        if (!empty($v['q'])) $query->where(fn ($q) => $q->where('t.name', 'like', '%'.$v['q'].'%')->orWhere('subject.name', 'like', '%'.$v['q'].'%'));
        $page = $query->orderBy('subject.name')->orderBy('t.name')->orderBy('e.id')->paginate(25, ['*'], 'page', (int) ($v['page'] ?? 1));
        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => [
                'id' => (int) $r->id, 'subjectName' => $r->subject_name, 'teacherName' => $r->teacher_name, 'grade' => $r->grade, 'syllabus' => $r->syllabus,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
