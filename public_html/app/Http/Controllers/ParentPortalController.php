<?php
namespace App\Http\Controllers;

use App\Modules\Erp\Services\{MoneyOperations,ParentPortal};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Family portal: student ID + date of birth, read-only apart from class queries. */
class ParentPortalController extends Controller
{
    private const SECTIONS = ['overview', 'classes', 'results', 'fees'];

    public function login(Request $request)
    {
        $v = $request->validate(['student_code' => 'required|string|max:100', 'date_of_birth' => 'required|date_format:Y-m-d']);
        $student = (new ParentPortal())->authenticate($v['student_code'], $v['date_of_birth'], ParentPortal::clientIp($request->ip()));
        $request->session()->regenerate();
        $request->session()->put('parent_portal', ['student_id' => (int) $student->id, 'unit_id' => (int) $student->unit_id, 'fingerprint' => ParentPortal::fingerprint((int) $student->id), 'seen' => now()->getTimestamp()]);
        if ($request->expectsJson()) return response()->json(['redirect' => route('parent.home', [], false)]);
        return redirect()->route('parent.home');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('parent_portal');
        $request->session()->regenerate();
        if ($request->expectsJson()) {
            $request->session()->flash('status', 'You have signed out.');
            return response()->json(['redirect' => route('parent.login', [], false)]);
        }
        return redirect()->route('parent.login')->with('status', 'You have signed out.');
    }

    public function dispute(Request $request)
    {
        if (!$student = $this->student($request)) return $request->expectsJson() ? response()->json(['message' => 'Your session has ended. Sign in again.'], 401) : redirect()->route('parent.login');
        $v = $request->validate(['attendance_id' => 'required|integer|min:1', 'note' => 'required|string|max:1000']);
        $query = (new ParentPortal())->dispute($student, (int) $v['attendance_id'], $v['note']);
        $message = $query->held
            ? 'Thank you. The centre will check this class and get back to you. It will not be billed until it is resolved.'
            : 'Thank you. The centre will check this class and get back to you. It has already been billed; if the centre agrees it was wrong, they will correct your account.';
        if ($request->expectsJson()) return response()->json(['message' => $message, 'held' => (bool) $query->held, 'attendanceId' => (int) $query->attendance_id]);
        return redirect()->route('parent.home', ['section' => 'classes'])->with('status', $message);
    }

    /** The signed-in student, or null when the session is missing, idle too long, or the date of birth changed. */
    protected function student(Request $request): ?object
    {
        $session = $request->session()->get('parent_portal');
        if (!$session) return null;
        $student = DB::table('erp_students as s')->join('erp_units as u', 'u.id', '=', 's.unit_id')->where('s.id', $session['student_id'])->where('s.unit_id', $session['unit_id'])->whereNull('u.archived_at')->select('s.*')->first();
        if (!$student || $session['seen'] < now()->getTimestamp() - ParentPortal::IDLE_MINUTES * 60 || !hash_equals($session['fingerprint'], ParentPortal::fingerprint((int) $student->id))) {
            $request->session()->forget('parent_portal');
            return null;
        }
        $request->session()->put('parent_portal.seen', now()->getTimestamp());
        return $student;
    }
}
