@extends('erp.layout')
@section('content')
<div class="page-heading"><div><h1>Business units</h1><p>Open a team to see its students, classes, attendance and money</p></div><a class="button button-secondary" href="{{ route('erp.tasks.mine') }}">@include('erp.icon',['name'=>'check'])My assigned tasks</a></div>
<div class="home-grid"><div><div class="section-heading"><div><h2>Your teams <span class="count-pill">{{ $units->count() }}</span></h2></div></div>
<div class="workspace-cards">
@forelse($units as $unit)
<section class="workspace-card {{ $loop->index % 2 ? 'workspace-card-blue' : '' }}">
    <div class="workspace-card-top"><span class="unit-avatar">{{ $unit->code }}</span><span class="access-pill">@include('erp.icon',['name'=>'shield']){{ (new \App\Modules\IdentityAccess\Services\SiteAccessPolicy())->isAdministrator(auth()->user()) ? 'Superadmin' : 'Staff' }}</span></div>
    <h3><a href="{{ route('erp.business',[$unit->id,(new \App\Modules\Erp\Services\TeamPermissions())->landing(auth()->user(),$unit->id)]) }}">{{ $unit->name }}</a></h3><p class="unit-meta">@include('erp.icon',['name'=>'clock']){{ str_replace('_',' ',$unit->timezone) }}@if($unit->phone)<span>· {{ $unit->phone }}</span>@endif</p>
    <div class="unit-shortcuts">@if((new \App\Modules\Erp\Services\TeamPermissions())->allows(auth()->user(),$unit->id,'page','people'))<a href="{{ route('erp.business',[$unit->id,'people']) }}">@include('erp.icon',['name'=>'people'])People</a>@endif
@if((new \App\Modules\Erp\Services\TeamPermissions())->allows(auth()->user(),$unit->id,'page','timetable'))<a href="{{ route('erp.business',[$unit->id,'timetable']) }}">@include('erp.icon',['name'=>'calendar'])Timetable</a>@endif
@if((new \App\Modules\Erp\Services\TeamPermissions())->allows(auth()->user(),$unit->id,'page','money'))<a href="{{ route('erp.business',[$unit->id,'money']) }}">@include('erp.icon',['name'=>'wallet'])Money</a>@endif</div>
    <a class="workspace-open" href="{{ route('erp.business',[$unit->id,(new \App\Modules\Erp\Services\TeamPermissions())->landing(auth()->user(),$unit->id)]) }}">Open workspace @include('erp.icon',['name'=>'arrow'])</a>
</section>
@empty
@if((new \App\Modules\IdentityAccess\Services\SiteAccessPolicy())->isAdministrator(auth()->user()))
<section class="empty-state"><span class="empty-icon">@include('erp.icon',['name'=>'grid'])</span><h3>No business units yet</h3><p>Create a team in Teams &amp; accounts, add yourself or your staff to it, and it will appear here.</p><a class="button" href="{{ route('erp.administration') }}">Go to Teams &amp; accounts</a></section>
@else
<section class="empty-state"><span class="empty-icon">@include('erp.icon',['name'=>'grid'])</span><h3>No business units assigned yet</h3><p>Ask your administrator to give you access to a business unit. Your assigned tasks are still available from the sidebar.</p><a class="button" href="{{ route('erp.tasks.mine') }}">View my tasks</a><a class="text-link" href="{{ route('erp.teacher.index') }}">Teacher lesson attendance @include('erp.icon',['name'=>'arrow'])</a></section>
@endif
@endforelse
</div><div class="workspace-note">@include('erp.icon',['name'=>'shield'])<p>Each unit has its own teacher pool and access. Existing Sheets and legacy records are not automatically imported.</p></div></div>
<aside class="home-aside" aria-label="Workspace shortcuts"><section class="quick-panel"><h3>Shortcuts</h3><a class="quick-link" href="{{ route('erp.tasks.mine') }}"><span class="quick-icon">@include('erp.icon',['name'=>'check'])</span><span><strong>My assigned tasks</strong><small>Your next steps, in one place</small></span>@include('erp.icon',['name'=>'chevron'])</a><a class="quick-link" href="{{ route('tools.index') }}"><span class="quick-icon blue">@include('erp.icon',['name'=>'external'])</span><span><strong>Records & administration</strong><small>Records, reports and site settings</small></span>@include('erp.icon',['name'=>'chevron'])</a></section>
@if($units->isNotEmpty())<section class="getting-started"><h3>Getting started</h3><ol><li><span>01</span>Build your student & teacher directory</li><li><span>02</span>Plan lessons in the timetable</li><li><span>03</span>Review attendance and follow-ups</li></ol><a class="text-link" href="{{ route('erp.business',[$units->first()->id,(new \App\Modules\Erp\Services\TeamPermissions())->landing(auth()->user(),$units->first()->id)]) }}">Start with your people @include('erp.icon',['name'=>'arrow'])</a></section>@endif
</aside></div>
@endsection
