<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\TeamPermissions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON read for the private "Class follow-up and HR" screen (classic TuitionController).
 * The route declares ['detail','all'] so TeamAuthorization admits only team managers,
 * read-only and administrators; this controller then repeats the classic screen's own
 * explicit role check, so the effective rule is exactly superadmin / admin / team_manager.
 */
class TuitionApiController extends Controller
{
    private function authorizeTeam(Request $request, int $unit): void
    {
        abort_unless(in_array((new TeamPermissions())->role($request->user(), $unit), MoneyApiController::TUITION_ROLES, true), 403, 'Private operations are restricted to administrators and team managers.');
    }

    public function index(Request $request, int $unit)
    {
        $this->authorizeTeam($request, $unit);
        $v = $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $business = DB::table('erp_units')->find($unit);
        $lessons = DB::table('erp_lessons as l')->join('erp_students as s', 's.id', '=', 'l.student_id')->join('erp_teachers as t', 't.id', '=', 'l.teacher_id')
            ->where('l.unit_id', $unit)->orderByDesc('l.starts_at')->limit(200)
            ->get(['l.id', 'l.starts_at', 'l.ends_at', 'l.status', 'l.kind', 's.name as student_name', 't.name as teacher_name']);
        $pending = DB::table('erp_attendance as a')->join('erp_lessons as l', 'l.id', '=', 'a.lesson_id')->join('erp_students as s', 's.id', '=', 'l.student_id')
            ->where('a.unit_id', $unit)->where('a.status', 'submitted')->orderBy('a.created_at')
            ->paginate(30, ['a.id', 'a.lesson_id', 'a.actual_minutes', 'a.actual_starts_at', 'a.submitted_late', 'a.parent_review_status', 'a.parent_deadline_at', 'a.change_reason', 's.name as student_name', 'l.starts_at'], 'page', (int) ($v['page'] ?? 1));
        $shortfalls = DB::table('erp_attendance as a')->join('erp_lessons as l', 'l.id', '=', 'a.lesson_id')->join('erp_students as s', 's.id', '=', 'l.student_id')
            ->where('a.unit_id', $unit)->where('a.status', '!=', 'rejected')->where('l.kind', '!=', 'demo')->orderByDesc('a.id')->limit(200)
            ->get(['a.actual_minutes', 'l.id', 'l.starts_at', 'l.ends_at', 's.name'])
            ->map(fn ($r) => tap($r, fn () => $r->planned = (int) Carbon::parse($r->starts_at)->diffInMinutes(Carbon::parse($r->ends_at))))
            ->filter(fn ($r) => $r->actual_minutes < $r->planned);
        $notes = DB::table('erp_internal_notes as n')->join('users as u', 'u.id', '=', 'n.created_by')->where('n.unit_id', $unit)->orderByDesc('n.id')->limit(100)
            ->get(['n.id', 'n.lesson_id', 'n.kind', 'n.outcome', 'n.note', 'n.created_at', 'u.name as actor_name']);
        $candidates = DB::table('erp_candidates')->where('unit_id', $unit)->orderByDesc('id')->limit(200)->get(['id', 'name', 'contact', 'qualification', 'subjects', 'levels', 'languages', 'availability', 'status']);
        $interviews = DB::table('erp_candidate_interviews')->where('unit_id', $unit)->orderByDesc('id')->limit(200)->get(['id', 'candidate_id', 'score', 'decision', 'strengths', 'concerns', 'recording_url', 'created_at']);
        $parentRequests = DB::table('erp_parent_requests as r')->join('erp_students as s', 's.id', '=', 'r.student_id')->leftJoin('erp_attendance as a', 'a.id', '=', 'r.attendance_id')->leftJoin('erp_lessons as l', 'l.id', '=', 'a.lesson_id')
            ->where('r.unit_id', $unit)->where('r.status', 'open')->orderBy('r.created_at')->limit(100)
            ->get(['r.id', 'r.created_at', 'r.note', 'r.attendance_id', 's.name as student_name', 's.code as student_code', 'a.actual_minutes', 'a.status as attendance_status', 'l.starts_at']);
        $now = Carbon::now('UTC');

        return response()->json([
            'timezone' => $business->timezone,
            'parentRequests' => $parentRequests->map(fn ($r) => [
                'id' => (int) $r->id, 'receivedAt' => $r->created_at, 'studentName' => $r->student_name, 'studentCode' => $r->student_code, 'note' => $r->note,
                'attendanceId' => $r->attendance_id !== null ? (int) $r->attendance_id : null, 'lessonStartsAt' => $r->starts_at,
                'actualMinutes' => $r->actual_minutes !== null ? (int) $r->actual_minutes : null, 'attendanceStatus' => $r->attendance_status,
            ])->values(),
            'students' => DB::table('erp_students')->where('unit_id', $unit)->orderBy('name')->get(['id', 'name', 'code'])->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'code' => $s->code])->values(),
            'pending' => collect($pending->items())->map(fn ($a) => [
                'id' => (int) $a->id, 'lessonId' => (int) $a->lesson_id, 'studentName' => $a->student_name, 'actualMinutes' => (int) $a->actual_minutes,
                'actualStartsAt' => $a->actual_starts_at ?? $a->starts_at, 'submittedLate' => (bool) $a->submitted_late,
                'parentReviewStatus' => $a->parent_review_status, 'parentDeadlineAt' => $a->parent_deadline_at, 'changeReason' => $a->change_reason,
            ])->values(),
            'pendingMeta' => ['page' => $pending->currentPage(), 'perPage' => $pending->perPage(), 'total' => $pending->total(), 'sort' => 'created_at', 'dir' => 'asc'],
            'lessons' => $lessons->map(fn ($l) => [
                'id' => (int) $l->id, 'startsAt' => $l->starts_at, 'status' => $l->status, 'kind' => $l->kind, 'studentName' => $l->student_name, 'teacherName' => $l->teacher_name,
                // Same rule as the classic list: cancelled, no-show, or still "scheduled" after it ended.
                'needsFollowUp' => in_array($l->status, ['cancelled', 'no_show'], true) || ($l->status === 'scheduled' && Carbon::parse($l->ends_at, 'UTC')->lt($now)),
            ])->values(),
            'shortfalls' => $shortfalls->map(fn ($r) => ['lessonId' => (int) $r->id, 'studentName' => $r->name, 'startsAt' => $r->starts_at, 'plannedMinutes' => $r->planned, 'actualMinutes' => (int) $r->actual_minutes])->values(),
            'notes' => $notes->map(fn ($n) => ['id' => (int) $n->id, 'lessonId' => (int) $n->lesson_id, 'kind' => $n->kind, 'outcome' => $n->outcome, 'note' => $n->note, 'createdAt' => $n->created_at, 'actorName' => $n->actor_name])->values(),
            'candidates' => $candidates->map(fn ($c) => [
                'id' => (int) $c->id, 'name' => $c->name, 'contact' => $c->contact, 'qualification' => $c->qualification, 'subjects' => $c->subjects,
                'levels' => $c->levels, 'languages' => $c->languages, 'availability' => $c->availability, 'status' => $c->status,
                'interviews' => $interviews->where('candidate_id', $c->id)->map(fn ($i) => [
                    'id' => (int) $i->id, 'score' => (int) $i->score, 'decision' => $i->decision, 'strengths' => $i->strengths, 'concerns' => $i->concerns, 'recordingUrl' => $i->recording_url, 'createdAt' => $i->created_at,
                ])->values(),
            ])->values(),
            'can' => ['approve' => !$business->archived_at && (new TeamPermissions())->allows($request->user(), $unit, 'action', 'approve'), 'write' => !$business->archived_at],
        ]);
    }
}
