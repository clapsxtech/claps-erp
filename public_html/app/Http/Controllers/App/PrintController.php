<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Access, Books, Invoicing, MoneyDisplay, ParentPortal, Reporting, TeamPermissions, TuitionWorkflows};
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Printable staff documents for the React app: payment/refund acknowledgement,
 * invoice or credit note, payment request and the monthly parent statement.
 * Each read mirrors the classic Blade view field for field and formats the
 * display text with the same helpers, so the printed words do not change.
 */
class PrintController extends Controller
{
    /** Host page for every /print URL. The documents themselves load from the JSON reads below. */
    public function host(Request $request)
    {
        (new SiteAccessPolicy())->assertSiteMember($request->user());
        return AppShellController::page(['kind' => 'print'], 'Print · ClapsLearn');
    }

    private static function inr($paise): string
    {
        return MoneyDisplay::inr((int) $paise);
    }

    private static function business(int $unit): object
    {
        return DB::table('erp_units')->where('id', $unit)->first(['id', 'name', 'legal_name', 'address', 'state', 'phone', 'timezone']);
    }

    /** Classic WorkspaceController::receipt. */
    public function receipt(Request $request, int $unit, int $entry)
    {
        (new Access())->unit($request->user(), $unit, false);
        $receipt = DB::table('erp_ledger')->where('unit_id', $unit)->where('id', $entry)->whereIn('kind', ['payment', 'refund'])->first(['id', 'student_id', 'kind', 'amount_paise', 'reference', 'occurred_at']);
        abort_unless($receipt, 404);
        $student = DB::table('erp_students')->where('unit_id', $unit)->where('id', $receipt->student_id)->first(['name', 'code']);
        $business = self::business($unit);
        $reversal = DB::table('erp_ledger')->where('unit_id', $unit)->where('reverses_id', $receipt->id)->first(['reference']);
        $correction = DB::table('erp_audit')->where('unit_id', $unit)->where('entity_type', 'ledger')->where('entity_id', $receipt->id)->where('action', 'receipt.corrected')->orderByDesc('id')->first(['payload']);
        $replacement = $correction ? json_decode($correction->payload, true) : null;
        $refund = $receipt->kind === 'refund';
        return response()->json([
            'id' => (int) $receipt->id,
            'kind' => $receipt->kind,
            'title' => $refund ? 'Refund acknowledgement' : 'Payment acknowledgement',
            'businessName' => $business->name,
            'businessPhone' => $business->phone ?: null,
            'reference' => $receipt->reference,
            'studentName' => $student->name ?? null,
            'studentCode' => $student->code ?? null,
            'amountLabel' => $refund ? 'Paid back to the family' : 'Received',
            'amount' => $refund ? self::inr(abs((int) $receipt->amount_paise)) : self::inr($receipt->amount_paise),
            'recordedAt' => $receipt->occurred_at,
            'installationTimezone' => config('app.timezone'),
            'statement' => ($refund ? 'This acknowledges a refund already paid from the student\'s credit balance.' : 'This acknowledges a recorded payment.').' It is not a tax invoice or a payment request.',
            'reversal' => $reversal ? ['reference' => $reversal->reference] : null,
            'replacement' => $reversal && is_array($replacement) && isset($replacement['replacement_id']) ? ['id' => (int) $replacement['replacement_id'], 'reference' => (string) ($replacement['new_reference'] ?? '')] : null,
        ])->header('Cache-Control', 'no-store');
    }

