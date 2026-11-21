<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\ParentPortalController;
use App\Modules\Erp\Services\{Books, Invoicing, MoneyOperations, ParentPortal};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The family portal in the React app. Host pages and JSON reads for one signed-in student.
 * Sign-in, lockouts, the idle timeout and the date-of-birth fingerprint all come from the
 * classic ParentPortalController (its session check) and the ParentPortal service; nothing
 * here decides who may see what beyond "this student only". Every JSON field is listed.
 */
class ParentPortalAppController extends ParentPortalController
{
    private const SECTIONS = ['overview', 'classes', 'results', 'fees'];

    // ---- Host pages -------------------------------------------------------

    public function loginPage(Request $request)
    {
        if ($this->student($request)) return redirect()->route('parent.home');
        return $this->host(['kind' => 'parent', 'signedIn' => false, 'status' => session('status')], 'Sign in · Parent portal');
    }

    public function homePage(Request $request, string $section = 'overview')
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        if (!$student = $this->student($request)) return redirect()->route('parent.login');
        return $this->host($this->header($student), $student->name.' · Parent portal');
    }

    public function statementPage(Request $request)
    {
        if (!$student = $this->student($request)) return redirect()->route('parent.login');
        $request->validate(['month' => 'required|date_format:Y-m']);
        return $this->host($this->header($student), 'Statement · '.$student->name);
    }

    public function documentPage(Request $request, string $type, int $id)
    {
        if (!$student = $this->student($request)) return redirect()->route('parent.login');
        abort_unless($this->documentData($student, $type, $id), 404);
        return $this->host($this->header($student), 'Document · '.$student->name);
    }

    // ---- JSON reads -------------------------------------------------------

    public function section(Request $request, string $section)
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        if (!$student = $this->student($request)) return $this->signedOut();
        $portal = new ParentPortal();
        $data = match ($section) {
            'overview' => $this->overview($portal, $student),
            'classes' => $this->classes($portal, $student),
            'results' => $this->results($portal, $student, $request),
            'fees' => $this->fees($portal, $student),
        };
        return $this->json($data);
    }

    public function statement(Request $request)
    {
        if (!$student = $this->student($request)) return $this->signedOut();
        $v = $request->validate(['month' => 'required|date_format:Y-m']);
        ['rows' => $rows, 'account' => $account] = ParentPortal::statement((int) $student->unit_id, $student, $v['month']);
        $labels = ['payment' => 'Payments received', 'lesson_charge' => 'Class charges posted', 'refund' => 'Refunds paid back', 'opening_balance' => 'Opening balance brought in', 'reversal' => 'Corrections and reversals'];
        $lines = [];
        foreach ($labels as $kind => $label) if ($account['kinds']->has($kind)) $lines[] = ['label' => $label, 'paise' => (int) $account['kinds'][$kind]];
        return $this->json([
            'month' => $v['month'],
            'monthLabel' => CarbonImmutable::createFromFormat('!Y-m', $v['month'])->format('F Y'),
            'rows' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'startsAt' => $this->iso($row->actual_starts_at ?? $row->starts_at),
                'teacher' => $row->teacher_name,
                'subject' => $row->subject_name,
                'isDemo' => $row->kind === 'demo',
                'minutes' => (int) $row->actual_minutes,
                // Same formula as the classic statement; the hourly rate itself never leaves the server.
                'chargePaise' => $row->kind === 'demo' ? 0 : intdiv((int) $row->actual_minutes * (int) $row->student_rate_paise + 30, 60),
            ])->values(),
            'totalMinutes' => (int) $rows->sum('actual_minutes'),
            'account' => ['openingPaise' => (int) $account['opening'], 'lines' => $lines, 'closingPaise' => (int) $account['closing']],
        ]);
    }

    public function document(Request $request, string $type, int $id)
    {
        if (!$student = $this->student($request)) return $this->signedOut();
        $data = $this->documentData($student, $type, $id);
        abort_unless($data, 404);
        return $this->json($data);
    }

    // ---- Builders ---------------------------------------------------------

    /** What the host page embeds: the student's public facts and the centre's contact, nothing else. */
    private function header(object $student): array
    {
        $business = DB::table('erp_units')->where('id', $student->unit_id)->first(['name', 'phone']);
        return [
            'kind' => 'parent',
            'signedIn' => true,
            'status' => session('status'),
            'student' => [
                'name' => $student->name,
                'code' => $student->code,
                'grade' => preg_match('/^\s*grade\b/i', (string) $student->grade) ? (string) $student->grade : 'Grade '.$student->grade,
                'syllabus' => $student->syllabus,
                'timezone' => $student->timezone,
            ],
            'centre' => ['name' => $business->name, 'phone' => $business->phone ?: null],
        ];
    }

    private function overview(ParentPortal $portal, object $student): array
    {
        $data = $portal->overview($student);
        return [
            'balancePaise' => (int) $data['balance'],
            'openRequests' => (int) $data['open_requests'],
            'upcoming' => $data['upcoming']->map(fn ($l) => $this->lesson($l))->values(),
            'results' => $data['results']->map(fn ($r) => $this->result($r))->values(),
        ];
    }

    private function classes(ParentPortal $portal, object $student): array
    {
        $data = $portal->classes($student);
        return [
            'upcoming' => $data['upcoming']->map(fn ($l) => $this->lesson($l))->values(),
            'past' => $data['past']->map(fn ($l) => $this->lesson($l) + [
                'status' => $l->status,
                'attendanceId' => $l->attendance_id ? (int) $l->attendance_id : null,
                'minutes' => $l->attendance_id ? (int) $l->actual_minutes : null,
                'beingChecked' => $l->parent_review_status === 'disputed',
                'querySent' => (bool) $l->open_request_id,
            ])->values(),
        ];
    }

    private function results(ParentPortal $portal, object $student, Request $request): array
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $page = $portal->results($student)->paginate(25);
        return [
            'data' => collect($page->items())->map(fn ($r) => $this->result($r))->values(),
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ];
    }

    private function fees(ParentPortal $portal, object $student): array
    {
        $data = $portal->fees($student);
        return [
            'balancePaise' => (int) $data['balance'],
            'requests' => $data['requests']->map(fn ($r) => [
                'id' => (int) $r->id,
                'reference' => $r->reference,
                'requestedPaise' => (int) $r->requested_paise,
                'paidPaise' => (int) $r->covered_paise,
                'outstandingPaise' => (int) $r->outstanding_paise,
                'dueOn' => $r->due_on,
                // The service merges the coverage summary under the row, so the row's own status ("open") wins there; use the summary's status rule.
                'status' => $r->status === 'cancelled' ? 'cancelled' : ((int) $r->covered_paise >= (int) $r->requested_paise ? 'fulfilled' : 'pending'),
            ])->values(),
            'invoices' => $data['invoices']->map(fn ($i) => [
                'id' => (int) $i->id,
                'number' => $i->invoice_number,
                'issueDate' => $i->issue_date,
                'period' => $i->billing_period,
                'totalPaise' => (int) $i->total_paise,
                'isCreditNote' => $i->document_type === 'credit_note',
                'isCancelled' => $i->status === 'cancelled',
            ])->values(),
            'receipts' => $data['documents']->map(fn ($e) => [
                'id' => (int) $e->id,
                'date' => CarbonImmutable::parse($e->occurred_at)->format('Y-m-d'),
                'reference' => $e->reference,
                'isRefund' => $e->kind === 'refund',
                'amountPaise' => abs((int) $e->amount_paise),
                'isWithdrawn' => in_array($e->id, $data['reversed']),
            ])->values(),
            'months' => $data['months']->map(fn ($m) => ['month' => $m, 'label' => CarbonImmutable::createFromFormat('!Y-m', $m)->format('F Y')])->values(),
        ];
    }

    /** The same lookups as the classic document() action, so another family's id is a 404. */
    private function documentData(object $student, string $type, int $id): ?array
    {
        $business = DB::table('erp_units')->where('id', $student->unit_id)->first(['name', 'phone', 'legal_name', 'address', 'state']);
        $centre = ['name' => $business->name, 'phone' => $business->phone ?: null];
        if ($type === 'receipt') {
            $entry = DB::table('erp_ledger')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->where('id', $id)->whereIn('kind', ['payment', 'refund'])->first();
            if (!$entry) return null;
            return ['type' => 'receipt', 'centre' => $centre, 'receipt' => [
                'reference' => $entry->reference,
                'isRefund' => $entry->kind === 'refund',
                'amountPaise' => abs((int) $entry->amount_paise),
                'date' => CarbonImmutable::parse($entry->occurred_at)->format('Y-m-d'),
                'isWithdrawn' => DB::table('erp_ledger')->where('reverses_id', $entry->id)->exists(),
            ]];
        }
        if ($type === 'request') {
            $request = DB::table('erp_payment_requests')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->where('id', $id)->first();
            if (!$request) return null;
            $summary = (new MoneyOperations())->requestSummary((int) $student->unit_id, (int) $request->id);
            return ['type' => 'request', 'centre' => $centre, 'request' => [
                'reference' => $request->reference,
                'requestedPaise' => (int) $summary['requested_paise'],
                'paidPaise' => (int) $summary['covered_paise'],
                'outstandingPaise' => (int) $summary['outstanding_paise'],
                'dueOn' => $request->due_on,
                'status' => $summary['status'],
                'notes' => $request->notes ?: null,
            ]];
        }
        if ($type === 'invoice') {
            $invoice = DB::table('erp_invoices')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->where('id', $id)->first();
            if (!$invoice) return null;
            $lines = DB::table('erp_invoice_lines')->where('invoice_id', $invoice->id)->orderBy('id')->get(['id', 'description', 'sac', 'taxable_paise', 'amount_paise']);
            $credited = $invoice->credits_invoice_id ? DB::table('erp_invoices')->where('id', $invoice->credits_invoice_id)->first(['invoice_number', 'issue_date']) : null;
            $title = ['tax_invoice' => 'Tax invoice', 'invoice' => 'Invoice', 'credit_note' => 'Credit note'][$invoice->document_type] ?? 'Invoice';
            $gst = $invoice->document_type === 'tax_invoice' || ($invoice->document_type === 'credit_note' && $invoice->supplier_tax_id);
            return ['type' => 'invoice', 'centre' => $centre, 'invoice' => [
                'title' => $title,
                'number' => $invoice->invoice_number,
                'isCreditNote' => $invoice->document_type === 'credit_note',
                'isCancelled' => $invoice->status === 'cancelled',
                'issueDate' => $invoice->issue_date,
                'periodLabel' => $invoice->billing_period ? CarbonImmutable::createFromFormat('!Y-m', $invoice->billing_period)->format('F Y') : null,
                'credited' => $credited ? ['number' => $credited->invoice_number, 'issueDate' => $credited->issue_date] : null,
                'supplier' => [
                    'name' => $business->legal_name ?: $business->name,
                    'address' => $business->address ?: null,
                    'state' => $business->state ?: null,
                    'taxId' => $invoice->supplier_tax_id ?: null,
                    'phone' => $business->phone ?: null,
                ],
                'billingName' => $invoice->billing_name,
                'placeOfSupply' => $invoice->place_of_supply ?: null,
                'hasGst' => (bool) $gst,
                'gstRatePercent' => Books::GST_RATE_PERCENT,
                'lines' => $lines->map(fn ($l) => ['id' => (int) $l->id, 'description' => $l->description, 'sac' => $l->sac, 'taxablePaise' => (int) $l->taxable_paise, 'amountPaise' => (int) $l->amount_paise])->values(),
                'subtotalPaise' => (int) $invoice->subtotal_paise,
                'igstPaise' => (int) $invoice->igst_paise,
                'cgstPaise' => (int) $invoice->cgst_paise,
                'sgstPaise' => (int) $invoice->sgst_paise,
                'totalPaise' => (int) $invoice->total_paise,
                'amountInWords' => Invoicing::words((int) $invoice->total_paise),
                // Invoice notes are shown to the family only as a credit note's reason, as on the classic page.
                'creditReason' => $invoice->document_type === 'credit_note' && $invoice->notes ? $invoice->notes : null,
            ]];
        }
        return null;
    }

    private function lesson(object $l): array
    {
        return [
            'id' => (int) $l->id,
            'startsAt' => $this->iso($l->starts_at),
            'endsAt' => $this->iso($l->ends_at),
            'subject' => $l->subject_name,
            'teacher' => $l->teacher_name,
            'kind' => in_array($l->kind, ['demo', 'extra'], true) ? $l->kind : 'regular',
        ];
    }

    private function result(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'assessedOn' => CarbonImmutable::parse($r->assessed_on)->format('Y-m-d'),
            'subject' => $r->subject_name,
            'assessment' => $r->assessment,
            'score' => $r->score + 0,
            'maximum' => $r->maximum + 0,
            'note' => $r->notes ?: null,
        ];
    }

    /** Lesson times are stored in UTC; the client shows them in the student's time zone. */
    private function iso(?string $utc): ?string
    {
        return $utc ? CarbonImmutable::parse($utc, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    private function host(array $session, string $title)
    {
        return AppShellController::page($session, $title)->header('X-Robots-Tag', 'noindex')->header('Referrer-Policy', 'no-referrer');
    }

    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    private function signedOut()
    {
        return response()->json(['message' => 'Your session has ended. Sign in again.'], 401)->header('Cache-Control', 'no-store');
    }
}
