<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #0f172a; font-size: 8px; }
        .head { background: #0c1929; color: #fff; padding: 14px 20px; }
        .brand { font-size: 16px; font-weight: bold; }
        .muted { color: #94a3b8; font-size: 10px; }
        .wrap { padding: 14px 20px; }
        h2 { font-size: 12px; color: #0c1929; margin: 0 0 2px; }
        table { width: 100%; border-collapse: collapse; }
        .grid { margin-top: 8px; }
        .grid th { background: #f8fafc; text-align: left; padding: 5px 4px; font-size: 7px; text-transform: uppercase;
             color: #64748b; border-bottom: 1px solid #e2e8f0; }
        .grid td { padding: 4px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .grid tr:nth-child(even) td { background: #fbfcfd; }
        .r { text-align: right; }
        .tot td { font-weight: bold; background: #f8fafc; border-top: 1px solid #cbd5e1; }
        .foot { margin-top: 12px; color: #94a3b8; font-size: 8px; }
    </style>
</head>
<body>
    <div class="head">
        <table>
            <tr>
                <td>
                    @php $logoSrc = $brand['logo_file'] ?? ($brand['logo'] ?? ''); @endphp
                    @if (!empty($logoSrc))
                        <img src="{{ $logoSrc }}" style="max-height:32px;max-width:120px;vertical-align:middle;">
                    @endif
                    <div class="brand" style="color: {{ $brand['color'] ?? '#f97316' }};">{{ $brand['display_name'] ?? ($company->name ?? 'SmartPRS') }}</div>
                    <div class="muted">{{ $company->name ?? 'SmartPRS' }}</div>
                </td>
                <td style="text-align:right;">
                    <div style="font-size:14px;font-weight:bold;">{{ $title }}</div>
                    <div class="muted">Generated {{ $generated }}</div>
                </td>
            </tr>
        </table>
    </div>
    <div class="wrap">
        <h2>{{ $title }} &mdash; {{ count($rows) }} item(s)</h2>
        <table class="grid">
            <thead>
                <tr>
                    @foreach ($head as $i => $h)
                        <th class="{{ in_array($i, $money, true) ? 'r' : '' }}">{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr>
                        @foreach ($r as $i => $v)
                            <td class="{{ in_array($i, $money, true) ? 'r' : '' }}">{{ in_array($i, $money, true) ? number_format((float) $v, 2) : \App\Support\DateFmt::text($v) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($head) }}" style="text-align:center;padding:18px;color:#94a3b8;">Nothing to show.</td></tr>
                @endforelse
                @if (count($rows))
                    <tr class="tot">
                        @foreach ($head as $i => $h)
                            <td class="{{ in_array($i, $money, true) ? 'r' : '' }}">{{ $i === 0 ? 'Total' : (in_array($i, $money, true) ? number_format($total, 2) : '') }}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
        <div class="foot">SmartPRS &middot; Asset Issue &amp; Returns &middot; Confidential</div>
    </div>
</body>
</html>
