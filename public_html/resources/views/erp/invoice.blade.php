<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
@php
$inr=fn($p)=>\App\Modules\Erp\Services\MoneyDisplay::inr((int)$p);
$title=['tax_invoice'=>'Tax invoice','invoice'=>'Invoice','credit_note'=>'Credit note'][$invoice->document_type] ?? 'Invoice';
$gst=in_array($invoice->document_type,['tax_invoice'],true) || ($invoice->document_type==='credit_note' && $invoice->supplier_tax_id);
$rate=\App\Modules\Erp\Services\Books::GST_RATE_PERCENT;
@endphp
<title>{{ $title }} {{ $invoice->invoice_number }}</title><style>body{font:14px system-ui;margin:24px auto;padding:0 16px;max-width:900px;color:#172b36}table{border-collapse:collapse;width:100%;margin:12px 0}td,th{text-align:left;padding:8px 6px;border-bottom:1px solid #ddd;vertical-align:top}th{background:#f5f7f6}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.right{text-align:right}.muted{color:#5d6d64;font-size:12px}.cancelled{border:2px solid #b5413a;color:#b5413a;padding:8px 12px;display:inline-block;font-weight:700}@media print{.controls{display:none}}@media(max-width:600px){.grid{grid-template-columns:1fr}}</style></head><body>
<h1>{{ $title }}</h1>
@if($invoice->status==='cancelled')<p class="cancelled">CANCELLED — see credit note</p>@endif
<div class="grid"><div><strong>{{ $business->legal_name ?: $business->name }}</strong><br>@if($business->address){!! nl2br(e($business->address)) !!}<br>@endif @if($business->state)State: {{ $business->state }}<br>@endif @if($invoice->supplier_tax_id)GSTIN: {{ $invoice->supplier_tax_id }}<br>@endif @if($business->phone)Phone: {{ $business->phone }}@endif</div>
<div><strong>{{ $title }} no. {{ $invoice->invoice_number }}</strong><br>Date: {{ \Carbon\CarbonImmutable::parse($invoice->issue_date)->format('j M Y') }}<br>@if($invoice->billing_period)For classes charged in: {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m',$invoice->billing_period)->format('F Y') }}<br>@endif @if($credited)Against invoice: {{ $credited->invoice_number }} dated {{ $credited->issue_date }}<br>@endif</div></div>
<p><strong>Billed to:</strong> {{ $invoice->billing_name }} (student ID {{ $student->code }})@if($invoice->place_of_supply)<br>Place of supply: {{ $invoice->place_of_supply }}@endif</p>
<table><thead><tr><th>#</th><th>Description</th>@if($gst)<th>SAC</th><th class="right">Taxable value</th>@endif<th class="right">Amount</th></tr></thead><tbody>
@foreach($lines as $i=>$line)<tr><td>{{ $i+1 }}</td><td>{{ $line->description }}</td>@if($gst)<td>{{ $line->sac }}</td><td class="right">{{ $inr($line->taxable_paise) }}</td>@endif<td class="right">{{ $inr($line->amount_paise) }}</td></tr>@endforeach
</tbody></table>
<table style="max-width:420px;margin-left:auto"><tbody>
@if($gst)<tr><td>Taxable value</td><td class="right">{{ $inr($invoice->subtotal_paise) }}</td></tr>
@if($invoice->igst_paise)<tr><td>IGST {{ $rate }}%</td><td class="right">{{ $inr($invoice->igst_paise) }}</td></tr>@else<tr><td>CGST {{ $rate/2 }}%</td><td class="right">{{ $inr($invoice->cgst_paise) }}</td></tr><tr><td>SGST {{ $rate/2 }}%</td><td class="right">{{ $inr($invoice->sgst_paise) }}</td></tr>@endif @endif
<tr><th>{{ $invoice->document_type==='credit_note' ? 'Total credited' : 'Total' }}</th><th class="right">{{ $inr($invoice->total_paise) }}</th></tr></tbody></table>
<p>{{ \App\Modules\Erp\Services\Invoicing::words((int)$invoice->total_paise) }}</p>
@if($gst)<p class="muted">Class fees include GST at {{ $rate }}%. Supply of commercial training and coaching services. Tax is not payable on reverse charge.</p>@else<p class="muted">No tax is charged on this invoice.</p>@endif
@if($invoice->document_type==='credit_note' && $invoice->notes)<p class="muted">Reason: {{ $invoice->notes }}</p>@endif
<p class="controls"><button onclick="window.print()">Print</button> @if(!empty($forParent))<a href="{{ route('parent.home',['section'=>'fees']) }}">Back to the parent portal</a>@else<a href="{{ route('erp.business',[$business->id,'accounts','view'=>'invoices']) }}">Back to invoices</a>@endif</p>
</body></html>