    /** Classic AccountsController::invoice (staff copy). */
    public function invoice(Request $request, int $unit, int $id)
    {
        (new Access())->unit($request->user(), $unit, false);
        $business = self::business($unit);
        $invoice = DB::table('erp_invoices')->where('unit_id', $unit)->where('id', $id)->first();
        abort_unless($invoice, 404);
        $student = DB::table('erp_students')->where('unit_id', $unit)->where('id', $invoice->student_id)->first(['code']);
        $lines = DB::table('erp_invoice_lines')->where('invoice_id', $invoice->id)->orderBy('id')->get(['description', 'sac', 'taxable_paise', 'amount_paise']);
        $credited = $invoice->credits_invoice_id ? DB::table('erp_invoices')->where('unit_id', $unit)->where('id', $invoice->credits_invoice_id)->first(['invoice_number', 'issue_date']) : null;
        $title = ['tax_invoice' => 'Tax invoice', 'invoice' => 'Invoice', 'credit_note' => 'Credit note'][$invoice->document_type] ?? 'Invoice';
        $gst = $invoice->document_type === 'tax_invoice' || ($invoice->document_type === 'credit_note' && $invoice->supplier_tax_id);
        $rate = Books::GST_RATE_PERCENT;
        $taxes = [];
        if ($gst) {
            $taxes[] = ['label' => 'Taxable value', 'amount' => self::inr($invoice->subtotal_paise)];
            if ($invoice->igst_paise) $taxes[] = ['label' => 'IGST '.$rate.'%', 'amount' => self::inr($invoice->igst_paise)];
            else {
                $taxes[] = ['label' => 'CGST '.($rate / 2).'%', 'amount' => self::inr($invoice->cgst_paise)];
                $taxes[] = ['label' => 'SGST '.($rate / 2).'%', 'amount' => self::inr($invoice->sgst_paise)];
            }
        }
        return response()->json([
            'id' => (int) $invoice->id,
            'title' => $title,
            'documentType' => $invoice->document_type,
            'number' => $invoice->invoice_number,
            'isCancelled' => $invoice->status === 'cancelled',
            'supplier' => [
                'name' => $business->legal_name ?: $business->name,
                'address' => $business->address ?: null,
                'state' => $business->state ?: null,
                'taxId' => $invoice->supplier_tax_id ?: null,
                'phone' => $business->phone ?: null,
            ],
            'issueDate' => CarbonImmutable::parse($invoice->issue_date)->format('j M Y'),
            'billingPeriod' => $invoice->billing_period ? CarbonImmutable::createFromFormat('!Y-m', $invoice->billing_period)->format('F Y') : null,
            'credited' => $credited ? ['number' => $credited->invoice_number, 'issueDate' => $credited->issue_date] : null,
            'billingName' => $invoice->billing_name,
            'studentCode' => $student->code ?? null,
            'placeOfSupply' => $invoice->place_of_supply ?: null,
            'hasGst' => (bool) $gst,
            'lines' => $lines->values()->map(fn ($line, $i) => [
                'number' => $i + 1,
                'description' => $line->description,
                'sac' => $line->sac,
                'taxable' => self::inr($line->taxable_paise),
                'amount' => self::inr($line->amount_paise),
            ]),
            'taxes' => $taxes,
            'totalLabel' => $invoice->document_type === 'credit_note' ? 'Total credited' : 'Total',
            'total' => self::inr($invoice->total_paise),
            'words' => Invoicing::words((int) $invoice->total_paise),
            'taxNote' => $gst ? 'Class fees include GST at '.$rate.'%. Supply of commercial training and coaching services. Tax is not payable on reverse charge.' : 'No tax is charged on this invoice.',
            'reason' => $invoice->document_type === 'credit_note' && $invoice->notes ? $invoice->notes : null,
        ])->header('Cache-Control', 'no-store');
    }

