{{-- rev173b — ONE agent's audit-report body. Shared by agent-audit-pdf (single)
     and agent-audit-bulk-pdf (many, page-break separated). Expects the full
     per-agent data array from ComplianceController::agentAuditData(). --}}
@php
    $color = $brand['color'] ?? '#ea580c';
    $brandName = $brand['display_name'] ?? ($company->name ?? 'Company');
    $brandLogo = $brand['logo_file'] ?? ($brand['logo'] ?? '');
    $addr = $company->address ?? '';
    $gstin = $company->gstin ?? '';
    $vColors = ['ok' => ['#15803d', '#f0fdf4'], 'warn' => ['#b45309', '#fffbeb'], 'bad' => ['#b91c1c', '#fef2f2']];
    $vLabel = ['ok' => 'Compliant', 'warn' => 'Attention', 'bad' => 'Non-compliant'];
    $verdictColor = $fail > 0 ? '#b91c1c' : ($warn > 0 ? '#b45309' : '#15803d');
@endphp

    <table class="head" width="100%" style="border-bottom:3px solid {{ $color }}"><tr>
        <td width="62%">
            @if(!empty($brandLogo))
                <img src="{{ $brandLogo }}" style="max-height:38px;max-width:170px;object-fit:contain;margin-bottom:4px;"><br>
            @endif
            <span class="co">{{ $brandName }}</span>@if(!empty($brand['tagline'])) <span class="co" style="font-weight:normal;font-style:italic;font-size:10px;"> &middot; {{ $brand['tagline'] }}</span> @endif
            <div class="co-sub">
                @if($addr){{ $addr }}<br>@endif
                @if($gstin)GSTIN: {{ $gstin }}@endif
            </div>
        </td>
        <td width="38%" class="rpt">
            <b>Recovery Agent Compliance File</b><br>
            Report Ref: <b>{{ $ref }}</b><br>
            Generated: <b>{{ $generatedAt }}</b><br>
            Audit period: {{ $auditFrom }} – {{ $auditTo }}<br>
            Tamper-evident hash: <b>{{ $hash }}</b>
        </td>
    </tr></table>

    @if(!empty($isSample))
        <div style="background:#fef3c7;border:1px dashed #b45309;color:#92400e;font-size:9.5px;font-weight:bold;padding:5px 10px;margin:6px 0 0;text-align:center">
            SAMPLE REPORT — illustrative data only. Real reports are generated from live employee records.
        </div>
    @endif
    <div class="band" style="background:{{ $color }}">Recovery Agent — RBI Compliance Audit Report
        <span class="v" style="color:{{ $verdictColor }}">{{ $verdict }}</span>
    </div>

    <table class="meta"><tr>
        <td><div class="k">Agent Name</div><div class="val">{{ $e->name }}</div></td>
        <td><div class="k">Agent Code</div><div class="val">{{ $e->emp_code }}</div></td>
        <td><div class="k">Designation</div><div class="val">{{ $e->designation ?? '—' }}</div></td>
        <td><div class="k">Status</div><div class="val">{{ ucfirst($e->status ?? 'active') }}</div></td>
    </tr><tr>
        <td><div class="k">Date of Joining</div><div class="val">{{ $e->doj ?? '—' }}</div></td>
        <td><div class="k">Mobile</div><div class="val">{{ $e->mobile ?? '—' }}</div></td>
        <td><div class="k">Department</div><div class="val">{{ $e->department ?? '—' }}</div></td>
        <td><div class="k">Compliance Score</div><div class="val" style="color:{{ $verdictColor }}">{{ $score }}%</div></td>
    </tr></table>

    <table class="score"><tr>
        <td><div class="big" style="color:#15803d">{{ $pass }}</div><div class="lbl">Compliant</div></td>
        <td><div class="big" style="color:#b45309">{{ $warn }}</div><div class="lbl">Needs attention</div></td>
        <td><div class="big" style="color:#b91c1c">{{ $fail }}</div><div class="lbl">Non-compliant</div></td>
        <td><div class="big" style="color:{{ $color }}">{{ $score }}%</div><div class="lbl">Overall score</div></td>
    </tr></table>

    @foreach($groups as $gname => $rows)
        <h2 style="border-left:4px solid {{ $color }}">{{ $gname }}</h2>
        <table class="p">
            <tr><th width="34%">Parameter</th><th width="24%">On Record</th><th width="15%">Status</th><th>Evidence</th></tr>
            @foreach($rows as $r)
                @php $vc = $vColors[$r['state']]; @endphp
                <tr>
                    <td>{{ $r['param'] }}</td>
                    <td>{{ $r['value'] }}</td>
                    <td><span class="pill" style="color:{{ $vc[0] }};background:{{ $vc[1] }}">{{ $vLabel[$r['state']] }}</span></td>
                    <td style="color:#6b7280">{{ $r['evidence'] ?: '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endforeach

    <table class="sign" width="100%"><tr>
        <td width="33%"><div class="l">Prepared by (Compliance Officer)</div></td>
        <td width="34%"><div class="l">Reviewed by (Operations Head)</div></td>
        <td width="33%"><div class="l">For {{ $brandName }}</div></td>
    </tr></table>

    <div class="foot" style="border-top:2px solid {{ $color }}">
        Generated from live records by the SmartPRS Recovery-Agent Compliance module. This report is a tamper-evident record (hash-chained audit log).
        Regulatory basis: RBI Guidelines on Recovery Agents, RBI Outsourcing Directions 2025, and IIBF DRA certification norms — indicative; verify against the latest RBI directions applicable to the engaging bank/NBFC before filing.
    </div>
