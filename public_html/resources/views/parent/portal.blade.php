@extends('parent.layout')
@section('title',$student->name)
@section('content')
@php($inr=fn($p)=>\App\Modules\Erp\Services\MoneyDisplay::inr((int)$p))
@php($balanceText=fn($p)=>\App\Modules\Erp\Services\MoneyDisplay::inr(abs((int)$p)).($p>0?' paid in advance':($p<0?' due':'')))
@php($local=fn($t)=>\Carbon\CarbonImmutable::parse($t,'UTC')->setTimezone($student->timezone))
<div class="page-heading"><div><div class="eyebrow">{{ $business->name }}@if($business->phone) · {{ $business->phone }}@endif</div><h1>{{ $student->name }}</h1><p>Student ID {{ $student->code }} · {{ preg_match('/^\s*grade\b/i',$student->grade) ? $student->grade : 'Grade '.$student->grade }} {{ $student->syllabus }} · Times in {{ $student->timezone }}</p></div></div>
<nav class="tabs" aria-label="Parent portal sections">@foreach(['overview'=>'Overview','classes'=>'Classes','results'=>'Results','fees'=>'Fees & documents'] as $key=>$label)<a href="{{ route('parent.home',['section'=>$key]) }}" @if($section===$key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>

@if($section==='overview')
<div class="teacher-metrics"><div><span>Balance</span><strong>{{ $balanceText($data['balance']) }}</strong></div><div><span>Next class</span><strong>{{ $data['upcoming']->first() ? $local($data['upcoming']->first()->starts_at)->format('D j M, H:i') : 'None booked' }}</strong></div><div><span>Open fee requests</span><strong>{{ $data['open_requests'] }}</strong></div><div><span>Latest result</span><strong>{{ $data['results']->first() ? $data['results']->first()->score.'/'.$data['results']->first()->maximum : '—' }}</strong></div></div>
<h2>Upcoming classes</h2><div class="teacher-cards">@forelse($data['upcoming'] as $lesson)<article class="teacher-card"><p class="teacher-time">{{ $local($lesson->starts_at)->format('D j M Y') }}<small>{{ $local($lesson->starts_at)->format('H:i') }}–{{ $local($lesson->ends_at)->format('H:i') }}</small></p><h2>{{ $lesson->subject_name }}@if($lesson->kind==='demo') (demo)@elseif($lesson->kind==='extra') (extra class)@endif</h2><p>with {{ $lesson->teacher_name }}</p></article>@empty<div class="teacher-empty"><h2>No upcoming classes</h2><p>Contact the centre to book classes.</p></div>@endforelse</div>
@endif

@if($section==='classes')
<h2>Upcoming</h2><div class="scroll"><table><thead><tr><th>Date and time</th><th>Subject</th><th>Teacher</th></tr></thead><tbody>@forelse($data['upcoming'] as $lesson)<tr><td>{{ $local($lesson->starts_at)->format('D j M Y, H:i') }}</td><td>{{ $lesson->subject_name }}@if($lesson->kind==='extra') (extra)@elseif($lesson->kind==='demo') (demo)@endif</td><td>{{ $lesson->teacher_name }}</td></tr>@empty<tr><td colspan="3">No upcoming classes in the next 60 days.</td></tr>@endforelse</tbody></table></div>
<h2>Past 60 days</h2><p>If a class record looks wrong, tell us here. It won't be billed until the centre has checked it.</p>
<div class="scroll"><table><thead><tr><th>Date and time</th><th>Subject and teacher</th><th>What happened</th><th></th></tr></thead><tbody>
@forelse($data['past'] as $lesson)<tr><td>{{ $local($lesson->starts_at)->format('D j M Y, H:i') }}</td><td>{{ $lesson->subject_name }} · {{ $lesson->teacher_name }}</td>
<td>@if($lesson->status==='cancelled')Cancelled (no charge)@elseif($lesson->status==='no_show')Marked as not attended (no charge)@elseif($lesson->attendance_id){{ $lesson->actual_minutes }} minutes taught @if($lesson->parent_review_status==='disputed')<br><small>Being checked by the centre</small>@endif @else Waiting for the teacher's record @endif</td>
<td>@if($lesson->attendance_id && !$lesson->open_request_id)<details><summary>Something wrong?</summary><form method="post" action="{{ route('parent.dispute') }}">@csrf<input type="hidden" name="attendance_id" value="{{ $lesson->attendance_id }}"><label>What's wrong with this class?<textarea name="note" minlength="10" maxlength="1000" required placeholder="For example: the class was only 40 minutes"></textarea></label><button type="submit">Send to the centre</button></form></details>@elseif($lesson->open_request_id)<small>Query sent</small>@endif</td></tr>
@empty<tr><td colspan="4">No classes in the last 60 days.</td></tr>@endforelse</tbody></table></div>
@endif

@if($section==='results')
<div class="scroll"><table><thead><tr><th>Date</th><th>Subject</th><th>Assessment</th><th>Score</th><th>Teacher's note</th></tr></thead><tbody>
@forelse($data['results'] as $result)<tr><td>{{ \Carbon\CarbonImmutable::parse($result->assessed_on)->format('j M Y') }}</td><td>{{ $result->subject_name }}</td><td>{{ $result->assessment }}</td><td><strong>{{ $result->score }}</strong> / {{ $result->maximum }}</td><td>{{ $result->notes }}</td></tr>
@empty<tr><td colspan="5">No results recorded yet.</td></tr>@endforelse</tbody></table></div>{{ $data['results']->links('pagination::simple-bootstrap-4') }}
@endif

@if($section==='fees')
<div class="teacher-metrics"><div><span>Balance</span><strong>{{ $balanceText($data['balance']) }}</strong></div></div>
<h2>Fee requests</h2><div class="scroll"><table><thead><tr><th>Reference</th><th>Requested</th><th>Paid</th><th>Outstanding</th><th>Due</th><th></th></tr></thead><tbody>
@forelse($data['requests'] as $request)<tr><td>{{ $request->reference }}</td><td>{{ $inr($request->requested_paise) }}</td><td>{{ $inr($request->covered_paise) }}</td><td>{{ $inr($request->outstanding_paise) }}</td><td>{{ $request->due_on ?? '—' }}</td><td><a href="{{ route('parent.document',['type'=>'request','id'=>$request->id]) }}">View / print</a></td></tr>
@empty<tr><td colspan="6">No fee requests.</td></tr>@endforelse</tbody></table></div>
<h2>Invoices</h2><div class="scroll"><table><thead><tr><th>Number</th><th>Date</th><th>Period</th><th>Total</th><th></th></tr></thead><tbody>
@forelse($data['invoices'] as $invoice)<tr><td>{{ $invoice->invoice_number }}@if($invoice->document_type==='credit_note') (credit note)@elseif($invoice->status==='cancelled') (cancelled)@endif</td><td>{{ $invoice->issue_date }}</td><td>{{ $invoice->billing_period }}</td><td>{{ $inr($invoice->total_paise) }}</td><td><a href="{{ route('parent.document',['type'=>'invoice','id'=>$invoice->id]) }}">View / print</a></td></tr>
@empty<tr><td colspan="5">No invoices yet.</td></tr>@endforelse</tbody></table></div>
<h2>Receipts and refunds</h2><div class="scroll"><table><thead><tr><th>Date</th><th>Reference</th><th>Amount</th><th></th></tr></thead><tbody>
@forelse($data['documents'] as $entry)<tr><td>{{ \Carbon\CarbonImmutable::parse($entry->occurred_at)->format('j M Y') }}</td><td>{{ $entry->reference }}@if(in_array($entry->id,$data['reversed'],true)) <small>(withdrawn)</small>@endif</td><td>{{ $entry->kind==='refund' ? 'Refund '.$inr(abs((int)$entry->amount_paise)) : $inr($entry->amount_paise) }}</td><td><a href="{{ route('parent.document',['type'=>'receipt','id'=>$entry->id]) }}">View / print</a></td></tr>
@empty<tr><td colspan="4">No payments recorded yet.</td></tr>@endforelse</tbody></table></div>
<h2>Monthly statements</h2><p>@forelse($data['months'] as $month)<a class="button button-secondary" href="{{ route('parent.statement',['month'=>$month]) }}">{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m',$month)->format('F Y') }}</a> @empty No statements yet. @endforelse</p>
@endif
@endsection
