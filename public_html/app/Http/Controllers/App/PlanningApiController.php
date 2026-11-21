<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Weekly availability rows for the Timetable planning tab. */
class PlanningApiController extends Controller
{
    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public function availability(Request $request, int $unit)
    {
        $v = $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $rows = DB::table('erp_availability as v')
            ->leftJoin('erp_students as s', function ($j) { $j->on('s.id', '=', 'v.student_id')->on('s.unit_id', '=', 'v.unit_id'); })
            ->leftJoin('erp_teachers as t', 't.id', '=', 'v.teacher_id')
            ->where('v.unit_id', $unit)
            ->select('v.id', 'v.student_id', 'v.weekday', 'v.start_time', 'v.end_time', 'v.timezone', 'v.starts_on', 'v.ends_on', 's.name as student_name', 't.name as teacher_name')
            ->orderByDesc('v.id')
            ->paginate(25, ['*'], 'page', (int) ($v['page'] ?? 1));
        return response()->json([
            'data' => collect($rows->items())->map(fn ($r) => [
                'id' => (int) $r->id,
                'party' => $r->student_id ? 'student' : 'teacher',
                'who' => $r->student_id ? $r->student_name : $r->teacher_name,
                'weekday' => self::WEEKDAYS[(int) $r->weekday] ?? (string) $r->weekday,
                'startTime' => substr((string) $r->start_time, 0, 5),
                'endTime' => substr((string) $r->end_time, 0, 5),
                'timezone' => $r->timezone,
                'startsOn' => $r->starts_on,
                'endsOn' => $r->ends_on,
            ])->values(),
            'meta' => ['page' => $rows->currentPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    }
}
