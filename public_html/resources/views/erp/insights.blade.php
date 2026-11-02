@extends('erp.layout')
@section('content')
@php
$inr=fn($p)=>\App\Modules\Erp\Services\MoneyDisplay::inr((int)$p);
$metrics=[
  'enquiries'=>['Enquiries','Sales','count'],'joined'=>['Students joined','Sales','count'],'conversion'=>['Conversion','Sales','percent'],
  'active_students'=>['Active students','Teaching','count'],'hours'=>['Hours taught','Teaching','hours'],'no_show_rate'=>['No-show rate','Teaching','percent'],
  'billed'=>['Billed (class charges)','Money','money'],'collected'=>['Collected','Money','money'],'teacher_cost'=>['Teacher cost','Money','money'],
  'margin'=>['Gross margin','Money','money'],'dues'=>['Owed at month end','Money','money'],'refunds'=>['Refunds','Money','money'],
];
$fmt=fn($v,$kind)=>$v===null?'—':($kind==='money'?$inr($v):($kind==='percent'?number_format($v,1).'%':($kind==='hours'?number_format($v,1).' h':number_format($v))));
// Headline the last complete month; the month in progress is the faded bar.
$current=$months[count($months)-2]; $previous=$months[count($months)-3] ?? null;
@endphp
<div class="page-heading business-heading"><div><div class="eyebrow">{{ $business->name }}</div><h1>Trends</h1><p>The last six months side by side. Headline figures are for {{ $months[count($months)-2]->label }}, the last complete month; the faded bar is {{ end($months)->label }}, still in progress.</p></div></div>
<style>
.trend-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin:20px 0}.trend-card{margin:0;padding:18px 20px}.trend-card h3{font-size:12px;color:var(--muted);font-weight:600;margin:0}.trend-card .trend-value{font-size:24px;font-weight:600;letter-spacing:-.5px;margin:4px 0 2px;font-variant-numeric:tabular-nums}.trend-card .trend-change{font-size:11px;color:var(--muted)}
.trend-chart{display:block;width:100%;height:96px;margin-top:10px}.trend-chart .bar{fill:#008f6b}.trend-chart .bar.current{fill:#008f6b;opacity:.55}.trend-chart .hit{fill:transparent}.trend-chart .hit:hover+.bar,.trend-chart g:hover .bar{fill:#00724f}.trend-chart .base{stroke:#d9e2dc;stroke-width:1}.trend-chart text{font-size:9px;fill:#6b7c71}
@media(max-width:900px){.trend-grid{grid-template-columns:1fr 1fr}}@media(max-width:600px){.trend-grid{grid-template-columns:1fr}}
</style>
@foreach(['Sales','Teaching','Money'] as $group)
<h2>{{ $group }}</h2>
<div class="trend-grid">@foreach(array_filter($metrics,fn($m)=>$m[1]===$group) as $key=>[$label,,$kind])
@php $values=array_map(fn($m)=>$m->$key,$months); @endphp @php $max=max(1,...array_map(fn($v)=>abs((float)($v??0)),$values)); @endphp @php $now=$current->$key; @endphp @php $before=$previous?->$key; @endphp
<section class="trend-card"><h3>{{ $label }}</h3><div class="trend-value">{{ $fmt($now,$kind) }}</div>
<div class="trend-change">{{ $current->label }}@if($before!==null && $now!==null) · {{ $now>=$before?'up':'down' }} from {{ $fmt($before,$kind) }} in {{ $previous->label }}@endif</div>
<svg class="trend-chart" viewBox="0 0 300 96" role="img" aria-label="{{ $label }} by month: @foreach($months as $m){{ $m->label }} {{ $fmt($m->$key,$kind) }}{{ $loop->last?'':', ' }}@endforeach">
@foreach($months as $i=>$m)@php $v=(float)($m->$key??0); @endphp @php $h=$v>0?max(2,round(72*$v/$max)):0; @endphp @php $x=$i*50+6; @endphp
<g><title>{{ $m->label }}: {{ $fmt($m->$key,$kind) }}{{ $loop->last?' (month in progress)':'' }}</title><rect class="hit" x="{{ $i*50 }}" y="0" width="50" height="96"></rect>@if($h)<path class="bar{{ $loop->last?' current':'' }}" d="M{{ $x }},80 V{{ 80-$h+4 }} a4,4 0 0 1 4,-4 H{{ $x+34 }} a4,4 0 0 1 4,4 V80 Z"></path>@endif<text x="{{ $x+19 }}" y="93" text-anchor="middle">{{ $m->short }}</text></g>@endforeach
<line class="base" x1="0" y1="80.5" x2="300" y2="80.5"></line></svg></section>
@endforeach</div>
@endforeach
<section><h3>All figures</h3><div class="scroll"><table><thead><tr><th>Measure</th>@foreach($months as $m)<th>{{ $m->label }}</th>@endforeach</tr></thead><tbody>
@foreach(['enquiries'=>['Enquiries','count'],'demos'=>['Demos booked','count'],'joined'=>['Students joined','count'],'conversion'=>['Conversion (joined ÷ enquiries)','percent'],'new_students'=>['New students registered','count'],'active_students'=>['Active students (had a class)','count'],'hours'=>['Hours taught','hours'],'cancelled'=>['Classes cancelled','count'],'no_shows'=>['No-shows','count'],'no_show_rate'=>['No-show rate','percent'],'billed'=>['Billed','money'],'collected'=>['Collected','money'],'refunds'=>['Refunds','money'],'teacher_cost'=>['Teacher cost','money'],'margin'=>['Gross margin (billed − teacher cost)','money'],'dues'=>['Owed by families at month end','money']] as $key=>[$label,$kind])
<tr><td>{{ $label }}</td>@foreach($months as $m)<td>{{ $fmt($m->$key,$kind) }}</td>@endforeach</tr>@endforeach
</tbody></table></div><p><small>Billed counts class charges posted in the month; collected counts payments received, net of reversals. The faded bar is the month in progress.</small></p></section>
@endsection
