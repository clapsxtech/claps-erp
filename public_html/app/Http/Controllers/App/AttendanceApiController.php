<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\WorkQueues;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for the Attendance screen (page "attendance"). Queues come from
 * WorkQueues, exactly as the classic page renders them; the register adds the
 * read-only student/teacher finance states WorkspaceController::workspace shows.
 * Nothing here posts money: approvals go to the classic POST routes.
 */
class AttendanceApiController extends Controller
{
    private const SORTS = ['starts_at' => 'l.starts_at', 'student' => 's.name', 'teacher' => 't.name', 'minutes' => 'a.actual_minutes', 'status' => 'a.status'];

    /** The attendance register with finance states and queue counts. */
    public function index(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:submitted,approved,rejected',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::SORTS)),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $tz = DB::table('erp_units')->where('id', $unit)->value('timezone');
        $query = $this->base($unit)->select('a.id', 'a.lesson_id', 'a.actual_minutes', 'a.status', 'a.submitted_late', 'l.starts_at', 'l.kind', 's.name as student_name', 's.code as student_code', 't.name as teacher_name', 'subject.name as subject_name')
            ->selectSub($this->pendingReviews($unit), 'pending_reviews');
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('s.name', 'like', '%'.$search.'%')->orWhere('s.code', 'like', '%'.$search.'%')->orWhere('t.name', 'like', '%'.$search.'%')->orWhere('a.id', $search));
        }
        if (!empty($v['status'])) $query->where('a.status', $v['status']);
        $sort = $v['sort'] ?? 'starts_at';
        $dir = $v['dir'] ?? 'desc';
        $query->orderBy(self::SORTS[$sort], $dir)->orderBy('a.id', $dir);
        $page = $query->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        $rows = collect($page->items());
        $states = $this->financeStates($unit, $rows);

        $queues = (new WorkQueues())->attendance($unit);
        return response()->json([
            'data' => $rows->map(fn ($r) => $this->row($r, $tz) + $states[$r->id])->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sort, 'dir' => $dir],
            'counts' => [
                'approval' => $queues['teacher_approval']->total(),
                'import' => $queues['student_import']->total(),
                'missing' => $queues['missing_submission']->total(),
                'reviews' => DB::table('erp_duplicate_reviews')->where('unit_id', $unit)->where('status', 'pending')->count(),
                'submitted' => DB::table('erp_attendance')->where('unit_id', $unit)->where('status', 'submitted')->count(),
            ],
            'timezone' => $tz,
        ]);
    }

    /** One of the classic work queues: approval (teacher accrual), import (student charge) or missing. */
    public function queue(Request $request, int $unit, string $queue)
    {
        $v = $request->validate([
            'approval_queue_page' => 'nullable|integer|min:1|max:100000',
            'import_queue_page' => 'nullable|integer|min:1|max:100000',
            'missing_queue_page' => 'nullable|integer|min:1|max:100000',
        ]);
        $tz = DB::table('erp_units')->where('id', $unit)->value('timezone');
        $service = new WorkQueues();
        if ($queue === 'missing') {
            $page = $service->missingSubmission($unit);
            $data = collect($page->items())->map(function ($r) use ($tz) {
                $start = CarbonImmutable::parse($r->starts_at, 'UTC')->setTimezone($tz);
                return [
                    'lessonId' => (int) $r->lesson_id,
                    'localDate' => $start->toDateString(),
                    'startTime' => $start->format('H:i'),
                    'kind' => $r->kind,
                    'studentName' => $r->student_name,
                    'studentCode' => $r->student_code,
                    'teacherName' => $r->teacher_name,
                    'subjectName' => $r->subject_name,
                ];
            })->values();
        } else {
            $page = $service->attendance($unit)[$queue === 'approval' ? 'teacher_approval' : 'student_import'];
            $rows = collect($page->items());
            $states = $this->financeStates($unit, $rows);
            $data = $rows->map(fn ($r) => $this->row($r, $tz) + $states[$r->id])->values();
        }
        return response()->json([
            'data' => $data,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'pageParam' => $page->getPageName()],
            'timezone' => $tz,
        ]);
    }

    /** Pending Gallabox duplicate reviews, with both attendance records named. */
    public function reviews(Request $request, int $unit)
    {
        $v = $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $tz = DB::table('erp_units')->where('id', $unit)->value('timezone');
        $page = DB::table('erp_duplicate_reviews')->where('unit_id', $unit)->where('status', 'pending')
            ->select('id', 'attendance_id', 'other_attendance_id', 'status')
            ->orderBy('id')->paginate(25, ['*'], 'page', (int) ($v['page'] ?? 1));
        $ids = collect($page->items())->flatMap(fn ($r) => [$r->attendance_id, $r->other_attendance_id])->unique()->values()->all();
        $records = $this->base($unit)->whereIn('a.id', $ids)
            ->select('a.id', 'a.actual_minutes', 'a.status', 'l.starts_at', 's.name as student_name', 't.name as teacher_name', 'subject.name as subject_name')
            ->get()->keyBy('id');
        $describe = function ($id) use ($records, $tz) {
            $r = $records[$id] ?? null;
            if (!$r) return ['id' => (int) $id, 'label' => 'Attendance #'.$id];
            $start = CarbonImmutable::parse($r->starts_at, 'UTC')->setTimezone($tz);
            return ['id' => (int) $id, 'label' => $r->student_name.' / '.$r->teacher_name.' · '.$r->subject_name.' · '.$start->format('j M Y H:i').' · '.$r->actual_minutes.' min · '.$r->status];
        };
        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => [
                'id' => (int) $r->id,
                'attendance' => $describe($r->attendance_id),
                'other' => $describe($r->other_attendance_id),
                'status' => $r->status,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** Same scoping as WorkQueues: lesson, student and teacher pool all inside this unit. */
    private function base(int $unit)
    {
        return DB::table('erp_attendance as a')
            ->join('erp_lessons as l', function ($j) { $j->on('l.id', '=', 'a.lesson_id')->on('l.unit_id', '=', 'a.unit_id'); })
            ->join('erp_students as s', function ($j) { $j->on('s.id', '=', 'l.student_id')->on('s.unit_id', '=', 'l.unit_id'); })
            ->join('erp_teachers as t', 't.id', '=', 'l.teacher_id')
            ->join('erp_subjects as subject', 'subject.id', '=', 'l.subject_id')
            ->whereExists(function ($q) { $q->selectRaw('1')->from('erp_teacher_units as pool')->whereColumn('pool.teacher_id', 't.id')->whereColumn('pool.unit_id', 'a.unit_id'); })
            ->where('a.unit_id', $unit);
    }

    private function pendingReviews(int $unit): \Closure
    {
        return function ($q) use ($unit) {
            $q->from('erp_duplicate_reviews as d')->selectRaw('COUNT(*)')->where('d.unit_id', $unit)->where('d.status', 'pending')
                ->where(function ($q) { $q->whereColumn('d.attendance_id', 'a.id')->orWhereColumn('d.other_attendance_id', 'a.id'); });
        };
    }

    private function row(object $r, string $tz): array
    {
        $start = CarbonImmutable::parse($r->starts_at, 'UTC')->setTimezone($tz);
        return [
            'id' => (int) $r->id,
            'lessonId' => (int) $r->lesson_id,
            'localDate' => $start->toDateString(),
            'startTime' => $start->format('H:i'),
            'kind' => $r->kind,
            'studentName' => $r->student_name,
            'studentCode' => $r->student_code,
            'teacherName' => $r->teacher_name,
            'subjectName' => $r->subject_name,
            'actualMinutes' => (int) $r->actual_minutes,
            'status' => $r->status,
            'pendingReviews' => (int) $r->pending_reviews,
        ];
    }

    /**
     * Read-only copy of the finance-state labels in WorkspaceController::workspace.
     * Keep in step with that method; it decides nothing, it only describes the ledger.
     */
    private function financeStates(int $unit, Collection $rows): array
    {
        $ids = $rows->pluck('id')->all();
        $attendanceLedger = DB::table('erp_ledger')->where('unit_id', $unit)->whereIn('attendance_id', $ids)->get(['id', 'attendance_id', 'kind', 'reverses_id'])->groupBy('attendance_id');
        $out = [];
        foreach ($rows as $attendance) {
            $entries = $attendanceLedger->get($attendance->id, collect());
            $reversed = $entries->pluck('reverses_id')->filter()->all();
            $active = $entries->filter(fn ($entry) => !in_array($entry->id, $reversed) && in_array($entry->kind, ['lesson_charge', 'teacher_accrual']));
            $demo = $attendance->kind === 'demo';
            $out[$attendance->id] = [
                'studentFinanceState' => $demo ? 'Free demo' : ($active->where('kind', 'lesson_charge')->isNotEmpty() ? 'Imported' : ($attendance->status === 'rejected' ? 'Rejected / reversed' : 'Pending import')),
                'teacherFinanceState' => $demo ? 'Unpaid demo' : ($active->where('kind', 'teacher_accrual')->isNotEmpty() ? 'Accrued' : ($attendance->status === 'rejected' ? 'Rejected / reversed' : ($attendance->status === 'approved' ? 'Approved, not accrued' : 'Pending approval'))),
            ];
        }
        return $out;
    }
}
