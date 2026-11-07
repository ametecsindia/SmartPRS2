@push('head')
{{-- Leaflet 1.9.4 served from the module. ponytail: OSM tiles are for light use; switch to a commercial tile provider at scale. --}}
<link rel="stylesheet" href="{{ route('qronex.asset', ['f' => 'leaflet.css']) }}">
<script src="{{ route('qronex.asset', ['f' => 'leaflet.js']) }}"></script>
@endpush
