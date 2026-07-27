<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #0f172a; font-size: 11px; }
        .head { background: #0c1929; color: #fff; padding: 16px 22px; }
        .brand { font-size: 16px; font-weight: bold; } .brand span { color: #f97316; }
        .muted { color: #94a3b8; font-size: 10px; }
        .wrap { padding: 18px 22px; }
        h2 { font-size: 14px; color: #0c1929; margin: 0 0 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f8fafc; text-align: left; padding: 7px 8px; font-size: 9px; text-transform: uppercase;
             color: #64748b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 7px 8px; border-bottom: 1px solid #f1f5f9; }
        .amt { text-align: right; }
        .tot td { font-weight: bold; border-top: 2px solid #0c1929; background: #f8fafc; }
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
                        <img src="{{ $logoSrc }}" style="max-height:34px;max-width:120px;object-fit:contain;vertical-align:middle;">
                    @endif
                    <div class="brand" style="color: {{ $brand['color'] ?? '#f97316' }};">{{ $brand['display_name'] ?? ($company->name ?? 'SmartPRS') }}</div>
                    @if(!empty($brand['tagline'])) <div style="font-size:10px;color:#666;font-style:italic;">{{ $brand['tagline'] }}</div> @endif
                    <div class="muted">{{ $company->name ?? 'SmartPRS' }}</div>
                </td>
                <td style="text-align:right;"><div style="font-size:14px;font-weight:bold;">{{ $title }}</div><div class="muted">{{ $period }}</div></td>
            </tr>
        </table>
    </div>
    <div class="wrap">
        <h2>{{ $subtitle }}</h2>
        <table>
            <thead><tr>@foreach ($columns as $c)<th class="{{ $c['amt'] ?? false ? 'amt' : '' }}">{{ $c['l'] }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>@foreach ($columns as $c)<td class="{{ $c['amt'] ?? false ? 'amt' : '' }}">{{ $r[$c['k']] }}</td>@endforeach</tr>
                @endforeach
                <tr class="tot">@foreach ($columns as $c)<td class="{{ $c['amt'] ?? false ? 'amt' : '' }}">{{ $totals[$c['k']] ?? '' }}</td>@endforeach</tr>
            </tbody>
        </table>
        <div class="foot">Computer-generated statutory report · {{ now()->format('d M Y, H:i') }}. Indicative figures — verify against your registered statutory rates before filing.</div>
    </div>
</body>
</html>