    /** Classic BusinessController::requestPrint. */
    public function request(Request $request, int $unit, int $id)
    {
        (new Access())->unit($request->user(), $unit, false);
        $paymentRequest = DB::table('erp_payment_requests')->where('unit_id', $unit)->where('id', $id)->first(['id', 'student_id', 'reference', 'due_on', 'notes']);
        abort_unless($paymentRequest, 404);
        $student = DB::table('erp_students')->where('unit_id', $unit)->where('id', $paymentRequest->student_id)->first(['name', 'code']);
        $business = self::business($unit);
        $summary = (new Reporting())->requestSummaries($unit, [$id])[$id];
        return response()->json([
            'id' => (int) $paymentRequest->id,
            'businessName' => $business->name,
            'businessPhone' => $business->phone ?: null,
            'studentName' => $student->name ?? null,
            'studentCode' => $student->code ?? null,
            'reference' => $paymentRequest->reference,
            'status' => $summary['status'],
            'requested' => self::inr($summary['requested_paise']),
            'covered' => self::inr($summary['covered_paise']),
            'outstanding' => self::inr($summary['outstanding_paise']),
            'due' => $paymentRequest->due_on ?? 'Not specified',
            'notes' => $paymentRequest->notes,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Classic TuitionController::statement. Private: the same explicit role check
     * (superadmin, admin, team manager) as the classic tuition screens.
     */
    public function statement(Request $request, int $unit)
    {
        (new Access())->unit($request->user(), $unit, false);
        abort_unless(in_array((new TeamPermissions())->role($request->user(), $unit), ['superadmin', 'admin', 'team_manager'], true), 403, 'Private operations are restricted to administrators and team managers.');
        $v = $request->validate(['student_id' => 'required|integer|min:1', 'month' => 'required|date_format:Y-m']);
        $student = DB::table('erp_students')->where('unit_id', $unit)->where('id', $v['student_id'])->first();
        abort_unless($student, 404);
        $business = self::business($unit);
        ['rows' => $rows, 'account' => $account] = ParentPortal::statement($unit, $student, $v['month']);
        $balance = fn (int $p) => MoneyDisplay::inr(abs($p)).($p > 0 ? ' credit' : ($p < 0 ? ' due' : ''));
        $kinds = [];
        foreach (['payment' => 'Payments received', 'lesson_charge' => 'Class charges posted', 'refund' => 'Refunds paid back', 'opening_balance' => 'Opening balance brought in', 'reversal' => 'Corrections and reversals'] as $kind => $label) {
            if ($account['kinds']->has($kind)) $kinds[] = ['label' => $label, 'amount' => MoneyDisplay::inr($account['kinds'][$kind])];
        }
        $submitted = $rows->where('status', 'submitted');
        $canRecord = !DB::table('erp_units')->where('id', $unit)->whereNotNull('archived_at')->exists();
        return response()->json([
            'businessName' => $business->name,
            'businessTimezone' => $business->timezone ?: 'UTC',
            'month' => $v['month'],
            'student' => ['id' => (int) $student->id, 'name' => $student->name, 'code' => $student->code, 'timezone' => $student->timezone],
            'rows' => $rows->values()->map(fn ($row) => [
                'id' => (int) $row->id,
                'when' => Carbon::parse($row->actual_starts_at ?? $row->starts_at, 'UTC')->setTimezone($student->timezone)->format('d M Y H:i'),
                'teacher' => $row->teacher_name,
                'subject' => $row->subject_name.($row->kind === 'demo' ? ' (free demo)' : ''),
                'minutes' => (int) $row->actual_minutes,
                'charge' => '₹'.number_format(($row->kind === 'demo' ? 0 : intdiv($row->actual_minutes * $row->student_rate_paise + 30, 60)) / 100, 2),
                'status' => $row->status,
            ]),
            'totalMinutes' => (int) $rows->sum('actual_minutes'),
            'account' => ['opening' => $balance($account['opening']), 'lines' => $kinds, 'closing' => $balance($account['closing'])],
            // Exactly the entries the classic "After sharing" form posts to monthly-review.
            'review' => [
                'canRecord' => $canRecord && config('sheet_transition.authority') !== 'sheets',
                'entries' => $submitted->values()->map(fn ($row) => ['attendanceId' => (int) $row->id, 'snapshot' => TuitionWorkflows::reviewSnapshot($row)]),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
