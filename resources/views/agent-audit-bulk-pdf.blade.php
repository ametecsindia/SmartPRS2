{{-- rev173b — BULK agent audit: summary page + one full report per agent,
     page-break separated. $agents = array of per-agent data arrays from
     ComplianceController::agentAuditData(); chrome colors follow the first
     agent's brand. --}}
@php
    $color = $brand['color'] ?? '#ea580c';
    $brandName = $brand['display_name'] ?? ($company->name ?? 'Company');
    $brandLogo = $brand['logo_file'] ?? ($brand['logo'] ?? '');
    $sumOk = 0; $sumWarn = 0; $sumBad = 0;
    foreach ($agents as $a) {
        if ($a['fail'] > 0) { $sumBad++; }
        elseif ($a['warn'] > 0) { $sumWarn++; }
        else { $sumOk++; }
    }
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { margin: 0; color: #1f2937; font-size: 11px; }
    .head { padding: 0 0 10px; margin-bottom: 4px; }
    .head td { vertical-align: top; }
    .co { font-size: 19px; font-weight: bold; color: #111827; }
    .co-sub { font-size: 9.5px; color: #6b7280; line-height: 1.5; }
    .rpt { text-align: right; font-size: 9.5px; color: #6b7280; line-height: 1.6; }
    .rpt b { color: #111827; }
    .band { color: #fff; padding: 8px 12px; font-size: 14px; font-weight: bold; margin: 8px 0 0; }
    .band .v { float: right; background: #fff; font-size: 10px; padding: 2px 10px; border-radius: 10px; }
    .meta { width: 100%; border-collapse: collapse; margin: 10px 0 6px; }
    .meta td { border: 1px solid #e5e7eb; padding: 5px 8px; width: 25%; }
    .meta .k { font-size: 8px; color: #9ca3af; text-transform: uppercase; letter-spacing: .4px; }
    .meta .val { font-size: 11px; font-weight: bold; }
    .score { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
    .score td { text-align: center; padding: 7px; border: 1px solid #e5e7eb; background: #f9fafb; width: 25%; }
    .score .big { font-size: 20px; font-weight: bold; }
    .score .lbl { font-size: 8px; text-transform: uppercase; color: #9ca3af; letter-spacing: .4px; }
    h2 { font-size: 12px; color: #111827; padding-left: 8px; margin: 14px 0 4px; }
    table.p { width: 100%; border-collapse: collapse; }
    table.p th { background: #f9fafb; border: 1px solid #e5e7eb; padding: 5px 8px; text-align: left; font-size: 8px; text-transform: uppercase; color: #9ca3af; letter-spacing: .3px; }
    table.p td { border: 1px solid #e5e7eb; padding: 5px 8px; font-size: 10.5px; }
    .pill { font-size: 9px; font-weight: bold; padding: 1px 8px; border-radius: 9px; }
    .foot { margin-top: 18px; padding-top: 8px; font-size: 8.5px; color: #6b7280; line-height: 1.6; }
    .sign td { padding-top: 28px; font-size: 9px; color: #6b7280; text-align: center; }
    .sign .l { border-top: 1px solid #111827; }
    .pgbrk { page-break-before: always; }
</style>
</head>
<body>

    {{-- ======= SUMMARY / COVER PAGE ======= --}}
    <table class="head" width="100%" style="border-bottom:3px solid {{ $color }}"><tr>
        <td width="62%">
            @if(!empty($brandLogo))
                <img src="{{ $brandLogo }}" style="max-height:38px;max-width:170px;object-fit:contain;margin-bottom:4px;"><br>
            @endif
            <span class="co">{{ $brandName }}</span>@if(!empty($brand['tagline'])) <span class="co" style="font-weight:normal;font-style:italic;font-size:10px;"> &middot; {{ $brand['tagline'] }}</span> @endif
            <div class="co-sub">Recovery Agent Compliance — Bulk Audit File</div>
        </td>
        <td width="38%" class="rpt">
            <b>Bulk Compliance Audit</b><br>
            Agents covered: <b>{{ $count }}</b><br>
            Generated: <b>{{ $generatedAt }}</b>
        </td>
    </tr></table>

    <div class="band" style="background:{{ $color }}">Audit Summary — {{ $count }} agent(s)</div>

    <table class="score"><tr>
        <td><div class="big" style="color:#15803d">{{ $sumOk }}</div><div class="lbl">Compliant</div></td>
        <td><div class="big" style="color:#b45309">{{ $sumWarn }}</div><div class="lbl">With open items</div></td>
        <td><div class="big" style="color:#b91c1c">{{ $sumBad }}</div><div class="lbl">Non-compliant</div></td>
        <td><div class="big" style="color:{{ $color }}">{{ $count }}</div><div class="lbl">Total agents</div></td>
    </tr></table>

    <table class="p">
        <tr><th width="4%">#</th><th width="26%">Agent</th><th width="12%">Code</th><th width="18%">Department</th><th width="10%">Score</th><th width="30%">Verdict</th></tr>
        @foreach($agents as $i => $a)
            @php
                $vcol = $a['fail'] > 0 ? '#b91c1c' : ($a['warn'] > 0 ? '#b45309' : '#15803d');
                $vbg = $a['fail'] > 0 ? '#fef2f2' : ($a['warn'] > 0 ? '#fffbeb' : '#f0fdf4');
            @endphp
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $a['e']->name }}</td>
                <td>{{ $a['e']->emp_code }}</td>
                <td>{{ $a['e']->department ?? '—' }}</td>
                <td style="color:{{ $vcol }};font-weight:bold">{{ $a['score'] }}%</td>
                <td><span class="pill" style="color:{{ $vcol }};background:{{ $vbg }}">{{ $a['verdict'] }}</span></td>
            </tr>
        @endforeach
    </table>

    <div class="foot" style="border-top:2px solid {{ $color }}">
        Individual agent reports follow, one per section. Generated from live records by the SmartPRS Recovery-Agent Compliance module.
    </div>

    {{-- ======= ONE FULL REPORT PER AGENT ======= --}}
    @foreach($agents as $a)
        <div class="pgbrk"></div>
        @include('agent-audit-body', $a)
    @endforeach

</body>
</html>
