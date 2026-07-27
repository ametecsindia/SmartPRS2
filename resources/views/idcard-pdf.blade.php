<?php
    $color = $brand['color'] ?? '#f97316';
    $brandName = $brand['display_name'] ?? ($company ?? 'SmartPRS');
    $brandLogo = $brand['logo_file'] ?? ($brand['logo'] ?? '');   // rev 131: company logo
    $initials = collect(explode(' ', (string) $e->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 0; }
        body { margin: 0; }
        .card { width: 320px; margin: 30px auto; border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden; }
        .top { background: {{ $color }}; color: #fff; padding: 14px 16px; text-align: center; }
        .top .co { font-size: 15px; font-weight: bold; }
        .top .sub { font-size: 10px; opacity: .9; letter-spacing: 2px; text-transform: uppercase; }
        .photo { text-align: center; margin: 16px 0 8px; }
        .photo .ph { display: inline-block; width: 86px; height: 86px; border-radius: 50%; background: {{ $color }}22; color: {{ $color }}; text-align: center; line-height: 86px; font-size: 30px; font-weight: bold; }
        .name { text-align: center; font-size: 17px; font-weight: bold; color: #111827; }
        .desg { text-align: center; color: #6b7280; font-size: 12px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; padding: 0 16px; }
        .rows { padding: 0 18px 16px; }
        .rows td { padding: 4px 0; font-size: 11px; }
        .rows .k { color: #9ca3af; width: 70px; }
        .rows .v { color: #1f2937; font-weight: 600; }
        .bar { background: {{ $color }}; height: 8px; }
    </style>
</head>
<body>
<div class="card">
    <div class="top">
        @if(!empty($brandLogo))
            <img src="{{ $brandLogo }}" style="max-height:34px;max-width:150px;object-fit:contain;margin-bottom:5px;">
        @endif
        <div class="co">{{ $brandName }}</div>
        @if(!empty($brand['tagline'])) <div class="sub" style="text-transform:none;letter-spacing:0;font-style:italic;">{{ $brand['tagline'] }}</div> @endif
        <div class="sub">Employee ID Card</div>
    </div>
    <div class="photo">
        @if(!empty($photo))
            <img src="{{ $photo }}" style="width:86px;height:86px;border-radius:50%;object-fit:cover;border:2px solid {{ $color }}33">
        @else
            <span class="ph">{{ strtoupper($initials ?: 'EMP') }}</span>
        @endif
    </div>
    <div class="name">{{ $e->name }}</div>
    <div class="desg">{{ $designation ?: 'Employee' }}@if(!empty($department)) · {{ $department }}@endif</div>
    <div class="rows">
        <table>
            <tr><td class="k">Emp Code</td><td class="v">{{ $e->emp_code }}</td></tr>
            <tr><td class="k">Designation</td><td class="v">{{ $designation ?: '—' }}</td></tr>
            <tr><td class="k">Department</td><td class="v">{{ $department ?: '—' }}</td></tr>
            <tr><td class="k">Company</td><td class="v">{{ $company ?: '—' }}</td></tr>
            <tr><td class="k">Date of Joining</td><td class="v">{{ $e->doj ? \Illuminate\Support\Carbon::parse($e->doj)->format('d M Y') : '—' }}</td></tr>
            <tr><td class="k">Mobile</td><td class="v">{{ $e->mobile ?: '—' }}</td></tr>
        </table>
    </div>
    <table style="width:100%;padding:0 18px 8px">
        <tr>
            <td style="font-size:9px;color:#9ca3af;text-align:center;width:50%">
                <div style="border-top:1px solid #cbd5e1;margin:18px 8px 2px"></div>Employee Signature
            </td>
            <td style="font-size:9px;color:#9ca3af;text-align:center;width:50%">
                <div style="border-top:1px solid #cbd5e1;margin:18px 8px 2px"></div>Authorised Signatory
            </td>
        </tr>
    </table>
    <div class="bar"></div>
</div>
</body>
</html>
