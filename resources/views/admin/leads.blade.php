@extends('admin.layout')
@section('title', 'Leads')
@section('nav_leads', 'active')

@section('content')
    <h1>Leads</h1>
    <p class="sub">Demo requests from the public website (smartprs.com). Each new lead is also emailed / WhatsApp-alerted per the settings in the <a href="{{ route('landing.editor') }}" style="color:var(--accent)">Landing CMS</a>.</p>

    @if (session('success'))
        <div class="flash">{{ session('success') }}</div>
    @endif

    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
        @foreach (['all' => 'All', 'new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed'] as $k => $lbl)
            <a href="{{ route('admin.leads', $k === 'all' ? [] : ['status' => $k]) }}"
               class="btn {{ $status === $k ? 'btn-primary' : 'btn-outline' }}" style="text-decoration:none;">
                {{ $lbl }}
                @if ($k === 'all')
                    ({{ $counts->sum() }})
                @else
                    ({{ $counts[$k] ?? 0 }})
                @endif
            </a>
        @endforeach
    </div>

    <table>
        <thead>
            <tr><th>#</th><th>When</th><th>Name / Company</th><th>Contact</th><th>City</th><th>Employees</th><th>Challenges / Notes</th><th>Status</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r->id }}</td>
                    <td style="white-space:nowrap;">{{ \Carbon\Carbon::parse($r->created_at)->format('d M Y H:i') }}</td>
                    <td><b>{{ $r->name }}</b>{{ $r->designation ? ' · '.$r->designation : '' }}<br><span style="color:var(--text2);">{{ $r->company }}</span></td>
                    <td style="white-space:nowrap;">
                        <a href="tel:{{ $r->mobile }}" style="color:var(--navy);">{{ $r->mobile }}</a>
                        · <a href="https://wa.me/91{{ preg_replace('/\D+/', '', substr($r->mobile, -10)) }}" target="_blank" rel="noopener" style="color:#25d366;"><i class="fab fa-whatsapp"></i></a><br>
                        <a href="mailto:{{ $r->email }}" style="color:var(--text2);">{{ $r->email }}</a>
                    </td>
                    <td>{{ $r->city }}</td>
                    <td>{{ $r->employees }}</td>
                    <td style="max-width:260px;">
                        <div style="font-size:13px;color:var(--text2);">{{ $r->challenges }}</div>
                        @if ($r->notes)<div style="font-size:12px;color:var(--accent);margin-top:4px;"><i class="fas fa-pen"></i> {{ $r->notes }}</div>@endif
                    </td>
                    <td style="min-width:190px;">
                        <form method="POST" action="{{ route('admin.leads.update', $r->id) }}?back={{ $status }}">
                            @csrf
                            <select name="status" style="padding:6px 8px;font-size:13px;width:100%;">
                                @foreach (['new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed'] as $k => $lbl)
                                    <option value="{{ $k }}" {{ $r->status === $k ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                            <input name="notes" value="{{ $r->notes }}" placeholder="Note…" style="margin-top:6px;padding:6px 8px;font-size:13px;width:100%;">
                            <button class="btn btn-outline" style="margin-top:6px;padding:6px 12px;font-size:12px;">Save</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--text3);padding:28px;">No leads yet — they appear here the moment someone submits the demo form on the website.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
