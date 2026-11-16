@extends('erp.layout')
@section('content')
@php
$inr=fn($p)=>\App\Modules\Erp\Services\MoneyDisplay::inr((int)$p);
$can=fn($a)=>!$business->archived_at && $permissions->allows(auth()->user(),$business->id,'action',$a);
$dc=fn($b)=>$b>=0?[$inr($b),'']:['',$inr(-$b)];
$gst=$business->tax_mode==='gst';
@endphp
<div class="page-heading business-heading"><div><div class="eyebrow">{{ $business->name }}</div><h1>Accounts &amp; tax</h1><p>Double-entry books kept automatically from every charge, receipt, refund and teacher payment, plus your own journals. {{ $gst ? 'GST: '.$business->tax_id.' · '.$business->state.' · rates include '.\App\Modules\Erp\Services\Books::GST_RATE_PERCENT.'% GST.' : 'No tax is charged in this team.' }}</p></div></div>
@if(!empty($pendingPosts))<p class="notice" role="status">{{ number_format($pendingPosts) }} more transaction(s) are still being added to the books, so these figures are not yet complete. Reload this page to continue; running invoices posts them all first.</p>@endif
<nav class="tabs" aria-label="Accounts sections">@foreach(['overview'=>'Overview','journals'=>'Journals','ledger'=>'General ledger','reports'=>'P&L and balance sheet','invoices'=>'Invoices','tax'=>$gst?'GST summary':'Tax summary','chart'=>'Chart of accounts'] as $key=>$label)<a href="{{ route('erp.business',[$business->id,'accounts','view'=>$key]) }}" @if($view===$key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>

@if(in_array($view,['overview','ledger','reports']))
<form method="get" aria-label="Dates"><input type="hidden" name="view" value="{{ $view }}">@if($view==='ledger')<label>Account<select name="account_id">@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(($data['ledger']['account']->id??null)==$account->id)>{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></label>@endif<label>From<input type="date" name="from" value="{{ $from }}"></label><label>To<input type="date" name="to" value="{{ $to }}"></label><button>Show</button></form>
@endif

@if($view==='overview')
<div class="metrics"><section><strong>{{ $inr($data['pnl']['total_income']) }}</strong><p>Income {{ $from }} to {{ $to }}</p></section><section><strong>{{ $inr($data['pnl']['total_expense']) }}</strong><p>Expenses</p></section><section><strong>{{ $inr($data['pnl']['net']) }}</strong><p>{{ $data['pnl']['net']>=0?'Surplus':'Deficit' }}</p></section><section><strong>{{ $data['trial']['debit']===$data['trial']['credit'] ? 'Balanced' : 'Out of balance' }}</strong><p>Trial balance at {{ $to }}</p></section></div>
<section><h3>Trial balance at {{ $to }}</h3><div class="scroll"><table><thead><tr><th>Code</th><th>Account</th><th>Debit</th><th>Credit</th></tr></thead><tbody>
@forelse($data['trial']['rows'] as $row)@php [$d,$c]=$dc($row->balance); @endphp<tr><td>{{ $row->code }}</td><td><a href="{{ route('erp.business',[$business->id,'accounts','view'=>'ledger','account_id'=>$row->id,'to'=>$to]) }}">{{ $row->name }}</a></td><td>{{ $d }}</td><td>{{ $c }}</td></tr>@empty<tr><td colspan="4">No entries yet.</td></tr>@endforelse
<tr><td></td><td><strong>Total</strong></td><td><strong>{{ $inr($data['trial']['debit']) }}</strong></td><td><strong>{{ $inr($data['trial']['credit']) }}</strong></td></tr></tbody></table></div></section>
@endif

@if($view==='journals')
@if($can('books-journal'))<details id="action-journal"><summary>Record a manual journal (rent, bank charges, GST paid, capital…)</summary><form method="post" action="{{ route('erp.business.mutate',[$business->id,'books-journal']) }}" style="flex-direction:column;align-items:stretch">@csrf
<label>Date<input type="date" name="entry_date" required value="{{ now($business->timezone)->toDateString() }}"></label><label>Description<input name="memo" maxlength="500" required placeholder="For example: October rent paid by bank transfer"></label>
<div class="scroll"><table><thead><tr><th>Account</th><th>Debit (INR)</th><th>Credit (INR)</th></tr></thead><tbody>@for($i=0;$i<4;$i++)<tr><td><select name="lines[{{ $i }}][account_id]" aria-label="Account line {{ $i+1 }}"><option value="">Choose</option>@foreach($accounts->where('active',true) as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></td><td><input name="lines[{{ $i }}][debit_inr]" inputmode="decimal" aria-label="Debit line {{ $i+1 }}"></td><td><input name="lines[{{ $i }}][credit_inr]" inputmode="decimal" aria-label="Credit line {{ $i+1 }}"></td></tr>@endfor</tbody></table></div>
<p><small>Debits must equal credits. Operational money (receipts, refunds, charges, teacher pay) is recorded in Money and posted here automatically.</small></p><button type="submit">Post journal</button></form></details>@endif
<div class="scroll"><table><thead><tr><th>Date</th><th>Reference</th><th>Description</th><th>Lines</th><th></th></tr></thead><tbody>
@forelse($data['entries'] as $entry)<tr><td>{{ $entry->entry_date }}</td><td>{{ $entry->reference }}</td><td>{{ $entry->memo }}<br><small>{{ $entry->source_type==='manual'?'Manual journal':'From Money' }}</small></td><td>@foreach($data['lines'][$entry->id]??[] as $line){{ $line->code }} {{ $line->name }}: {{ $line->debit_paise?'Dr '.$inr($line->debit_paise):'Cr '.$inr($line->credit_paise) }}<br>@endforeach</td>
<td>@if($entry->source_type==='manual' && !$entry->reverses_id && !in_array($entry->id,$data['reversed'],true) && $can('books-journal-reverse'))<details><summary>Reverse</summary><form method="post" action="{{ route('erp.business.mutate',[$business->id,'books-journal-reverse']) }}">@csrf<input type="hidden" name="entry_id" value="{{ $entry->id }}"><label>Reason<input name="reason" maxlength="500" required></label><button>Reverse journal</button></form></details>@elseif(in_array($entry->id,$data['reversed'],true))<small>Reversed</small>@endif</td></tr>
@empty<tr><td colspan="5">No journals yet.</td></tr>@endforelse</tbody></table></div>{{ $data['entries']->links('pagination::simple-bootstrap-4') }}
@endif

@if($view==='ledger')
@php $ledger=$data['ledger']; @endphp
@if($ledger['account'])<section><h3>{{ $ledger['account']->code }} · {{ $ledger['account']->name }}</h3><div class="scroll"><table><thead><tr><th>Date</th><th>Reference</th><th>Description</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead><tbody>
<tr><td>{{ $from }}</td><td></td><td>Opening balance</td><td></td><td></td><td>{{ $inr($ledger['opening']) }}</td></tr>
@forelse($ledger['lines'] as $line)<tr><td>{{ $line->entry_date }}</td><td>{{ $line->reference }}</td><td>{{ $line->memo }}@if($line->student_name) · {{ $line->student_name }}@endif @if($line->teacher_name) · {{ $line->teacher_name }}@endif</td><td>{{ $line->debit_paise?$inr($line->debit_paise):'' }}</td><td>{{ $line->credit_paise?$inr($line->credit_paise):'' }}</td><td>{{ $inr($line->running) }}</td></tr>@empty<tr><td colspan="6">No entries in this period.</td></tr>@endforelse
</tbody></table></div><p><small>Balance shown debit-positive.</small></p></section>@endif
@endif

@if($view==='reports')
@php $pnl=$data['pnl']; @endphp @php $sheet=$data['sheet']; @endphp
<section><h3>Profit and loss, {{ $from }} to {{ $to }}</h3><div class="scroll"><table><tbody><tr><th colspan="2">Income</th></tr>@foreach($pnl['income'] as $row)<tr><td>{{ $row->code }} {{ $row->name }}</td><td>{{ $inr($row->amount) }}</td></tr>@endforeach<tr><td><strong>Total income</strong></td><td><strong>{{ $inr($pnl['total_income']) }}</strong></td></tr>
<tr><th colspan="2">Expenses</th></tr>@foreach($pnl['expense'] as $row)<tr><td>{{ $row->code }} {{ $row->name }}</td><td>{{ $inr($row->amount) }}</td></tr>@endforeach<tr><td><strong>Total expenses</strong></td><td><strong>{{ $inr($pnl['total_expense']) }}</strong></td></tr>
<tr><td><strong>{{ $pnl['net']>=0?'Surplus':'Deficit' }}</strong></td><td><strong>{{ $inr($pnl['net']) }}</strong></td></tr></tbody></table></div><p><small>Tuition income is shown net of GST.</small></p></section>
<section><h3>Balance sheet at {{ $to }}</h3><div class="scroll"><table><tbody>
@foreach(['assets'=>'Assets','liabilities'=>'Liabilities','equity'=>'Equity'] as $key=>$label)<tr><th colspan="2">{{ $label }}</th></tr>@foreach($sheet[$key] as $row)<tr><td>{{ $row->code }} {{ $row->name }}</td><td>{{ $inr($row->amount) }}</td></tr>@endforeach @if($key==='equity')<tr><td>Accumulated surplus (income less expenses to date)</td><td>{{ $inr($sheet['earnings']) }}</td></tr>@endif @endforeach
<tr><td><strong>Total assets</strong></td><td><strong>{{ $inr($sheet['total_assets']) }}</strong></td></tr><tr><td><strong>Total liabilities and equity</strong></td><td><strong>{{ $inr($sheet['total_liabilities_equity']) }}</strong></td></tr></tbody></table></div>
<p><small>Student accounts show the net of amounts owed and advances paid; a credit-heavy (negative) figure means families have paid in advance.</small></p></section>
@endif

@if($view==='invoices')
<p class="notice">{{ $data['uninvoiced']->charges }} posted class charge(s) not yet invoiced, totalling {{ $inr($data['uninvoiced']->total) }}.</p>
@if($can('books-invoice-run'))<details id="action-invoice-run" open><summary>Issue invoices for a month</summary><form method="post" action="{{ route('erp.business.mutate',[$business->id,'books-invoice-run']) }}">@csrf<label>Month (class charges posted in)<input type="month" name="month" required value="{{ now($business->timezone)->format('Y-m') }}"></label><button>Issue {{ $gst ? 'tax invoices' : 'invoices' }}</button></form><p><small>One invoice per student for that month's charges not yet invoiced, dated today. Running it again only picks up new charges. {{ $gst ? 'Tax invoices use SAC '.\App\Modules\Erp\Services\Books::SAC_TUITION.' with CGST+SGST, or IGST when the student\'s billing state differs from '.$business->state.'.' : '' }}</small></p></details>@endif
<div class="scroll"><table><thead><tr><th>Number</th><th>Date</th><th>Student</th><th>Period</th><th>Taxable</th><th>Tax</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($data['invoices'] as $invoice)<tr><td><a href="{{ route('erp.invoice',[$business->id,$invoice->id]) }}">{{ $invoice->invoice_number }}</a><br><small>{{ ['tax_invoice'=>'Tax invoice','invoice'=>'Invoice','credit_note'=>'Credit note'][$invoice->document_type] ?? $invoice->document_type }}</small></td><td>{{ $invoice->issue_date }}</td><td>{{ $invoice->student_name }} · {{ $invoice->student_code }}</td><td>{{ $invoice->billing_period }}</td><td>{{ $inr($invoice->subtotal_paise) }}</td><td>{{ $inr($invoice->tax_paise) }}</td><td>{{ $inr($invoice->total_paise) }}</td><td>{{ $invoice->status }}</td>
<td>@if($invoice->document_type!=='credit_note' && $invoice->status==='issued' && $can('books-invoice-cancel'))<details><summary>Cancel</summary><form method="post" action="{{ route('erp.business.mutate',[$business->id,'books-invoice-cancel']) }}">@csrf<input type="hidden" name="invoice_id" value="{{ $invoice->id }}"><label>Reason<input name="reason" maxlength="500" required></label><button>Issue credit note</button></form></details>@endif</td></tr>
@empty<tr><td colspan="9">No invoices yet.</td></tr>@endforelse</tbody></table></div>{{ $data['invoices']->links('pagination::simple-bootstrap-4') }}
@endif

@if($view==='tax')
@php $tax=$data['tax']; @endphp
<form method="get"><input type="hidden" name="view" value="tax"><label>Month<input type="month" name="month" value="{{ $month }}"></label><button>Show</button></form>
<section><h3>{{ $gst ? 'GST' : 'Tax' }} summary for {{ $month }}</h3><div class="scroll"><table><thead><tr><th></th><th>Taxable value</th><th>CGST</th><th>SGST</th><th>IGST</th></tr></thead><tbody>
<tr><td>Booked from class charges</td><td>{{ $inr($tax['booked']['taxable']) }}</td><td>{{ $inr($tax['booked']['cgst']) }}</td><td>{{ $inr($tax['booked']['sgst']) }}</td><td>{{ $inr($tax['booked']['igst']) }}</td></tr>
<tr><td>On invoices issued ({{ $tax['documents'] }}, incl. {{ $tax['credit_notes'] }} credit notes)</td><td>{{ $inr($tax['issued']['taxable']) }}</td><td>{{ $inr($tax['issued']['cgst']) }}</td><td>{{ $inr($tax['issued']['sgst']) }}</td><td>{{ $inr($tax['issued']['igst']) }}</td></tr></tbody></table></div>
<p><small>Booked tax follows the date charges were posted; invoice tax follows the issue date, so they agree once each month's charges are invoiced in the same month. Use the invoice figures for returns (B2C supplies) and confirm with your accountant.</small></p>
<h3>Invoices by place of supply</h3><div class="scroll"><table><thead><tr><th>State</th><th>Documents</th><th>Taxable value</th><th>Tax</th></tr></thead><tbody>@forelse($tax['by_state'] as $state=>$row)<tr><td>{{ $state }}</td><td>{{ $row['documents'] }}</td><td>{{ $inr($row['taxable']) }}</td><td>{{ $inr($row['tax']) }}</td></tr>@empty<tr><td colspan="4">No invoices issued this month.</td></tr>@endforelse</tbody></table></div></section>
@endif

@if($view==='chart')
@if($can('books-account'))<details id="action-account"><summary>Add an account</summary><form method="post" action="{{ route('erp.business.mutate',[$business->id,'books-account']) }}">@csrf<label>Code<input name="code" maxlength="20" required placeholder="6100"></label><label>Name<input name="name" maxlength="120" required placeholder="Rent"></label><label>Type<select name="type">@foreach(\App\Modules\Erp\Services\Books::TYPES as $type)<option value="{{ $type }}">{{ ucfirst($type) }}</option>@endforeach</select></label><button>Add account</button></form></details>@endif
<div class="scroll"><table><thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Used for</th></tr></thead><tbody>@foreach($accounts as $account)<tr><td>{{ $account->code }}</td><td>{{ $account->name }}</td><td>{{ ucfirst($account->type) }}</td><td>{{ $account->system_key ? 'Posted automatically' : 'Manual journals' }}</td></tr>@endforeach</tbody></table></div>
@endif
@endsection
