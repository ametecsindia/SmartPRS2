@extends('qronex::layout', ['title' => 'Phones'])
@section('content')
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
  <x-qx::stat label="Waiting for approval" :value="$counts['pending'] ?? 0" icon="clock" :tone="($counts['pending'] ?? 0) ? 'amber' : 'navy'" :href="route('qronex.devices', ['status' => 'pending'])" />
  <x-qx::stat label="Approved" :value="$counts['approved'] ?? 0" icon="check" tone="teal" :href="route('qronex.devices', ['status' => 'approved'])" />
  <x-qx::stat label="Rejected" :value="$counts['rejected'] ?? 0" icon="x" :href="route('qronex.devices', ['status' => 'rejected'])" />
  <x-qx::stat label="Revoked" :value="$counts['revoked'] ?? 0" icon="shield" :href="route('qronex.devices', ['status' => 'revoked'])" />
</div>
<div class="flex flex-wrap gap-3 items-center">
  <nav class="tabs">@foreach(['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'revoked' => 'Revoked'] as $k => $l)<a href="{{ route('qronex.devices', array_filter(['status' => $k, 'q' => request('q')])) }}" @class(['on' => request('status', '') === $k])>{{ $l }}</a>@endforeach</nav>
  <form class="relative" role="search"><input type="hidden" name="status" value="{{ request('status') }}"><x-qx::icon name="search" class="w-4 h-4 absolute left-3 top-2.5 text-slate-400" /><input name="q" value="{{ request('q') }}" class="input pl-9 w-64" placeholder="Employee, code, model, install ref"></form>
  <span class="text-xs text-slate-500 ml-auto">One approved phone per employee (admins can allow more as an exception) · employees can only punch from an approved phone</span>
</div>
<div class="card p-0 overflow-x-auto">
  <table class="tbl"><tr><th>Employee</th><th>Phone</th><th>Match code</th><th>Registered</th><th>Last seen</th><th>Status</th><th></th></tr>
  @forelse($devices as $d)<tr>
    <td><a href="{{ route('qronex.devices.show', $d) }}" class="font-medium text-navy hover:text-teal">{{ $d->employee->name }}</a><div class="text-xs text-slate-500">{{ $d->employee->employee_code }} · {{ $d->employee->office?->name }}</div></td>
    <td>{{ $d->model }}<div class="text-xs text-slate-500">{{ $d->platform === 'ios' ? 'iOS' : 'Android' }} · {{ $d->os_version }}</div>@if($d->replaces && $d->status === 'pending')<div class="text-xs text-amber-700">Replaces {{ $d->replaces->model }}</div>@endif</td>
    <td>@if($d->status === 'pending' && $d->approval_code)<span class="chip bg-amber-100 text-amber-800">on phone</span>@else<span class="text-slate-300">—</span>@endif</td>
    <td class="text-xs whitespace-nowrap">{{ $d->created_at->timezone('Asia/Kolkata')->format('d M Y') }}</td>
    <td class="text-xs whitespace-nowrap">{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</td>
    <td><x-qx::status :s="$d->status" />@if($d->approver)<div class="text-xs text-slate-500">by {{ $d->approver->name }}</div>@endif</td>
    <td class="text-right whitespace-nowrap">@include('qronex::devices._actions')<a class="btn-ghost btn-sm ml-1" href="{{ route('qronex.devices.show', $d) }}">History</a></td></tr>
  @empty<tr><td colspan="7"><x-qx::empty icon="phone" title="No phones here" text="Use “Activate app” on the Employees page to send an employee their activation code." /></td></tr>@endforelse
  </table>
</div>
<div>{{ $devices->links() }}</div>
@endsection
