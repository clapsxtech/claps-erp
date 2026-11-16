<?php
namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Access,Books,Invoicing,MoneyInput,TeamPermissions};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Accounts & tax: chart of accounts, journals, reports, invoices and GST summary for one team. */
class AccountsController extends Controller
{
    private const PAGE_SYNC_LIMIT = 500;
    private const VIEWS = ['overview', 'journals', 'ledger', 'reports', 'invoices', 'tax', 'chart'];

    public function index(Request $request, int $unit)
    {
        (new Access())->unit($request->user(), $unit, false);
        $business = DB::table('erp_units')->find($unit);
        $books = new Books();
        // Page loads post a bounded batch so the page stays fast; anything left is shown and posted on the next load or invoice run.
        $books->sync($unit, self::PAGE_SYNC_LIMIT);
        $pendingPosts = $books->pendingCount($unit);
        $v = $request->validate(['view' => 'nullable|in:'.implode(',', self::VIEWS), 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'month' => 'nullable|date_format:Y-m', 'account_id' => 'nullable|integer']);
        $view = $v['view'] ?? 'overview';
        $today = CarbonImmutable::now($business->timezone);
        $from = $v['from'] ?? $today->startOfMonth()->toDateString(); $to = $v['to'] ?? $today->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];
        $month = $v['month'] ?? $today->format('Y-m');
        $accounts = DB::table('erp_accounts')->where('unit_id', $unit)->orderBy('code')->get();
        $data = match ($view) {
            'overview' => ['trial' => $books->trialBalance($unit, $to), 'pnl' => $books->profitAndLoss($unit, $from, $to)],
            'journals' => ['entries' => DB::table('erp_journal_entries')->where('unit_id', $unit)->orderByDesc('entry_date')->orderByDesc('id')->paginate(30)->withQueryString()],
            'ledger' => ['ledger' => $books->generalLedger($unit, (int) ($v['account_id'] ?? $books->account($unit, 'bank')), $from, $to)],
            'reports' => ['pnl' => $books->profitAndLoss($unit, $from, $to), 'sheet' => $books->balanceSheet($unit, $to)],
            'invoices' => ['invoices' => DB::table('erp_invoices as i')->join('erp_students as s', 's.id', '=', 'i.student_id')->where('i.unit_id', $unit)->orderByDesc('i.issue_date')->orderByDesc('i.id')->select('i.*', 's.name as student_name', 's.code as student_code')->paginate(30)->withQueryString(),
                'uninvoiced' => DB::table('erp_ledger as l')->where('l.unit_id', $unit)->where('l.kind', 'lesson_charge')->whereNull('l.invoice_id')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_ledger as r')->whereColumn('r.reverses_id', 'l.id'))->selectRaw('COUNT(*) AS charges, COALESCE(SUM(-l.amount_paise),0) AS total')->first()],
            'tax' => ['tax' => $books->taxSummary($unit, $month)],
            'chart' => [],
        };
        if ($view === 'journals') {
            $data['lines'] = DB::table('erp_journal_lines as l')->join('erp_accounts as a', 'a.id', '=', 'l.account_id')->whereIn('l.entry_id', $data['entries']->pluck('id'))->orderBy('l.id')->get(['l.*', 'a.code', 'a.name'])->groupBy('entry_id');
            $data['reversed'] = DB::table('erp_journal_entries')->whereIn('reverses_id', $data['entries']->pluck('id'))->pluck('reverses_id')->all();
        }
        $permissions = new TeamPermissions();
        $page = 'accounts';
        return view('erp.accounts', compact('business', 'view', 'data', 'accounts', 'from', 'to', 'month', 'permissions', 'page', 'pendingPosts'));
    }

    public function mutate(Request $request, int $unit, string $action)
    {
        (new Access())->unit($request->user(), $unit);
        $actor = (int) $request->user()->id; $p = $request->except('_token', 'return_page');
        $result = match ($action) {
            'books-account' => (new Books())->createAccount($unit, $actor, $p),
            'books-journal' => (new Books())->manualJournal($unit, $actor, ['lines' => array_map(fn ($l) => ['account_id' => $l['account_id'] ?? null,
                'debit_paise' => ($l['debit_inr'] ?? '') === '' || ($l['debit_inr'] ?? null) === null ? 0 : MoneyInput::toPaise($l['debit_inr'], 'lines', 1000000000, true),
                'credit_paise' => ($l['credit_inr'] ?? '') === '' || ($l['credit_inr'] ?? null) === null ? 0 : MoneyInput::toPaise($l['credit_inr'], 'lines', 1000000000, true)], array_values((array) ($p['lines'] ?? [])))] + $p),
            'books-journal-reverse' => (new Books())->reverseManual($unit, $actor, $p),
            'books-invoice-run' => (new Invoicing())->runMonth($unit, $actor, $p),
            'books-invoice-cancel' => (new Invoicing())->cancel($unit, $actor, $p),
            default => abort(404),
        };
        if ($request->expectsJson()) return response()->json(['result' => $result]);
        $message = match ($action) {
            'books-invoice-run' => $result->invoices ? $result->invoices.' invoice(s) issued for '.$result->charges.' class charge(s), dated '.$result->issue_date.'.' : 'No new class charges to invoice for that month.',
            'books-invoice-cancel' => 'Invoice cancelled with credit note '.$result->invoice_number.'. Its charges can be invoiced again.',
            'books-journal' => 'Journal '.$result->reference.' posted.',
            'books-journal-reverse' => 'Reversal '.$result->reference.' posted.',
            default => 'Account saved.',
        };
        $view = ['books-invoice-run' => 'invoices', 'books-invoice-cancel' => 'invoices', 'books-journal' => 'journals', 'books-journal-reverse' => 'journals', 'books-account' => 'chart'][$action];
        return redirect()->route('erp.business', [$unit, 'accounts', 'view' => $view])->with('status', $message);
    }

    public function invoice(Request $request, int $unit, int $id)
    {
        (new Access())->unit($request->user(), $unit, false);
        $business = DB::table('erp_units')->find($unit);
        $invoice = DB::table('erp_invoices')->where('unit_id', $unit)->where('id', $id)->first();
        abort_unless($invoice, 404);
        $student = DB::table('erp_students')->find($invoice->student_id);
        $lines = DB::table('erp_invoice_lines')->where('invoice_id', $invoice->id)->orderBy('id')->get();
        $credited = $invoice->credits_invoice_id ? DB::table('erp_invoices')->find($invoice->credits_invoice_id) : null;
        return view('erp.invoice', compact('business', 'invoice', 'student', 'lines', 'credited'));
    }
}
