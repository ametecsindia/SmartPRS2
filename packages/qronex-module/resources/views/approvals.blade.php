@extends('qronex::layout', ['title' => 'Approvals'])
@section('content')
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
  @foreach([['Phones', $devices, 'phone'], ['Remote attendance', $remote, 'globe'], ['Punch corrections', $corrections, 'clock']] as [$label, $list, $ic])
    <x-qx::stat :label="$label" :value="$list->count()" :icon="$ic" :tone="$list->count() ? 'amber' : 'navy'" />
  @endforeach
</div>

<div class="card overflow-x-auto"><h2 class="font-semibold text-navy mb-2">Devices</h2>
  <table class="tbl"><tr><th>Employee</th><th>Device</th><th>Requested</th><th></th></tr>
  @forelse($devices as $d)<tr><td>{{ $d->employee->name }}<div class="text-xs text-slate-500">{{ $d->employee->employee_code }} · {{ $d->employee->office?->name }}</div></td>
    <td>{{ $d->model }} <span class="text-xs text-slate-500">{{ $d->os_version }}</span>@if($d->replaces)<div class="text-xs text-amber-700">Replacement — approving revokes {{ $d->replaces->model }}</div>@endif</td>
    <td class="text-xs">{{ $d->created_at->diffForHumans() }}</td>
    <td class="text-right whitespace-nowrap">@include('qronex::devices._actions')</td></tr>
  @empty<tr><td colspan="4"><x-qx::empty icon="phone" title="No phones waiting" class="py-6" /></td></tr>@endforelse</table></div>

<div class="card overflow-x-auto"><h2 class="font-semibold text-navy mb-2">Remote attendance requests</h2>
  <table class="tbl"><tr><th>Employee</th><th>Type · dates</th><th>Reason</th><th>Location</th><th></th></tr>
  @forelse($remote as $p)<tr x-data="{loc:false}"><td>{{ $p->employee->name }}<div class="text-xs text-slate-500">{{ $p->ref }}</div></td>
    <td>{{ $p->typeLabel() }}<div class="text-xs">{{ $p->from_date->format('d M') }} → {{ $p->to_date->format('d M Y') }}</div></td>
    <td class="text-sm">{{ $p->reason }}</td>
    <td class="text-xs">@if($p->lat !== null){{ $p->location_label }}<br>{{ $p->lat }}, {{ $p->lng }} · {{ $p->radius_m }} m @else<span class="text-amber-700">No location sent</span>@endif</td>
    <td class="text-right">
      <form method="post" action="{{ route('qronex.approvals.remote', $p) }}" class="space-y-1">@csrf
        @if($p->lat === null)<label class="text-xs flex items-center gap-1 justify-end"><input type="checkbox" name="location_waived" value="1"> Waive location check</label>@endif
        <button name="decision" value="approve" class="btn btn-sm">Approve</button><button name="decision" value="reject" class="btn-ghost btn-sm">Reject</button></form></td></tr>
  @empty<tr><td colspan="5" class="text-slate-500">No remote requests.</td></tr>@endforelse</table></div>

<div class="card overflow-x-auto"><h2 class="font-semibold text-navy mb-2">Punch corrections</h2>
  <table class="tbl"><tr><th>Employee</th><th>Requested punch (IST)</th><th>Reason</th><th></th></tr>
  @forelse($corrections as $c)<tr><td>{{ $c->employee->name }}<div class="text-xs text-slate-500">{{ $c->ref }}</div></td>
    <td><b>{{ $c->type }}</b> · {{ $c->punch_at->timezone('Asia/Kolkata')->format('d M Y H:i') }}</td><td class="text-sm">{{ $c->reason }}</td>
    <td class="text-right whitespace-nowrap"><form method="post" action="{{ route('qronex.approvals.correction', $c) }}">@csrf<button name="decision" value="approve" class="btn btn-sm">Approve as adjustment</button><button name="decision" value="reject" class="btn-ghost btn-sm">Reject</button></form></td></tr>
  @empty<tr><td colspan="4" class="text-slate-500">No correction requests.</td></tr>@endforelse</table>
  <p class="text-xs text-slate-500 mt-2">Approving adds a new MANUAL_EXCEPTION punch marked “adjusted”. Existing punches are never changed.</p></div>
@endsection
