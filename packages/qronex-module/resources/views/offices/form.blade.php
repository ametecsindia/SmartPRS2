@extends('qronex::layout', ['title' => $office->exists ? 'Edit office' : 'Add office'])
@section('content')
<form method="post" action="{{ $office->exists ? route('qronex.offices.update', $office) : route('qronex.offices.store') }}" class="grid lg:grid-cols-2 gap-4">
  @csrf @if($office->exists) @method('PUT') @endif
  <div class="card grid sm:grid-cols-2 gap-4 content-start">
    <x-qx::field name="code" label="Office code" :value="$office->code" placeholder="HYD01" required />
    <x-qx::field name="name" label="Office name" :value="$office->name" required />
    <x-qx::field name="slug" label="URL name" :value="$office->slug" hint="Used in display URLs. Leave blank to use the name." />
    <x-qx::field class="sm:col-span-2" name="address" label="Address" :value="$office->address" />
    <x-qx::field name="city" label="City" :value="$office->city" />
    <x-qx::field name="lat" label="Latitude" type="number" step="0.0000001" :value="$office->lat" required />
    <x-qx::field name="lng" label="Longitude" type="number" step="0.0000001" :value="$office->lng" required />
    <x-qx::field name="geofence_radius_m" label="Geofence radius (metres)" type="number" min="30" max="2000" :value="$office->geofence_radius_m" required />
    <label class="flex items-center gap-2 text-sm pt-5"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $office->is_active))> Active</label>
    <div class="col-span-full flex gap-2"><button class="btn">Save office</button><a class="btn-ghost" href="{{ route('qronex.offices') }}">Cancel</a></div>
  </div>
  <div class="card p-0 overflow-hidden"><div id="map" class="h-[28rem]"></div><p class="text-xs text-slate-500 p-3">Click the map or drag the pin to set the office location. The circle is the geofence.</p></div>
</form>
@endsection
@include('qronex::offices.leaflet')
@push('scripts')
<script>
const $ = id => document.getElementById(id), lat = $('f-lat'), lng = $('f-lng'), rad = $('f-geofence_radius_m');
const p = [+lat.value || 17.385, +lng.value || 78.4867];
const map = L.map('map').setView(p, 16);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap'}).addTo(map);
const pin = L.marker(p, {draggable: true, icon: L.divIcon({className: '', iconSize: [18, 18], html: '<div style="width:18px;height:18px;border-radius:50%;background:#0A8278;border:3px solid #fff;box-shadow:0 0 4px #0007"></div>'})}).addTo(map), ring = L.circle(p, {radius: +rad.value || 150, color: '#0A8278'}).addTo(map);
const set = ll => { pin.setLatLng(ll); ring.setLatLng(ll); lat.value = ll.lat.toFixed(7); lng.value = ll.lng.toFixed(7); };
map.on('click', e => set(e.latlng)); pin.on('dragend', () => set(pin.getLatLng()));
[lat, lng].forEach(i => i.addEventListener('change', () => { const ll = L.latLng(+lat.value, +lng.value); set(ll); map.panTo(ll); }));
rad.addEventListener('input', () => ring.setRadius(+rad.value || 0));
</script>
@endpush
