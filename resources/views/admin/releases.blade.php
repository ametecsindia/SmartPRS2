@extends('admin.layout')
@section('title', 'Releases & Platform Updates')
@section('nav_releases', 'active')

@section('content')
    <h1>Releases &amp; Platform Updates</h1>
    <p class="sub">Platform is on <strong>version {{ $current }}</strong>. Upload a release built by BUILD-RELEASE.bat, APPLY it to this platform (all tenants + demo + teamdemo update together, with automatic backup &amp; rollback), then PUBLISH it to on-prem clients — AMC-active clients are granted and emailed.</p>

    @if (session('success'))
        <div class="flash">{{ session('success') }}</div>
    @endif

    <details style="margin-bottom:22px;">
        <summary style="cursor:pointer;font-weight:700;color:var(--accent);margin-bottom:10px;">+ Upload a release</summary>
        <form method="POST" action="{{ route('admin.releases.upload') }}" enctype="multipart/form-data" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;display:grid;grid-template-columns:1fr 2fr;gap:12px;">
            @csrf
            <label>Version * (e.g. 2026.6.2)<input name="version" required pattern="[0-9][0-9.]*"></label>
            <label>Package zip * (from BUILD-RELEASE.bat)<input type="file" name="package" accept=".zip" required></label>
            <label style="grid-column:span 2;">What is new * (plain language — this goes into the client email, one point per line)<textarea name="notes" rows="4" required style="width:100%;padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;margin-top:4px;"></textarea></label>
            <div><button class="btn btn-primary" type="submit">Upload &amp; register</button></div>
        </form>
    </details>

    <table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
        <thead><tr style="background:#0c1929;color:#fff;font-size:12px;text-align:left;">
            <th style="padding:10px 12px;">Version</th><th style="padding:10px 12px;">Uploaded</th><th style="padding:10px 12px;">Platform</th><th style="padding:10px 12px;">Published</th><th style="padding:10px 12px;">Grants</th><th style="padding:10px 12px;"></th>
        </tr></thead>
        <tbody>
        @forelse ($releases as $r)
            <tr style="border-top:1px solid #e2e8f0;font-size:13px;">
                <td style="padding:10px 12px;"><strong>{{ $r->version }}</strong><div style="font-size:11px;color:#64748b;max-width:380px;white-space:pre-line;">{{ \Illuminate\Support\Str::limit($r->notes, 160) }}</div></td>
                <td style="padding:10px 12px;">{{ \Carbon\Carbon::parse($r->created_at)->format('d M Y') }}<div style="font-size:11px;color:#64748b;">{{ number_format($r->size / 1048576, 1) }} MB</div></td>
                <td style="padding:10px 12px;">{{ $r->applied_platform_at ? '✓ applied' : '—' }}</td>
                <td style="padding:10px 12px;">{{ $r->published_at ? '✓' : '—' }}</td>
                <td style="padding:10px 12px;">{{ $grantCounts->get($r->id, 0) }} / {{ $clientCount }}</td>
                <td style="padding:10px 12px;white-space:nowrap;">
                    <form method="POST" action="{{ route('admin.releases.apply', $r->id) }}" style="display:inline;" onsubmit="return confirm('Apply {{ $r->version }} to THIS PLATFORM now? Maintenance mode for ~2 minutes; automatic backup + rollback protect it.');">@csrf<button class="btn btn-outline" style="font-size:11px;">Apply to platform</button></form>
                    <form method="POST" action="{{ route('admin.releases.publish', $r->id) }}" style="display:inline;" onsubmit="return confirm('Publish {{ $r->version }} and grant + email ALL AMC-active clients?');">@csrf<button class="btn btn-primary" style="font-size:11px;">Publish to clients</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" style="padding:18px;color:#64748b;">No releases uploaded yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2 style="font-size:16px;margin:26px 0 10px;">Recent client update activity</h2>
    <table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;font-size:12.5px;">
        <thead><tr style="background:#f1f5f9;text-align:left;"><th style="padding:8px 12px;">When</th><th style="padding:8px 12px;">Client</th><th style="padding:8px 12px;">Action</th><th style="padding:8px 12px;">Detail</th></tr></thead>
        <tbody>
        @forelse ($log as $l)
            <tr style="border-top:1px solid #e2e8f0;"><td style="padding:8px 12px;white-space:nowrap;">{{ \Carbon\Carbon::parse($l->created_at)->format('d M H:i') }}</td><td style="padding:8px 12px;">#{{ $l->client_id ?? '—' }}</td><td style="padding:8px 12px;">{{ $l->action }}</td><td style="padding:8px 12px;color:#64748b;">{{ \Illuminate\Support\Str::limit($l->detail, 90) }}</td></tr>
        @empty
            <tr><td colspan="4" style="padding:14px;color:#64748b;">No activity yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    <style>
        label { font-size: 12px; color: #475569; font-weight: 600; display: block; }
        label input { width: 100%; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; margin-top: 4px; }
    </style>
@endsection
