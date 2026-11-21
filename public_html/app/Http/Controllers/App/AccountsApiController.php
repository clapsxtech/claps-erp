<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Books, TeamPermissions};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON reads for Accounts & tax. Access is enforced by TeamAuthorization (page "accounts").
 * Every figure comes from the Books read methods; this controller only shapes them.
 * Writes use the books-* actions (AccountsController::mutate).
 */
class AccountsApiController extends Controller
{
    /** Most operational ledger rows posted to the books per overview load. */
    private const PAGE_SYNC_LIMIT = 500;
    private const ACTIONS = ['books-account', 'books-journal', 'books-journal-reverse', 'books-invoice-run', 'books-invoice-cancel'];

    private function range(Request $request, object $business): array
    {
        $v = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $today = CarbonImmutable::now($business->timezone);
        $from = $v['from'] ?? $today->startOfMonth()->toDateString();
        $to = $v['to'] ?? $today->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    private function account(object $r): array
    {
        return ['id' => (int) $r->id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type];
    }

    /**
     * Overview: trial balance and P&L totals. It first posts a bounded
     * batch of operational ledger rows into the books (Books::sync), so figures stay current for
     * people who only use the app; anything left is reported as pendingPosts.
     */
    public function overview(Request $request, int $unit)
    {
        $business = DB::table('erp_units')->find($unit);
        $books = new Books();
        $books->sync($unit, self::PAGE_SYNC_LIMIT);
        [$from, $to] = $this->range($request, $business);
        $trial = $books->trialBalance($unit, $to);
        $pnl = $books->profitAndLoss($unit, $from, $to);
        $permissions = new TeamPermissions();
        $user = $request->user();
        $can = [];
        foreach (self::ACTIONS as $action) $can[$action] = !$business->archived_at && $permissions->allows($user, $unit, 'action', $action);
        return response()->json([
            'team' => ['taxMode' => $business->tax_mode ?? 'none', 'taxId' => $business->tax_id ?? null, 'state' => $business->state ?? null, 'gstRatePercent' => Books::GST_RATE_PERCENT, 'sac' => Books::SAC_TUITION],
            'from' => $from,
            'to' => $to,
            'pendingPosts' => $books->pendingCount($unit),
            'trial' => [
                'rows' => $trial['rows']->map(fn ($r) => $this->account($r) + ['debitPaise' => max((int) $r->balance, 0), 'creditPaise' => max(-(int) $r->balance, 0)])->values(),
                'debitPaise' => (int) $trial['debit'],
                'creditPaise' => (int) $trial['credit'],
                'isBalanced' => (int) $trial['debit'] === (int) $trial['credit'],
            ],
            'pnl' => ['incomePaise' => (int) $pnl['total_income'], 'expensePaise' => (int) $pnl['total_expense'], 'netPaise' => (int) $pnl['net']],
            'types' => Books::TYPES,
            'can' => $can,
        ]);
    }

    public function chart(Request $request, int $unit)
    {
        return response()->json([
            'data' => DB::table('erp_accounts')->where('unit_id', $unit)->orderBy('code')->get(['id', 'code', 'name', 'type', 'system_key', 'active'])
                ->map(fn ($r) => $this->account($r) + ['isSystem' => $r->system_key !== null, 'isActive' => (bool) $r->active])->values(),
        ]);
    }

    public function journals(Request $request, int $unit)
    {
        $v = $request->validate(['source' => 'nullable|in:manual,ledger', 'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|in:25,50,100']);
        $query = DB::table('erp_journal_entries')->where('unit_id', $unit);
        if (!empty($v['source'])) $query->where('source_type', $v['source']);
        $page = $query->orderByDesc('entry_date')->orderByDesc('id')->paginate((int) ($v['per_page'] ?? 25), ['id', 'entry_date', 'reference', 'memo', 'source_type', 'reverses_id'], 'page', (int) ($v['page'] ?? 1));
        $ids = collect($page->items())->pluck('id');
        $lines = DB::table('erp_journal_lines as l')->join('erp_accounts as a', 'a.id', '=', 'l.account_id')->whereIn('l.entry_id', $ids)->orderBy('l.id')
            ->get(['l.entry_id', 'l.debit_paise', 'l.credit_paise', 'a.code', 'a.name'])->groupBy('entry_id');
        $reversed = DB::table('erp_journal_entries')->where('unit_id', $unit)->whereIn('reverses_id', $ids)->pluck('reverses_id')->map(fn ($id) => (int) $id)->all();
        return response()->json([
            'data' => collect($page->items())->map(function ($e) use ($lines, $reversed) {
                $entryLines = collect($lines[$e->id] ?? [])->map(fn ($l) => ['accountCode' => $l->code, 'accountName' => $l->name, 'debitPaise' => (int) $l->debit_paise, 'creditPaise' => (int) $l->credit_paise])->values();
                return [
                    'id' => (int) $e->id,
                    'entryDate' => $e->entry_date,
                    'reference' => $e->reference,
                    'memo' => $e->memo,
                    'source' => $e->source_type,
                    'reversesId' => $e->reverses_id !== null ? (int) $e->reverses_id : null,
                    'isReversed' => in_array((int) $e->id, $reversed, true),
                    'debitPaise' => (int) $entryLines->sum('debitPaise'),
                    'creditPaise' => (int) $entryLines->sum('creditPaise'),
                    'lines' => $entryLines,
                ];
            })->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => 'entry_date', 'dir' => 'desc'],
        ]);
    }

