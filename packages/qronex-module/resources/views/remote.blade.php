@extends('qronex::layout', ['title' => 'Remote attendance', 'crumb' => 'Approvals'])
@section('content')
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4">
  <x-qx::stat label="Remote today" :value="$counts['today']" icon="globe" tone="teal" sub="Approved for today" />
  <x-qx::stat label="Waiting for you" :value="$counts['pending']" icon="check" :tone="$counts['pending'] ? 'red' : 'navy'" sub="Requests from the app" :href="route('qronex.approvals')" />
  <x-qx::stat label="Location waived" :value="$counts['waived']" icon="alert" :tone="$counts['waived'] ? 'amber' : 'navy'" sub="Current or upcoming exceptions" />
  <x-qx::stat label="Remote punches · 30 days" :value="$counts['punches']" icon="clock" tone="sky" sub="Verified without an office QR" />
</div>
<p class="text-sm text-slate-600">A remote permission lets an employee use <b>Remote check-in</b> in the app without an office QR, on approved dates only — optionally only near an approved location.</p>
<details class="card [&[open]]:ring-2 [&[open]]:ring-teal/30" @if($errors->any()) open @endif><summary class="font-semibold text-navy cursor-pointer">Grant a remote permission</summary>
  <form method="post" action="{{ route('qronex.remote.store') }}" class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-3" x-data="{w:@js((bool) old('location_waived'))}">
    @csrf
    <div class="sm:col-span-2"><label class="label">Employee</label><select name="employee_id" class="input" required>@foreach($employees as $e)<option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->name }} · {{ $e->employee_code }}</option>@endforeach</select></div>
    <div><label class="label">Type</label><select name="type" class="input">@foreach(\Ametecs\Qronex\Models\RemotePermission::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('type') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div></div>
    <x-qx::field name="from_date" label="From" type="date" :value="today('Asia/Kolkata')->toDateString()" required />
    <x-qx::field name="to_date" label="To" type="date" :value="today('Asia/Kolkata')->addDays(2)->toDateString()" required />
    <label class="flex items-center gap-2 text-sm sm:col-span-2 pt-5"><input type="checkbox" name="location_waived" value="1" x-model="w"> Waive location check (audited exception — e.g. field duty)</label>
    <template x-if="!w"><div class="contents">
      <x-qx::field name="location_label" label="Approved location" placeholder="Home — Kukatpally" />
      <x-qx::field name="lat" label="Latitude" type="number" step="0.0000001" />
      <x-qx::field name="lng" label="Longitude" type="number" step="0.0000001" />
      <x-qx::field name="radius_m" label="Radius (m)" type="number" value="250" />
    </div></template>
    <x-qx::field class="sm:col-span-2 lg:col-span-4" name="reason" label="Reason" required />
    <div><button class="btn">Grant</button></div>
  </form>
</details>
<div class="card overflow-x-auto">
  <table class="tbl"><tr><th>Ref</th><th>Employee</th><th>Type</th><th>Dates</th><th>Location rule</th><th>Reason</th><th>Decided</th><th>Status</th><th></th></tr>
  @forelse($perms as $p)<tr>
    <td class="text-xs font-mono">{{ $p->ref }}</td><td>{{ $p->employee->name }}</td><td>{{ $p->typeLabel() }}<div class="text-xs text-slate-500">via {{ $p->requested_via }}</div></td>
    <td class="text-xs whitespace-nowrap">{{ $p->from_date->format('d M') }} → {{ $p->to_date->format('d M Y') }}</td>
    <td class="text-xs">@if($p->location_waived)<span class="chip bg-amber-100 text-amber-800">Waived</span>@elseif($p->lat !== null){{ $p->location_label }}<br>{{ $p->radius_m }} m @else — @endif</td>
    <td class="text-xs">{{ $p->reason }}</td><td class="text-xs">{{ $p->decider?->name }}<br>{{ $p->decided_at?->timezone('Asia/Kolkata')->format('d M H:i') }}</td>
    <td><x-qx::status :s="$p->status" /></td>
    <td class="text-right">@if($p->status === 'approved')<form method="post" action="{{ route('qronex.remote.revoke', $p) }}" onsubmit="return confirm('Revoke this permission?')">@csrf<button class="btn-ghost btn-sm">Revoke</button></form>@elseif($p->status === 'pending')<a class="btn-ghost btn-sm" href="{{ route('qronex.approvals') }}">Review</a>@endif</td></tr>
  @empty<tr><td colspan="9"><x-qx::empty icon="globe" title="No remote permissions" text="Grant one above, or approve a request an employee sends from the app." class="py-6" /></td></tr>@endforelse
  </table><div class="mt-3">{{ $perms->links() }}</div></div>
<div class="card overflow-x-auto"><h2 class="font-semibold text-navy mb-2">Recent remote punches</h2>
  <table class="tbl"><tr><th>Time (IST)</th><th>Employee</th><th>Type</th><th>Mode</th><th>Permission</th><th class="text-right">Distance</th></tr>
  @forelse($events as $e)<tr><td class="text-xs whitespace-nowrap">{{ $e->recorded_at->timezone('Asia/Kolkata')->format('d M H:i') }}</td><td>{{ $e->employee->name }}</td><td>{{ $e->type }}</td>
    <td class="text-xs font-mono">{{ $e->mode }}</td><td class="text-xs font-mono">{{ $e->remotePermission?->ref }}</td><td class="text-right text-xs">{{ $e->distance_m !== null ? $e->distance_m.' m' : 'waived' }}</td></tr>
  @empty<tr><td colspan="6" class="text-slate-500">None yet.</td></tr>@endforelse</table></div>
@endsection
