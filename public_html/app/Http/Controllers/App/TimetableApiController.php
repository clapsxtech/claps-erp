<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{TeamPermissions, Timetable};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON read for the Timetable screen (page "timetable"). The week comes from the
 * Timetable::week projection; this controller only picks
 * explicit fields and converts times to the team's timezone so the client never
 * does date maths. Lesson rates are never emitted.
 */
class TimetableApiController extends Controller
{
    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public function week(Request $request, int $unit)
    {
        // Same filter rules as BusinessController::page('timetable').
        $filters = $request->validate([
            'date' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-03|before_or_equal:2099-12-27',
            'student' => 'nullable|string|max:100',
            'teacher' => 'nullable|string|max:100',
            'status' => 'nullable|in:scheduled,completed,cancelled,rescheduled,no_show',
        ]);
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $business = DB::table('erp_units')->find($unit);
        $tz = $business->timezone;
        $week = (new Timetable())->week($unit, $tz, $filters);
        $now = CarbonImmutable::now('UTC');

        $lesson = fn ($l) => [
            'id' => (int) $l->id,
            'enrollmentId' => (int) $l->enrollment_id,
            'studentId' => (int) $l->student_id,
            'studentName' => $l->student_name,
            'studentCode' => $l->student_code,
            'teacherId' => (int) $l->teacher_id,
            'teacherName' => $l->teacher_name,
            'teacherCode' => $l->teacher_code,
            'subjectName' => $l->subject_name,
            'status' => $l->status,
            'kind' => $l->kind,
            'localDate' => $l->local_start->toDateString(),
            'startTime' => $l->local_start->format('H:i'),
            'endTime' => $l->local_end->format('H:i'),
            'endDate' => $l->local_end->toDateString(),
            'utcOffset' => $l->local_start->format('P'),
            'durationMinutes' => (int) round(($l->local_end->getTimestamp() - $l->local_start->getTimestamp()) / 60),
            'hasEnded' => $l->local_end->lte($now),
            'cancellationReason' => in_array($l->status, ['cancelled', 'no_show'], true) ? $l->cancellation_reason : null,
        ];
        $days = [];
        foreach ($week['days'] as $date => $day) {
            $days[] = ['date' => $date, 'lessons' => $day['lessons']->map($lesson)->values()];
        }

        $start = $week['start'];
        $prev = $start->subWeek();
        $next = $week['end'];

        return response()->json([
            'week' => [
                'start' => $start->toDateString(),
                'end' => $week['end']->subDay()->toDateString(),
                'timezone' => $tz,
                'today' => CarbonImmutable::now($tz)->toDateString(),
                'prevDate' => $prev->toDateString() >= '2000-01-03' ? $prev->toDateString() : null,
                'nextDate' => $next->toDateString() <= '2099-12-27' ? $next->toDateString() : null,
                'count' => (int) $week['count'],
                'truncated' => (bool) $week['truncated'],
            ],
            'days' => $days,
            'filters' => [
                'date' => $filters['date'] ?? null,
                'student' => $filters['student'] ?? null,
                'teacher' => $filters['teacher'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
        ]);
    }

    /** Weekly slots and unavailability: the two registers listed under the week. */
    public function plans(Request $request, int $unit)
    {
        $v = $request->validate([
            'agreements_page' => 'nullable|integer|min:1|max:100000',
            'exceptions_page' => 'nullable|integer|min:1|max:100000',
        ]);
        $agreements = DB::table('erp_agreements as a')
            ->join('erp_enrollments as e', function ($j) { $j->on('e.id', '=', 'a.enrollment_id')->on('e.unit_id', '=', 'a.unit_id'); })
            ->join('erp_students as s', function ($j) { $j->on('s.id', '=', 'e.student_id')->on('s.unit_id', '=', 'e.unit_id'); })
            ->join('erp_teachers as t', 't.id', '=', 'e.teacher_id')
            ->join('erp_subjects as subject', 'subject.id', '=', 'e.subject_id')
            ->where('a.unit_id', $unit)
            ->select('a.id', 'a.enrollment_id', 'a.weekday', 'a.start_time', 'a.duration_minutes', 'a.timezone', 'a.starts_on', 'a.ends_on', 'a.status', 's.name as student_name', 't.name as teacher_name', 'subject.name as subject_name')
            ->orderByDesc('a.id')
            ->paginate(25, ['*'], 'agreements_page', (int) ($v['agreements_page'] ?? 1));
        $exceptions = DB::table('erp_exceptions as x')
            ->leftJoin('erp_students as s', function ($j) { $j->on('s.id', '=', 'x.student_id')->on('s.unit_id', '=', 'x.unit_id'); })
            ->leftJoin('erp_teachers as t', 't.id', '=', 'x.teacher_id')
            ->where('x.unit_id', $unit)
            ->select('x.id', 'x.date', 'x.starts_at', 'x.ends_at', 'x.timezone', 'x.reason', 's.name as student_name', 't.name as teacher_name')
            ->orderByDesc('x.id')
            ->paginate(25, ['*'], 'exceptions_page', (int) ($v['exceptions_page'] ?? 1));

        return response()->json([
            'agreements' => [
                'data' => collect($agreements->items())->map(fn ($a) => [
                    'id' => (int) $a->id,
                    'enrollmentId' => (int) $a->enrollment_id,
                    'weekday' => self::WEEKDAYS[(int) $a->weekday] ?? (string) $a->weekday,
                    'startTime' => $a->start_time,
                    'durationMinutes' => (int) $a->duration_minutes,
                    'timezone' => $a->timezone,
                    'startsOn' => $a->starts_on,
                    'endsOn' => $a->ends_on,
                    'status' => $a->status,
                    'studentName' => $a->student_name,
                    'teacherName' => $a->teacher_name,
                    'subjectName' => $a->subject_name,
                ])->values(),
                'meta' => ['page' => $agreements->currentPage(), 'perPage' => $agreements->perPage(), 'total' => $agreements->total()],
            ],
            'exceptions' => [
                'data' => collect($exceptions->items())->map(function ($x) {
                    $interval = 'Full day';
                    if ($x->starts_at && $x->ends_at && $x->timezone) {
                        $interval = CarbonImmutable::parse($x->starts_at, 'UTC')->setTimezone($x->timezone)->format('H:i').'–'.CarbonImmutable::parse($x->ends_at, 'UTC')->setTimezone($x->timezone)->format('H:i').' '.$x->timezone;
                    }
                    return [
                        'id' => (int) $x->id,
                        'date' => $x->date,
                        'interval' => $interval,
                        'who' => $x->student_name ?? $x->teacher_name,
                        'party' => $x->student_name !== null ? 'student' : 'teacher',
                        'reason' => $x->reason,
                    ];
                })->values(),
                'meta' => ['page' => $exceptions->currentPage(), 'perPage' => $exceptions->perPage(), 'total' => $exceptions->total()],
            ],
            'can' => ['openPlanning' => (new TeamPermissions())->allows($request->user(), $unit, 'detail', 'all')],
        ]);
    }
}
