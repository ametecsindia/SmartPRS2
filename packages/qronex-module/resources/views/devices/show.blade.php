@extends('qronex::layout', ['title' => $d->model ?: 'Device', 'crumb' => '<a class="hover:text-teal" href="'.route('qronex.devices').'">Phones</a> / '.e($d->employee->name)])
@section('actions')@include('qronex::devices._actions')@endsection
@section('content')
<div class="grid lg:grid-cols-3 gap-4">
  <div class="card space-y-3">
    <div class="flex items-center gap-3"><span class="rounded-xl bg-navy/5 p-3 text-navy"><x-qx::icon name="phone" class="w-6 h-6" /></span>
      <div><div class="font-semibold text-navy">{{ $d->model }}</div><div class="text-xs text-slate-500">{{ $d->platform === 'ios' ? 'iOS' : 'Android' }} · {{ $d->os_version }}</div></div>
      <div class="ml-auto"><x-qx::status :s="$d->status" /></div></div>
    <dl class="text-sm grid grid-cols-3 gap-y-2">
      <dt class="text-slate-500">Employee</dt><dd class="col-span-2">{{ $d->employee->name }} <span class="text-slate-400">{{ $d->employee->employee_code }}</span></dd>
      <dt class="text-slate-500">Office</dt><dd class="col-span-2">{{ $d->employee->office?->name ?? '—' }}</dd>
      <dt class="text-slate-500">Install ref</dt><dd class="col-span-2 font-mono text-xs break-all">{{ $d->install_ref }}</dd>
      <dt class="text-slate-500">Registered</dt><dd class="col-span-2">{{ $d->created_at->timezone('Asia/Kolkata')->format('d M Y, H:i') }}</dd>
      <dt class="text-slate-500">Approved</dt><dd class="col-span-2">{{ $d->approved_at?->timezone('Asia/Kolkata')->format('d M Y, H:i') ?? '—' }}{{ $d->approver ? ' by '.$d->approver->name : '' }}</dd>
      <dt class="text-slate-500">Security</dt><dd class="col-span-2">@if($d->public_key)<span class="chip bg-teal-soft text-teal">Hardware key · every request signed</span>@else<span class="chip bg-amber-100 text-amber-800">Older app · token only</span>@endif</dd>
      <dt class="text-slate-500">Last seen</dt><dd class="col-span-2">{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</dd>
      @if($d->replaces)<dt class="text-slate-500">Replaces</dt><dd class="col-span-2"><a class="text-teal" href="{{ route('qronex.devices.show', $d->replaces) }}">{{ $d->replaces->model }}</a></dd>@endif
    </dl>
    @if($d->status === 'pending')<p class="text-xs rounded-lg bg-amber-50 text-amber-800 px-3 py-2">The employee sees a match code on their phone. Type it in when approving so you approve the right phone.</p>@endif
  </div>
  <div class="card lg:col-span-2">
    <div class="card-t mb-4">History</div>
    <ol class="relative border-l border-slate-200 ml-2 space-y-4">
      @forelse($d->events as $ev)
        @php($dot = ['approved' => 'bg-teal', 'auto_approved' => 'bg-teal', 'rejected' => 'bg-red-500', 'revoked' => 'bg-red-500', 'replaced' => 'bg-red-500'][$ev->action] ?? 'bg-amber-400')
        <li class="ml-4"><span class="absolute -left-[5px] mt-1.5 h-2.5 w-2.5 rounded-full {{ $dot }} ring-4 ring-white"></span>
          <div class="text-sm font-medium text-navy">{{ \Ametecs\Qronex\Models\DeviceEvent::LABELS[$ev->action] ?? $ev->action }}</div>
          <div class="text-xs text-slate-500">{{ $ev->created_at->timezone('Asia/Kolkata')->format('d M Y, H:i') }} · {{ $ev->actor?->name ?? ($ev->action === 'registered' || str_contains($ev->action, 'registered') || $ev->action === 'replacement_requested' ? 'Employee app' : 'System') }}</div>
          @if($ev->reason)<div class="text-sm text-slate-700 mt-0.5">“{{ $ev->reason }}”</div>@endif
          @if($ev->meta && isset($ev->meta['ip']))<div class="text-[11px] text-slate-400">{{ $ev->meta['model'] ?? '' }} · IP {{ $ev->meta['ip'] }}</div>@endif
        </li>
      @empty
        <li class="ml-4 text-sm text-slate-500">No history recorded for this phone (registered before history was added).</li>
      @endforelse
    </ol>
  </div>
</div>
<div class="grid lg:grid-cols-2 gap-4">
  <div class="card p-0 overflow-x-auto"><div class="card-t p-5 pb-2">Last punches from this phone</div>
    <table class="tbl"><tr><th>When</th><th>Type</th><th>Where</th></tr>
    @forelse($punches as $p)<tr><td class="text-xs whitespace-nowrap">{{ $p->recorded_at->timezone('Asia/Kolkata')->format('d M, H:i') }}</td><td><span class="chip {{ $p->type === 'IN' ? 'bg-teal-soft text-teal' : 'bg-slate-100 text-slate-700' }}">{{ $p->type }}</span></td><td class="text-sm">{{ $p->office?->name ?? $p->mode }}{{ $p->gate ? ' · '.$p->gate->name : '' }}</td></tr>
    @empty<tr><td colspan="3"><x-qx::empty icon="clock" title="No punches yet" class="py-6" /></td></tr>@endforelse</table></div>
  <div class="card p-0 overflow-x-auto"><div class="card-t p-5 pb-2">{{ strtok($d->employee->name, ' ') }}'s other phones</div>
    <table class="tbl"><tr><th>Phone</th><th>Registered</th><th>Status</th></tr>
    @forelse($others as $o)<tr><td><a class="text-navy hover:text-teal" href="{{ route('qronex.devices.show', $o) }}">{{ $o->model }}</a></td><td class="text-xs">{{ $o->created_at->timezone('Asia/Kolkata')->format('d M Y') }}</td><td><x-qx::status :s="$o->status" /></td></tr>
    @empty<tr><td colspan="3"><x-qx::empty icon="phone" title="No other phones" class="py-6" /></td></tr>@endforelse</table></div>
</div>
@endsection
