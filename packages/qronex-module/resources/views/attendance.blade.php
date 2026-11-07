@extends('qronex::layout', ['title' => 'Attendance ledger'])
@section('actions')<div class="flex gap-2"><a class="btn-ghost" href="{{ route('qronex.attendance.export', request()->only('from', 'to', 'office', 'q')) }}">CSV</a></div>@endsection
@section('content')
<form class="flex flex-wrap gap-2 items-end">
  <input type="hidden" name="tab" value="{{ $tab }}">
  <div><label class="label">From</label><input type="date" name="from" value="{{ request('from', today('Asia/Kolkata')->toDateString()) }}" class="input"></div>
  <div><label class="label">To</label><input type="date" name="to" value="{{ request('to', today('Asia/Kolkata')->toDateString()) }}" class="input"></div>
  <div><label class="label">Office</label><select name="office" class="input"><option value="">All offices</option>@foreach($offices as $o)<option value="{{ $o->id }}" @selected(request('office') == $o->id)>{{ $o->name }}</option>@endforeach</select></div>
  <div><label class="label">Employee</label><input name="q" value="{{ request('q') }}" class="input" placeholder="Name or code"></div>
  <button class="btn-ghost">Apply</button>
</form>
<div class="flex gap-2">
  <a href="{{ route('qronex.attendance', ['tab' => 'verified'] + request()->except('tab', 'page')) }}" class="{{ $tab === 'verified' ? 'btn' : 'btn-ghost' }} btn-sm">Verified</a>
  <a href="{{ route('qronex.attendance', ['tab' => 'rejected'] + request()->except('tab', 'page')) }}" class="{{ $tab === 'rejected' ? 'btn' : 'btn-ghost' }} btn-sm">Rejected attempts</a>
</div>
<div class="card overflow-x-auto">
@if($tab === 'verified')
  <table class="tbl"><tr><th>Time (IST)</th><th>Employee</th><th>Type</th><th>Office · gate</th><th class="text-right">Distance</th><th>In {{ $host->name() }}</th><th>Ref</th></tr>
  @forelse($rows as $e)<tr>
    <td class="whitespace-nowrap">{{ $e->recorded_at->timezone('Asia/Kolkata')->format('d M H:i:s') }}</td>
    <td>{{ $e->employee->name }}<div class="text-xs text-slate-500">{{ $e->employee->employee_code }}</div></td>
    <td><span class="chip {{ $e->type === 'IN' ? 'bg-emerald-100 text-emerald-800' : 'bg-sky-100 text-sky-800' }}">{{ $e->type }}</span></td>
    <td>@if($e->gate){{ $e->gate->office?->name }} · {{ $e->gate->name }}@else<span class="text-xs font-mono">{{ $e->mode }}</span>@endif
      @if($e->adjusted)<span class="chip bg-amber-100 text-amber-800 ml-1" title="{{ $e->correction?->reason }}">adjusted</span>@endif</td>
    <td class="text-right text-xs">@if($e->distance_m !== null){{ $e->distance_m }} m (±{{ $e->accuracy_m }} m)@else — @endif</td>
    <td class="text-xs">@if($e->host_synced_at)<span class="chip bg-emerald-100 text-emerald-800">recorded</span>@else<span class="chip bg-amber-100 text-amber-800" title="{{ $e->host_sync_error }}">pending</span>@endif</td>
    <td class="text-xs font-mono">{{ $e->ref }}</td></tr>
  @empty<tr><td colspan="7" class="text-center text-slate-500 py-6">No verified punches in this period.</td></tr>@endforelse
  </table>
@else
  <table class="tbl"><tr><th>Time (IST)</th><th>Employee</th><th>Reason</th><th>Gate</th><th>Ref</th></tr>
  @forelse($rows as $r)<tr x-data="{o:false}">
    <td class="whitespace-nowrap">{{ $r->created_at->timezone('Asia/Kolkata')->format('d M H:i:s') }}</td>
    <td>{{ $r->employee?->name ?? 'Unknown' }}<div class="text-xs text-slate-500">{{ $r->employee?->employee_code }}</div></td>
    <td><span class="chip bg-red-100 text-red-800">{{ $r->reason_code }}</span> <span class="text-xs">{{ $r->reason }}</span>
      <button class="text-xs text-teal ml-1" @click="o=!o" x-text="o?'Hide checks':'Checks'"></button>
      <ul x-show="o" x-cloak class="text-xs mt-1 space-y-0.5">@foreach($r->checks ?? [] as $c)<li>{{ ['ok' => '✓', 'bad' => '✕', 'skip' => '·', 'na' => 'i'][$c['status']] ?? '·' }} {{ $c['label'] }} — <span class="text-slate-500">{{ $c['detail'] }}</span></li>@endforeach
        @if($r->lat !== null)<li class="pt-1">📍 Phone location: <span class="font-mono" data-loc>{{ $r->lat }}, {{ $r->lng }}</span>{{ $r->accuracy_m ? ' ±'.$r->accuracy_m.' m' : '' }} · <a class="text-teal underline" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat={{ $r->lat }}&mlon={{ $r->lng }}#map=18/{{ $r->lat }}/{{ $r->lng }}">map</a></li>@endif</ul></td>
    <td>{{ $r->gate?->office?->name }} {{ $r->gate ? '· '.$r->gate->name : '—' }}</td>
    <td class="text-xs font-mono">{{ $r->ref }}</td></tr>
  @empty<tr><td colspan="5" class="text-center text-slate-500 py-6">No rejected attempts in this period.</td></tr>@endforelse
  </table>
@endif
  <div class="mt-3">{{ $rows->links() }}</div>
</div>
@endsection
