@extends('qronex::layout', ['title' => 'Dashboard'])
@section('content')
<div class="grid grid-cols-2 xl:grid-cols-5 gap-4">
  <x-qx::stat label="Employees" :value="$stats['employees']" icon="users" :href="route('qronex.employees')" sub="Synced from {{ $host->name() }}" />
  <x-qx::stat label="Present today" :value="$stats['present']" icon="check" tone="teal" :href="route('qronex.attendance')" />
  <x-qx::stat label="Punches today" :value="$stats['punches']" icon="clock" tone="sky" :href="route('qronex.attendance')" />
  <x-qx::stat label="Rejected today" :value="$stats['rejected']" icon="alert" :tone="$stats['rejected'] ? 'red' : 'navy'" :href="route('qronex.attendance', ['tab' => 'rejected'])" />
  <x-qx::stat label="Waiting for {{ $host->name() }}" :value="$stats['unsynced']" icon="server" :tone="$stats['unsynced'] ? 'amber' : 'navy'" sub="Punches not yet written (retried hourly)" />
</div>
<div class="grid lg:grid-cols-3 gap-4">
  <div class="card space-y-2">
    <div class="card-t">Waiting for approval</div>
    @foreach(['devices' => 'Phones', 'remote' => 'Remote attendance', 'corrections' => 'Missed punches'] as $k => $l)
      <a href="{{ route('qronex.approvals') }}" class="flex justify-between rounded-lg px-3 py-2 hover:bg-slate-50"><span>{{ $l }}</span><span class="chip {{ $pending[$k] ? 'bg-amber-100 text-amber-800' : 'bg-slate-100' }}">{{ $pending[$k] }}</span></a>
    @endforeach
  </div>
  <div class="card lg:col-span-2 overflow-x-auto">
    <div class="card-t mb-2">Offices</div>
    @if($offices->isEmpty())
      <x-qx::empty icon="building" title="No offices yet" text="Add an office and its entrance gate, then open the display link on a screen at the entrance."><a class="btn" href="{{ route('qronex.offices.create') }}">Add office</a></x-qx::empty>
    @else
    <table class="tbl"><tr><th>Office</th><th>Gates</th><th>Employees</th></tr>
      @foreach($offices as $o)<tr><td>{{ $o->name }} <span class="text-xs text-slate-500">{{ $o->code }}</span></td><td>{{ $o->gates_count }}</td><td>{{ $o->employees_count }}</td></tr>@endforeach
    </table>@endif
  </div>
</div>
<div class="card overflow-x-auto">
  <div class="card-t mb-2">Recent activity</div>
  <table class="tbl">@forelse($audit as $a)<tr><td class="text-xs whitespace-nowrap">{{ $a->created_at->timezone('Asia/Kolkata')->format('d M H:i') }}</td><td class="text-sm">{{ $a->action }}</td><td class="text-xs text-slate-500">{{ $a->subject }}</td><td class="text-xs text-slate-500">{{ $a->user?->name }}</td></tr>
  @empty<tr><td class="text-slate-500 text-sm">Nothing yet.</td></tr>@endforelse</table>
</div>
@endsection
