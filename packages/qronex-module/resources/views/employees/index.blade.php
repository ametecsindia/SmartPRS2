@extends('qronex::layout', ['title' => 'Employees', 'crumb' => 'Organisation'])
@section('actions')
  @if(\Ametecs\Qronex\Qronex::can(auth()->user(), 'manage'))<form method="post" action="{{ route('qronex.employees.sync') }}">@csrf<button class="btn btn-sm"><x-qx::icon name="users" class="w-4 h-4" /> Sync from {{ $host->name() }}</button></form>@endif
@endsection
@section('content')
<p class="text-sm text-slate-600">Employees are managed in {{ $host->name() }} and copied here automatically{{ $syncedAt ? ' (last sync '.$syncedAt->diffForHumans().')' : '' }}. Here you choose each employee's office and gate, where they may scan, and send the app activation code.</p>
<form class="flex flex-wrap gap-2 items-end">
  <div class="relative"><x-qx::icon name="search" class="w-4 h-4 absolute left-3 top-2.5 text-slate-400" /><input name="q" value="{{ request('q') }}" class="input pl-9 w-64" placeholder="Name or code"></div>
  <select name="office" class="input w-48"><option value="">All offices</option><option value="none" @selected(request('office') === 'none')>No office yet</option>@foreach($offices as $o)<option value="{{ $o->id }}" @selected(request('office') == $o->id)>{{ $o->name }}</option>@endforeach</select>
  <label class="text-sm flex items-center gap-1"><input type="checkbox" name="status" value="all" @checked(request('status') === 'all')> Include inactive</label>
  <button class="btn-ghost">Apply</button>
</form>
<div class="card p-0 overflow-x-auto">
  <table class="tbl"><tr><th>Employee</th><th>Office · gate</th><th>Allowed to scan at</th><th>Phones</th><th>Status</th><th></th></tr>
  @forelse($employees as $e)<tr>
    <td class="font-medium text-navy">{{ $e->name }}<div class="text-xs text-slate-500">{{ $e->employee_code }}{{ $e->department ? ' · '.$e->department : '' }}</div></td>
    <td>@if($e->office){{ $e->office->name }}{{ $e->gate ? ' · '.$e->gate->name : '' }}@else<span class="chip bg-amber-100 text-amber-800">not assigned</span>@endif</td>
    <td class="text-xs">{{ \Ametecs\Qronex\Models\Employee::SCAN_SCOPES[$e->scan_scope ?: 'office'] }}</td>
    <td class="text-xs">{{ $e->approved_devices }} / {{ $e->deviceLimit() }}</td>
    <td><x-qx::status :s="$e->status" /></td>
    <td class="text-right whitespace-nowrap">@if($e->status === 'active' && \Ametecs\Qronex\Qronex::can(auth()->user(), 'manage'))
      <a class="btn-ghost btn-sm" href="{{ route('qronex.employees.edit', $e) }}">Set office</a>
      <form method="post" action="{{ route('qronex.employees.activation', $e) }}" class="inline">@csrf<button class="btn btn-sm" @disabled(! $e->office_id)>Activate app</button></form>@endif</td></tr>
  @empty<tr><td colspan="6"><x-qx::empty icon="users" title="No employees" text="Add employees in {{ $host->name() }}; they appear here after the next sync." /></td></tr>@endforelse
  </table>
</div>
<div>{{ $employees->links() }}</div>
@endsection
