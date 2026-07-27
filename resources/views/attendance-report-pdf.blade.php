<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #0f172a; font-size: 9px; }
        .head { background: #0c1929; color: #fff; padding: 14px 20px; }
        .brand { font-size: 16px; font-weight: bold; } .brand span { color: #f97316; }
        .muted { color: #94a3b8; font-size: 10px; }
        .wrap { padding: 16px 20px; }
        h2 { font-size: 13px; color: #0c1929; margin: 0 0 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f8fafc; text-align: left; padding: 5px 6px; font-size: 7.5px; text-transform: uppercase;
             color: #64748b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 5px 6px; border-bottom: 1px solid #f1f5f9; }
        .c { text-align: center; }
        .in { color: #16a34a; font-weight: bold; }
        .out { color: #dc2626; font-weight: bold; }
        .warn { color: #dc2626; font-weight: bold; }
        .foot { margin-top: 14px; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    <div class="head">
        <table>
            <tr>
                <td>
                    @php $logoSrc = $brand['logo_file'] ?? ($brand['logo'] ?? ''); @endphp
                    @if (!empty($logoSrc))
                        <img src="{{ $logoSrc }}" style="max-height:32px;max-width:120px;object-fit:contain;vertical-align:middle;">
                    @endif
                    <div class="brand" style="color: {{ $brand['color'] ?? '#f97316' }};">{{ $brand['display_name'] ?? ($company->name ?? 'SmartPRS') }}</div>
                    @if(!empty($brand['tagline'])) <div style="font-size:10px;color:#666;font-style:italic;">{{ $brand['tagline'] }}</div> @endif
                    <div class="muted">{{ $company->name ?? 'SmartPRS' }}</div>
                </td>
                <td style="text-align:right;">
                    <div style="font-size:14px;font-weight:bold;">Attendance Report</div>
                    <div class="muted">{{ $from }}@if($to !== $from) &rarr; {{ $to }} @endif &middot; Shift {{ $shift['start'] }}&ndash;{{ $shift['end'] }}</div>
                </td>
            </tr>
        </table>
    </div>
    <div class="wrap">
        <h2>Daily punch summary &mdash; {{ count($rows) }} record(s)</h2>
        <table>
            <thead>
                <tr>
                    <th>Employee</th><th>Code</th><th>Branch</th><th>Team</th><th>Manager</th><th>Leader</th>
                    <th>Date</th><th>First In</th><th>Last Out</th><th>Total</th><th>Break</th>
                    <th class="c">IN</th><th class="c">OUT</th><th class="c">Rating</th><th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td>{{ $r['emp_name'] }}</td>
                        <td>{{ $r['emp_code'] }}</td>
                        <td>{{ $r['branch'] ?? '' }}</td>
                        <td>{{ $r['team'] ?? '' }}</td>
                        <td>{{ $r['reporting'] ?? '' }}</td>
                        <td>{{ $r['leader'] ?? '' }}</td>
                        <td>{{ $r['date'] }}</td>
                        <td class="in">{{ $r['first_in'] }}</td>
                        <td class="out">{{ $r['last_out'] }}</td>
                        <td>{{ $r['total_time'] }}</td>
                        <td class="{{ ($r['break_min'] ?? 0) > 90 ? 'warn' : '' }}">{{ $r['break_time'] }}</td>
                        <td class="c">{{ $r['in_count'] }}</td>
                        <td class="c">{{ $r['out_count'] }}</td>
                        <td class="c">{{ $r['rating'] ? $r['rating'].'/10' : '—' }}</td>
                        <td>{{ $r['remarks'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="15" style="text-align:center;padding:24px;color:#94a3b8;">No punch logs for this period.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="foot">Computer-generated attendance report &middot; breaks over 1h30m shown in red &middot; generated {{ now()->format('d M Y, H:i') }}.</div>
    </div>
</body>
</html>
