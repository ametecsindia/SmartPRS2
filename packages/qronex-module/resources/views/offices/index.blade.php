@extends('qronex::layout', ['title' => 'Offices & gates', 'crumb' => 'Organisation'])
@section('actions')<a class="btn btn-sm" href="{{ route('qronex.offices.create') }}"><x-qx::icon name="plus" class="w-4 h-4" /> Add office</a>@endsection
@section('content')
@php($gates = $offices->flatMap->gates)
@php($liveN = $gates->filter(fn ($g) => $g->is_active && $g->display_last_seen_at?->gt(now()->subMinute()))->count())
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4">
  <x-qx::stat label="Offices" :value="$offices->where('is_active', true)->count()" icon="building" tone="teal" :sub="$offices->where('is_active', false)->count().' inactive'" />
  <x-qx::stat label="Gates" :value="$gates->where('is_active', true)->count()" icon="qr" sub="Entrances with a QR screen" />
  <x-qx::stat label="Screens live now" :value="$liveN.' / '.$gates->where('is_active', true)->count()" icon="window" :tone="$liveN < $gates->where('is_active', true)->count() ? 'amber' : 'teal'" sub="Heartbeat in the last minute" />
  <x-qx::stat label="Employees" :value="$offices->sum('employees_count')" icon="users" tone="sky" sub="Assigned to these offices" />
</div>
@if($offices->isEmpty())<div class="card"><x-qx::empty icon="building" title="No offices yet" text="Add an office with its location. A gate and its QR display link are created a gate and its QR display link for you."><a class="btn" href="{{ route('qronex.offices.create') }}">Add office</a></x-qx::empty></div>@endif
<div class="card p-0 overflow-hidden"><div id="map" class="h-72"></div></div>
<div class="grid lg:grid-cols-2 gap-4">
@foreach($offices as $o)
  <div class="card space-y-3 min-w-0">
    <div class="flex items-start justify-between gap-2">
      <div><h2 class="font-bold text-navy">{{ $o->name }} <span class="text-xs text-slate-500">{{ $o->code }}</span> @unless($o->is_active)<span class="chip bg-slate-200">inactive</span>@endunless</h2>
        <div class="text-xs text-slate-500">{{ $o->address }}, {{ $o->city }} · {{ $o->lat }}, {{ $o->lng }} · radius {{ $o->geofence_radius_m >= 1000 ? number_format($o->geofence_radius_m / 1000).' km' : $o->geofence_radius_m.' m' }} · {{ $o->employees_count }} employees</div></div>
      <a class="btn-ghost btn-sm" href="{{ route('qronex.offices.edit', $o) }}">Edit</a>
    </div>
    <div class="overflow-x-auto -mx-1 px-1"><table class="tbl"><tr><th>Gate</th><th>Entrance display</th><th></th></tr>
    @foreach($o->gates as $g)
      @php($url = route('qronex.display', [$g->id, 'key' => $g->display_secret]))
      <tr class="{{ $g->is_active ? '' : 'opacity-50' }}"><td>{{ $g->name }} <span class="text-xs text-slate-500">{{ $g->code }}</span>
        <div class="text-xs {{ $g->display_last_seen_at?->gt(now()->subMinute()) ? 'text-emerald-700' : 'text-slate-500' }}">{{ $g->display_last_seen_at ? 'Screen seen '.$g->display_last_seen_at->diffForHumans() : 'No screen yet' }}</div></td>
      <td class="text-xs" x-data="{s:false}"><a class="btn btn-sm" href="{{ $url }}" target="_blank" rel="noopener">Open display</a>
        <button type="button" class="btn-ghost btn-sm" @click="navigator.clipboard?.writeText(@js($url)); s=true; setTimeout(()=>s=false,1500)" x-text="s?'Copied':'Copy link'">Copy link</button>
        <div class="text-slate-500 mt-1">Anyone with this link can show live codes. Reset it if it leaks.</div></td>
      <td class="text-right whitespace-nowrap">
        <form method="post" action="{{ route('qronex.gates.reset', $g) }}" class="inline" onsubmit="return confirm('Reset this display link? Screens using the old link stop showing codes.')">@csrf<button class="btn-ghost btn-sm">Reset link</button></form>
        <form method="post" action="{{ route('qronex.gates.toggle', $g) }}" class="inline">@csrf<button class="btn-ghost btn-sm">{{ $g->is_active ? 'Disable' : 'Enable' }}</button></form></td></tr>
    @endforeach
    </table></div>
    <form method="post" action="{{ route('qronex.gates.store', $o) }}" class="flex flex-wrap gap-2 items-end">
      @csrf <input name="code" class="input max-w-[8rem]" placeholder="gate2" required><input name="name" class="input max-w-[14rem]" placeholder="Gate name" required><button class="btn-ghost btn-sm">Add gate</button>
    </form>
  </div>
@endforeach
</div>
@endsection
@include('qronex::offices.leaflet')
@push('scripts')
<script>
const offices = @js($offices->map->only(['name', 'lat', 'lng', 'geofence_radius_m']));
const map = L.map('map').setView([20.59, 78.96], 5); // a view must exist before layers are added
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap'}).addTo(map);
offices.forEach(o => L.circle([o.lat, o.lng], {radius: o.geofence_radius_m, color: '#0A8278'}).bindTooltip(o.name).addTo(map));
if (offices.length) map.fitBounds(L.latLngBounds(offices.map(o => [o.lat, o.lng])).pad(0.3), {maxZoom: 16});
</script>
@endpush