    public function ledger(Request $request, int $unit)
    {
        $business = DB::table('erp_units')->find($unit);
        $v = $request->validate(['account_id' => 'nullable|integer|min:1']);
        [$from, $to] = $this->range($request, $business);
        $books = new Books();
        $ledger = $books->generalLedger($unit, (int) ($v['account_id'] ?? $books->account($unit, 'bank')), $from, $to);
        return response()->json([
            'from' => $from,
            'to' => $to,
            'account' => $ledger['account'] ? $this->account($ledger['account']) : null,
            'openingPaise' => (int) $ledger['opening'],
            'lines' => $ledger['lines']->map(fn ($l) => [
                'entryId' => (int) $l->entry_id, 'entryDate' => $l->entry_date, 'reference' => $l->reference, 'memo' => $l->memo,
                'studentName' => $l->student_name, 'teacherName' => $l->teacher_name,
                'debitPaise' => (int) $l->debit_paise, 'creditPaise' => (int) $l->credit_paise, 'runningPaise' => (int) $l->running,
            ])->values(),
        ]);
    }

    public function reports(Request $request, int $unit)
    {
        $business = DB::table('erp_units')->find($unit);
        [$from, $to] = $this->range($request, $business);
        $books = new Books();
        $pnl = $books->profitAndLoss($unit, $from, $to);
        $sheet = $books->balanceSheet($unit, $to);
        $rows = fn ($list) => collect($list)->map(fn ($r) => $this->account($r) + ['amountPaise' => (int) $r->amount])->values();
        return response()->json([
            'from' => $from,
            'to' => $to,
            'pnl' => ['income' => $rows($pnl['income']), 'expense' => $rows($pnl['expense']), 'incomePaise' => (int) $pnl['total_income'], 'expensePaise' => (int) $pnl['total_expense'], 'netPaise' => (int) $pnl['net']],
            'sheet' => [
                'assets' => $rows($sheet['assets']), 'liabilities' => $rows($sheet['liabilities']), 'equity' => $rows($sheet['equity']),
                'earningsPaise' => (int) $sheet['earnings'], 'assetsPaise' => (int) $sheet['total_assets'], 'liabilitiesEquityPaise' => (int) $sheet['total_liabilities_equity'],
            ],
        ]);
    }

    public function invoices(Request $request, int $unit)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:issued,cancelled',
            'type' => 'nullable|in:invoice,tax_invoice,credit_note',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $query = DB::table('erp_invoices as i')->join('erp_students as s', 's.id', '=', 'i.student_id')->where('i.unit_id', $unit)
            ->select('i.id', 'i.invoice_number', 'i.document_type', 'i.issue_date', 'i.billing_period', 'i.subtotal_paise', 'i.tax_paise', 'i.total_paise', 'i.status', 's.name as student_name', 's.code as student_code');
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('i.invoice_number', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')->orWhere('s.code', 'like', '%'.$search.'%'));
        if (!empty($v['status'])) $query->where('i.status', $v['status']);
        if (!empty($v['type'])) $query->where('i.document_type', $v['type']);
        $page = $query->orderByDesc('i.issue_date')->orderByDesc('i.id')->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1));
        // "Not yet invoiced": lesson charges with no invoice that have not been reversed.
        $uninvoiced = DB::table('erp_ledger as l')->where('l.unit_id', $unit)->where('l.kind', 'lesson_charge')->whereNull('l.invoice_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_ledger as r')->whereColumn('r.reverses_id', 'l.id'))
            ->selectRaw('COUNT(*) AS charges, COALESCE(SUM(-l.amount_paise),0) AS total')->first();
        return response()->json([
            'data' => collect($page->items())->map(fn ($i) => [
                'id' => (int) $i->id, 'number' => $i->invoice_number, 'documentType' => $i->document_type, 'issueDate' => $i->issue_date,
                'billingPeriod' => $i->billing_period, 'studentName' => $i->student_name, 'studentCode' => $i->student_code,
                'taxablePaise' => (int) $i->subtotal_paise, 'taxPaise' => (int) $i->tax_paise, 'totalPaise' => (int) $i->total_paise, 'status' => $i->status,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => 'issue_date', 'dir' => 'desc'],
            'uninvoiced' => ['charges' => (int) $uninvoiced->charges, 'totalPaise' => (int) $uninvoiced->total],
        ]);
    }

    public function tax(Request $request, int $unit)
    {
        $business = DB::table('erp_units')->find($unit);
        $v = $request->validate(['month' => 'nullable|date_format:Y-m']);
        $month = $v['month'] ?? CarbonImmutable::now($business->timezone)->format('Y-m');
        $tax = (new Books())->taxSummary($unit, $month);
        $split = fn (array $s) => ['taxablePaise' => (int) $s['taxable'], 'cgstPaise' => (int) $s['cgst'], 'sgstPaise' => (int) $s['sgst'], 'igstPaise' => (int) $s['igst']];
        return response()->json([
            'month' => $month,
            'booked' => $split($tax['booked']),
            'issued' => $split($tax['issued']),
            'documents' => (int) $tax['documents'],
            'creditNotes' => (int) $tax['credit_notes'],
            'byState' => collect($tax['by_state'])->map(fn ($r, $state) => ['state' => (string) $state, 'documents' => (int) $r['documents'], 'taxablePaise' => (int) $r['taxable'], 'taxPaise' => (int) $r['tax']])->values(),
        ]);
    }
}
