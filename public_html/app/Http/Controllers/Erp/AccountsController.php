<?php
namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Access,Books,Invoicing,MoneyInput,TeamPermissions};
use Illuminate\Http\Request;

/** Accounts & tax: chart of accounts, journals, reports, invoices and GST summary for one team. */
class AccountsController extends Controller
{
    private const VIEWS = ['overview', 'journals', 'ledger', 'reports', 'invoices', 'tax', 'chart'];

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
        $message = match ($action) {
            'books-invoice-run' => $result->invoices ? $result->invoices.' invoice(s) issued for '.$result->charges.' class charge(s), dated '.$result->issue_date.'.' : 'No new class charges to invoice for that month.',
            'books-invoice-cancel' => 'Invoice cancelled with credit note '.$result->invoice_number.'. Its charges can be invoiced again.',
            'books-journal' => 'Journal '.$result->reference.' posted.',
            'books-journal-reverse' => 'Reversal '.$result->reference.' posted.',
            default => 'Account saved.',
        };
        return response()->json(['result' => $result, 'message' => $message]);
    }

}
