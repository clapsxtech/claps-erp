@extends('parent.layout')
@section('title',$entry->kind==='refund' ? 'Refund acknowledgement' : 'Payment acknowledgement')
@section('content')
<section><h2>{{ $entry->kind==='refund' ? 'Refund acknowledgement' : 'Payment acknowledgement' }}</h2>
@if($reversed)<p class="error"><strong>WITHDRAWN</strong> · This record was corrected by the centre and is no longer valid. Please check your latest receipts.</p>@endif
<p>{{ $business->name }}@if($business->phone) · {{ $business->phone }}@endif</p><p>Reference: {{ $entry->reference }}</p><p>Student: {{ $student->name }} ({{ $student->code }})</p>
<p>{{ $entry->kind==='refund' ? 'Paid back to the family' : 'Received' }}: {{ \App\Modules\Erp\Services\MoneyDisplay::inr(abs((int)$entry->amount_paise)) }}</p><p>Date: {{ \Carbon\CarbonImmutable::parse($entry->occurred_at)->format('j M Y') }}</p>
<p><small>This acknowledges a recorded {{ $entry->kind==='refund' ? 'refund' : 'payment' }}. It is not a tax invoice.</small></p>
<p><button onclick="window.print()">Print</button> <a href="{{ route('parent.home',['section'=>'fees']) }}">Back to fees</a></p></section>
@endsection
