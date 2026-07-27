@extends('layouts.app')

@section('title', $mode === 'edit' ? 'Edit Device' : 'Add Device')
@section('nav_devices_settings', 'active')

@php $action = $mode === 'edit' ? route('devices.update', $device) : route('devices.store'); @endphp

@section('content')
    <div class="page-header">
        <div><h2>{{ $mode === 'edit' ? 'Edit' : 'Add' }} Biometric Device</h2><p>ZKTeco device reachable on your local network</p></div>
        <a href="{{ route('devices.index') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    @if ($errors->any())
        <div style="background:var(--red-soft);color:#dc2626;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-exclamation"></i> Please fix the highlighted fields.</div>
    @endif

    <form method="POST" action="{{ $action }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif
        <div class="form-card">
            <h3><i class="fas fa-fingerprint"></i> Device</h3>
            <div class="form-grid">
                <div class="fg span2"><label>Name *</label><input type="text" name="name" value="{{ old('name', $device->name) }}" placeholder="Main Gate - ZKTeco K40" required></div>
                <div class="fg"><label>Serial Number</label><input type="text" name="serial_number" value="{{ old('serial_number', $device->serial_number) }}"></div>
                <div class="fg"><label>IP Address *</label><input type="text" name="ip_address" value="{{ old('ip_address', $device->ip_address) }}" placeholder="192.168.1.201" required></div>
                <div class="fg"><label>Port *</label><input type="number" name="port" value="{{ old('port', $device->port) }}" required></div>
                <div class="fg"><label>Location</label><input type="text" name="location" value="{{ old('location', $device->location) }}" placeholder="HO Reception"></div>
            </div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <a href="{{ route('devices.index') }}" class="btn btn-outline">Cancel</a>
            <button class="btn btn-primary"><i class="fas fa-check"></i> {{ $mode === 'edit' ? 'Save' : 'Register' }}</button>
        </div>
    </form>
@endsection
