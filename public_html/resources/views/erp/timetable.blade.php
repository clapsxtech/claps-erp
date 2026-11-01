<section aria-labelledby="week-title">
<h3 id="week-title">Weekly timetable · {{ $timetable['start']->format('j M') }}–{{ $timetable['end']->subDay()->format('j M Y') }}</h3>
<p>All times in {{ $business->timezone }}. UTC offsets distinguish daylight-saving changes. Only generated dated lessons appear here, not ungenerated recurring agreements.</p>
<form method="get" aria-label="Filter weekly timetable">
<label>Any date in week<input type="date" name="date" value="{{ request('date',$timetable['start']->toDateString()) }}" min="2000-01-03" max="2099-12-27"></label>
<label>Student name or code<input name="student" value="{{ request('student') }}" maxlength="100"></label>
<label>Teacher name or code<input name="teacher" value="{{ request('teacher') }}" maxlength="100"></label>
<label>Lesson status<select name="status"><option value="">All statuses</option>@foreach(['scheduled','completed','cancelled','rescheduled','no_show'] as $status)<option value="{{ $status }}" @if(request('status')===$status) selected @endif>{{ ucfirst(str_replace('_',' ',$status)) }}</option>@endforeach</select></label>
<button>Show week</button><a href="{{ route('erp.business',[$business->id,'timetable']) }}">Reset to this week</a>
</form>
<nav class="tabs" aria-label="Week navigation">
@if($timetable['start']->toDateString()>'2000-01-03')<a rel="prev" href="{{ route('erp.business',array_merge([$business->id,'timetable'],$timetable['filters'],['date'=>$timetable['start']->subWeek()->toDateString()])) }}">Previous week</a>@endif
<a href="{{ route('erp.business',array_merge([$business->id,'timetable'],$timetable['filters'],['date'=>now($business->timezone)->toDateString()])) }}">This week</a>
@if($timetable['end']->toDateString()<='2099-12-27')<a rel="next" href="{{ route('erp.business',array_merge([$business->id,'timetable'],$timetable['filters'],['date'=>$timetable['end']->toDateString()])) }}">Next week</a>@endif
</nav>
@if($timetable['truncated'])<p class="notice" role="status">Showing only the first 250 matching lessons. This week is incomplete: narrow the student, teacher or status filters to see the remaining lessons. Empty days below may have omitted lessons.</p>@else<p>{{ $timetable['count'] }} matching lessons. Overnight lessons appear on each day they overlap.</p>@endif
@if($timetable['count']===0)<p class="notice">No matching dated lessons this week. Try another week or clear the filters. Confirmed recurring agreements must have dated lessons generated before they appear.</p>@endif
<div class="week-grid">
@foreach($timetable['days'] as $day)
<section class="week-day" aria-label="{{ $day['date']->format('l j F Y') }}">
<h4><time datetime="{{ $day['date']->toDateString() }}">{{ $day['date']->format('D j M') }}</time></h4>
@forelse($day['lessons'] as $lesson)
<article class="lesson-card lesson-{{ $lesson->status }}">
<p><strong><time datetime="{{ $lesson->local_start->toIso8601String() }}">{{ $lesson->local_start->format('D H:i P') }}</time> to <time datetime="{{ $lesson->local_end->toIso8601String() }}">{{ $lesson->local_end->format('D H:i P') }}</time></strong></p>
<p><strong>{{ $lesson->student_name }}</strong><br><small>{{ $lesson->student_code }}</small></p>
<p>{{ $lesson->subject_name }}<br>{{ $lesson->teacher_name }}<br><small>{{ $lesson->teacher_code }}</small></p>
<p><span class="lesson-status">{{ ucfirst(str_replace('_',' ',$lesson->status)) }}</span> · {{ ucfirst($lesson->kind) }} @if($lesson->kind==='demo')<br><small>Free student class · Teacher unpaid</small>@endif</p>
<details><summary>Lesson #{{ $lesson->id }} actions</summary>
<p><small>Open the action, then select lesson #{{ $lesson->id }} by name or ID. Changes require explicit submission and existing scheduling checks.</small></p>
@if($lesson->status==='scheduled')
@if(!$business->archived_at && (new \App\Modules\Erp\Services\TeamPermissions())->allows(auth()->user(),$business->id,'action','reschedule'))<p><a href="#action-reschedule">Reschedule with agreement</a></p>@endif
@if(!$business->archived_at && (new \App\Modules\Erp\Services\TeamPermissions())->allows(auth()->user(),$business->id,'action','cancel'))<p><a href="#action-cancel">Cancel without charge</a></p>@endif
@if($lesson->local_end->lte(now('UTC')) && !$business->archived_at && (new \App\Modules\Erp\Services\TeamPermissions())->allows(auth()->user(),$business->id,'action','attendance'))<p><a href="#action-attendance">Record attended minutes</a></p><p><a href="#action-no-show">Record a no-show</a></p>@endif
@elseif($lesson->status==='completed')<p><a href="{{ route('erp.business',[$business->id,'attendance']) }}">Review attendance</a></p>
@elseif($lesson->status==='cancelled')<p><small>Cancelled lessons remain visible for history. No attendance or cancellation charge.</small></p>
@elseif($lesson->status==='rescheduled')<p><small>Original time retained for history; the replacement is a separate dated lesson.</small></p>
@else<p><small>Student didn't attend{{ $lesson->cancellation_reason ? ': '.$lesson->cancellation_reason : '' }}. No charge and no teacher pay.</small></p>@endif
</details>
</article>
@empty<p><small>{{ $timetable['truncated']?'No lessons in displayed subset.':'No matching lessons.' }}</small></p>@endforelse
</section>
@endforeach
</div>
@if(!$business->archived_at && (new \App\Modules\Erp\Services\TeamPermissions())->allows(auth()->user(),$business->id,'action','extra'))<p><a href="#action-extra">Schedule demo or extra class</a> · <a href="#action-propose">Add a weekly slot</a> · <a href="#action-materialize">Generate dated lessons</a></p><p><small>Adding a weekly slot for an existing assignment: record availability if needed, propose the slot, record both confirmations, then generate dated lessons.</small></p>@endif
<div class="action-list">@include('erp.action-forms',['actionForms'=>['availability','propose','confirm','materialize','extra','reschedule','cancel','attendance','no-show','pause-classes','stop-classes','switch-teacher','end-agreement','exception'],'returnPage'=>'timetable'])</div>
<p><small>Viewing this timetable changes no attendance, balances or teacher pay. Import and approval remain separate administrator actions.</small></p>
</section>
