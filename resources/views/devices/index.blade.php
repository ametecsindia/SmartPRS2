@extends('layouts.app')

@section('title', 'Biometric Devices')
@section('nav_devices_settings', 'active')

@section('content')
    @if (session('success'))
        <div style="background:var(--green-soft);color:#059669;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-check"></i> {{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div style="background:var(--red-soft);color:#dc2626;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-triangle-exclamation"></i> {{ session('error') }}</div>
    @endif

    <div class="page-header">
        <div><h2>Biometric Devices</h2><p>ZKTeco fingerprint / face machines · sync punches into attendance</p></div>
        <a href="{{ route('devices.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Add Device</a>
    </div>

    <div class="card">
        <div class="card-header"><div><h3>Registered Devices</h3><p>{{ $devices->count() }} devices · default port 4370</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Device</th><th>IP : Port</th><th>Location</th><th>Status</th><th>Last Sync</th><th style="text-align:right;">Actions</th></tr></thead>
                <tbody>
                    @forelse ($devices as $d)
                        <tr>
                            <td><strong>{{ $d->name }}</strong><div style="font-size:11px;color:var(--text3);">{{ $d->serial_number }}</div></td>
                            <td style="font-family:var(--mono);">{{ $d->ip_address }}:{{ $d->port }}</td>
                            <td>{{ $d->location ?? '—' }}</td>
                            <td>
                                <span class="badge {{ ['online' => 'badge-green', 'offline' => 'badge-red', 'unknown' => 'badge-gray'][$d->status] }}">{{ ucfirst($d->status) }}</span>
                            </td>
                            <td style="font-size:12px;color:var(--text3);">{{ $d->last_sync_at?->format('d M Y H:i') ?? 'never' }}</td>
                            <td style="text-align:right;white-space:nowrap;">
                                <form method="POST" action="{{ route('devices.sync', $d) }}" style="display:inline;">@csrf<button class="btn btn-outline btn-sm" title="Sync now"><i class="fas fa-rotate"></i> Sync</button></form>
                                <a href="{{ route('devices.edit', $d) }}" class="btn btn-ghost btn-sm" title="Edit"><i class="fas fa-pen"></i></a>
                                <form method="POST" action="{{ route('devices.destroy', $d) }}" style="display:inline;" onsubmit="return confirm('Remove {{ $d->name }}?');">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" style="color:var(--red);" title="Remove"><i class="fas fa-trash"></i></button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text3);">No devices registered. Add your first ZKTeco machine.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p style="font-size:12px;color:var(--text3);margin-top:14px;font-family:var(--font2);">
        <i class="fas fa-circle-info"></i> Sync connects to the device over your LAN (IP : port) and imports punches, matching each device user ID to an employee's "Device User ID". Set that field on the employee record for matching to work.
    </p>
@endsection
