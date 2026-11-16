<?php
namespace App\Http\Controllers;

use App\Modules\Erp\Services\{MoneyOperations,ParentPortal};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Family portal: student ID + date of birth, read-only apart from class queries. */
class ParentPortalController extends Controller
{
    private const SECTIONS = ['overview', 'classes', 'results', 'fees'];

    public function showLogin(Request $request)
    {
        if ($this->student($request)) return redirect()->route('parent.home');
        return response()->view('parent.login')->header('Cache-Control', 'no-store');
    }

    public function login(Request $request)
    {
        $v = $request->validate(['student_code' => 'required|string|max:100', 'date_of_birth' => 'required|date_format:Y-m-d']);
        $student = (new ParentPortal())->authenticate($v['student_code'], $v['date_of_birth']);
        $request->session()->regenerate();
        $request->session()->put('parent_portal', ['student_id' => (int) $student->id, 'unit_id' => (int) $student->unit_id, 'fingerprint' => ParentPortal::fingerprint((int) $student->id), 'seen' => now()->getTimestamp()]);
        return redirect()->route('parent.home');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('parent_portal');
        $request->session()->regenerate();
        return redirect()->route('parent.login')->with('status', 'You have signed out.');
    }

    public function index(Request $request, string $section = 'overview')
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        if (!$student = $this->student($request)) return redirect()->route('parent.login');
        $portal = new ParentPortal();
        $data = match ($section) {
            'overview' => $portal->overview($student),
            'classes' => $portal->classes($student),
            'results' => ['results' => $portal->results($student)->paginate(25)],
            'fees' => $portal->fees($student),
        };
        $business = DB::table('erp_units')->find($student->unit_id);
        return response()->view('parent.portal', compact('student', 'business', 'section', 'data'))->header('Cache-Control', 'no-store');
    }

    public function dispute(Request $request)
    {
        if (!$student = $this->student($request)) return redirect()->route('parent.login');
        $v = $request->validate(['attendance_id' => 'required|integer|min:1', 'note' => 'required|string|max:1000']);
        $query = (new ParentPortal())->dispute($student, (int) $v['attendance_id'], $v['note']);
        $message = $query->held
            ? 'Thank you. The centre will check this class and get back to you. It will not be billed until it is resolved.'
            : 'Thank you. The centre will check this class and get back to you. It has already been billed; if the centre agrees it was wrong, they will correct your account.';
        return redirect()->route('parent.home', ['section' => 'classes'])->with('status', $message);
    }

    public function statement(Request $request)
    {
        if (!$student = $this->student($request)) return redirect()->route('parent.login');
        $v = $request->validate(['month' => 'required|date_format:Y-m']);
        $business = DB::table('erp_units')->find($student->unit_id);
        ['rows' => $rows, 'account' => $account] = ParentPortal::statement((int) $student->unit_id, $student, $v['month']);
        $month = $v['month']; $forParent = true;
        return response()->view('erp.parent-statement', compact('business', 'student', 'rows', 'month', 'account', 'forParent'))->header('Cache-Control', 'no-store');
    }

    public function document(Request $request, string $type, int $id)
    {
        if (!$student = $this->student($request)) return redirect()->route('parent.login');
        $business = DB::table('erp_units')->find($student->unit_id);
        if ($type === 'receipt') {
            $entry = DB::table('erp_ledger')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->where('id', $id)->whereIn('kind', ['payment', 'refund'])->first();
            abort_unless($entry, 404);
            $reversed = DB::table('erp_ledger')->where('reverses_id', $entry->id)->exists();
            return response()->view('parent.receipt', compact('student', 'business', 'entry', 'reversed'))->header('Cache-Control', 'no-store');
        }
        if ($type === 'request') {
            $paymentRequest = DB::table('erp_payment_requests')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->where('id', $id)->first();
            abort_unless($paymentRequest, 404);
            $summary = (new MoneyOperations())->requestSummary((int) $student->unit_id, (int) $paymentRequest->id);
            return response()->view('parent.payment-request', compact('student', 'business', 'paymentRequest', 'summary'))->header('Cache-Control', 'no-store');
        }
        if ($type === 'invoice') {
            $invoice = DB::table('erp_invoices')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->where('id', $id)->first();
            abort_unless($invoice, 404);
            $lines = DB::table('erp_invoice_lines')->where('invoice_id', $invoice->id)->orderBy('id')->get();
            $credited = $invoice->credits_invoice_id ? DB::table('erp_invoices')->find($invoice->credits_invoice_id) : null;
            $forParent = true;
            return response()->view('erp.invoice', compact('student', 'business', 'invoice', 'lines', 'credited', 'forParent'))->header('Cache-Control', 'no-store');
        }
        abort(404);
    }

    /** The signed-in student, or null when the session is missing, idle too long, or the date of birth changed. */
    private function student(Request $request): ?object
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
