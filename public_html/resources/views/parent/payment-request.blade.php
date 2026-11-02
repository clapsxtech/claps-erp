@extends('parent.layout')
@section('title','Fee request '.$paymentRequest->reference)
@section('content')
@php($inr=fn($p)=>\App\Modules\Erp\Services\MoneyDisplay::inr((int)$p))
<section><h2>Fee request</h2><p>{{ $business->name }}@if($business->phone) · {{ $business->phone }}@endif</p><p>{{ $student->name }} · {{ $student->code }}</p><p>Reference: {{ $paymentRequest->reference }}</p>
<div class="scroll"><table><tbody><tr><th scope="row">Requested</th><td>{{ $inr($summary['requested_paise']) }}</td></tr><tr><th scope="row">Paid so far</th><td>{{ $inr($summary['covered_paise']) }}</td></tr><tr><th scope="row">Outstanding</th><td><strong>{{ $inr($summary['outstanding_paise']) }}</strong></td></tr><tr><th scope="row">Due date</th><td>{{ $paymentRequest->due_on ?? 'Not specified' }}</td></tr><tr><th scope="row">Status</th><td>{{ ucfirst($summary['status']) }}</td></tr></tbody></table></div>
@if($paymentRequest->notes)<p>{{ $paymentRequest->notes }}</p>@endif
<p><button onclick="window.print()">Print</button> <a href="{{ route('parent.home',['section'=>'fees']) }}">Back to fees</a></p></section>
@endsection
