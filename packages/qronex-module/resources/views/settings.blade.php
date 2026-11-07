@extends('qronex::layout', ['title' => 'Module & policy', 'crumb' => 'Settings'])
@section('content')
<div class="card flex flex-wrap items-center gap-4">
  <span class="rounded-xl p-3 {{ $s->enabled && $licensed ? 'bg-teal-soft text-teal' : 'bg-slate-100 text-slate-500' }}"><x-qx::icon name="qr" class="w-6 h-6" /></span>
  <div class="flex-1 min-w-[16rem]">
    <div class="font-semibold text-navy">QR attendance is {{ $s->enabled && $licensed ? 'ON' : 'OFF' }} for {{ $host->tenant(\Ametecs\Qronex\Qronex::tenantId())['name'] ?? 'this company' }}</div>
    <p class="text-sm text-slate-600">When on, employees mark attendance by scanning the rotating QR at the office entrance with their approved phone, and every punch is recorded in {{ $host->name() }} attendance just like a biometric punch.
      When off, phones and entrance screens stop working; nothing else in {{ $host->name() }} changes and all QR data is kept.</p>
    @unless($licensed)<p class="text-sm text-amber-700 mt-1">Your {{ $host->name() }} plan does not include QR attendance. Contact Ametecs to add it.</p>@endunless
  </div>
  @if($canConfigure)
    <form method="post" action="{{ route('qronex.settings.toggle') }}" @if($s->enabled) onsubmit="return confirm('Turn QR attendance off? Phones and entrance screens will stop working until you turn it back on.')" @endif>@csrf
      <input type="hidden" name="enabled" value="{{ $s->enabled ? 0 : 1 }}">
      <button class="{{ $s->enabled ? 'btn-ghost btn-danger' : 'btn' }}" @disabled(! $s->enabled && ! $licensed)>{{ $s->enabled ? 'Disable module' : 'Enable module' }}</button>
    </form>
  @else
    <span class="text-xs text-slate-500">Only an administrator can turn the module on or off.</span>
  @endif
</div>

@if($s->exists)
<form method="post" action="{{ route('qronex.settings.update') }}" class="space-y-4 max-w-6xl">
  @csrf @method('PUT')
  <fieldset @disabled(! $canConfigure) class="grid lg:grid-cols-2 gap-4 items-start">
    <div class="card grid sm:grid-cols-2 gap-4">
      <div class="col-span-full"><div class="card-t flex items-center gap-2"><x-qx::icon name="qr" class="w-5 h-5 text-teal" /> Rotating QR</div><p class="text-xs text-slate-500 mt-1">A new code shows on the entrance screen every few seconds, so a photo of it can't be reused.</p></div>
      <x-qx::field name="qr_interval_s" label="New code every (seconds)" type="number" min="2" max="30" :value="$s->qr_interval_s" required />
      <x-qx::field name="grace_s" label="Accept a just-expired code for (seconds)" type="number" min="0" max="15" :value="$s->grace_s" required />
      <x-qx::field name="duplicate_window_min" label="Ignore repeat scans within (minutes)" type="number" min="0" max="60" :value="$s->duplicate_window_min" required />
    </div>
    <div class="card grid sm:grid-cols-2 gap-4">
      <div class="col-span-full"><div class="card-t flex items-center gap-2"><x-qx::icon name="globe" class="w-5 h-5 text-teal" /> Location</div><p class="text-xs text-slate-500 mt-1">The phone must be inside the office geofence with a good GPS fix.</p></div>
      <x-qx::field name="gps_accuracy_m" label="Required GPS accuracy (metres)" type="number" min="10" max="500" :value="$s->gps_accuracy_m" hint="50 m suits most offices; raise it for basements" required />
      <div class="col-span-full"><label class="label" for="ln">Location notice shown to employees</label>
        <textarea id="ln" name="location_notice" rows="3" class="input" required>{{ old('location_notice', $s->location_notice) }}</textarea></div>
    </div>
    <div class="card space-y-3">
      <div class="card-t flex items-center gap-2"><x-qx::icon name="phone" class="w-5 h-5 text-teal" /> Phones</div>
      <p class="text-sm text-slate-600">One phone per employee. An administrator can allow more for a specific employee on the employee's page.</p>
      <label class="flex items-start gap-2 text-sm rounded-lg bg-slate-50 p-3"><input class="mt-0.5" type="checkbox" name="require_device_approval" value="1" @checked($s->require_device_approval)>
        <span><b class="text-navy">New phones need approval</b><br><span class="text-xs text-slate-500">The approver types the 8-character match code shown on the employee's phone and picks the office and gate.</span></span></label>
    </div>
    <div class="card space-y-3">
      <div class="card-t flex items-center gap-2"><x-qx::icon name="mail" class="w-5 h-5 text-teal" /> Email approvers</div>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify_device_requests" value="1" @checked($s->notify_device_requests)> When a phone waits for approval</label>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify_remote_requests" value="1" @checked($s->notify_remote_requests)> When remote attendance or a missed punch is requested</label>
    </div>
  </fieldset>
  @if($canConfigure)<button class="btn">Save policy</button>@endif
</form>
@endif
@endsection
