<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* rev163 — A4 payslip. P1: A4 + identity + employer CTC memo + leave + ID.
           P2: Fixed/Variable/Reimbursement grouping. P3: financial-year-to-date columns.
           Statutory maths unchanged; grouping/YTD are presentation-only. */
        @page { margin: 12mm 12mm 12mm 12mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #0f172a; font-size: 10px; line-height: 1.4; }
        .head { background: #0c1929; color: #fff; padding: 12px 16px; border-radius: 6px; }
        .head td { vertical-align: middle; }
        .logo { width: 34px; height: 34px; border-radius: 7px; color: #fff;
                text-align: center; font-size: 17px; font-weight: bold; line-height: 34px; }
        .brand { font-size: 16px; font-weight: bold; }
        .cmuted { color: #9fb0c4; font-size: 9px; line-height: 1.4; }
        .doc-title { font-size: 13px; font-weight: bold; letter-spacing: .5px; }

        table { border-collapse: collapse; }
        .meta { width: 100%; margin-top: 12px; }
        .meta td { width: 33.33%; padding: 4px 8px 4px 0; vertical-align: top; }
        .label { color: #64748b; text-transform: uppercase; font-size: 7.5px; letter-spacing: .4px; }
        .val { font-size: 10px; }

        h2 { font-size: 11px; margin: 12px 0 4px; color: #0c1929; }
        .grid { width: 100%; border: 1px solid #e2e8f0; }
        .grid th { background: #f1f5f9; text-align: left; padding: 4px 7px; font-size: 8px;
                   text-transform: uppercase; color: #475569; border-bottom: 1px solid #e2e8f0; }
        .grid td { padding: 3px 7px; border-bottom: 1px solid #f1f5f9; font-size: 9.5px; }
        .amt { text-align: right; white-space: nowrap; }
        .ytd { color: #94a3b8; }
        .grp td { background: #f8fafc; color: #475569; font-size: 8px; text-transform: uppercase;
                  letter-spacing: .3px; font-weight: bold; padding: 4px 7px; }
        .subt td { font-weight: bold; border-top: 1px dashed #cbd5e1; }
        .tot td { font-weight: bold; border-top: 1.5px solid #0c1929; background: #f8fafc; }

        .memo { width: 100%; margin-top: 8px; border: 1px solid #e2e8f0; border-radius: 6px; background: #f8fafc; }
        .memo td { padding: 5px 10px; font-size: 9px; }
        .memo .mval { font-weight: bold; font-size: 10.5px; }

        .net { margin-top: 10px; background: #0c1929; color: #fff; padding: 10px 16px; border-radius: 6px; }
        .net .big { font-size: 18px; font-weight: bold; }
        .words { color: #cbd5e1; font-size: 8.5px; }

        .leave { margin-top: 7px; font-size: 9px; color: #334155; }
        .note-wrap { margin-top: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;
                     padding: 7px 10px; font-size: 8.4px; line-height: 1.45; color: #334155; }
        .foot { margin-top: 8px; color: #94a3b8; font-size: 8px; line-height: 1.4; }
    </style>
</head>
<body>
    @php
        $c = $company ?? null;
        $m = fn ($v) => '₹'.number_format((float) $v, 2);
        $line1 = array_filter([
            trim((string) ($c->address ?? '')),
            !empty($c->gstin ?? null) ? 'GSTIN: '.$c->gstin : null,
            !empty($c->pan ?? null) ? 'PAN: '.$c->pan : null,
        ]);
        $line2 = array_filter([
            !empty($c->phone ?? null) ? 'Ph: '.$c->phone : null,
            !empty($c->email ?? null) ? $c->email : null,
            !empty($c->website ?? null) ? $c->website : null,
        ]);
        $esiStat = (($e->esi_applicable ?? '') === 'yes') ? 'Applicable'
                   : ((($e->esi_applicable ?? '') === 'auto') ? 'Auto' : '—');
        // UAN / PAN can arrive as a number (Excel import) → avoid 1.002E+11 style output.
        $uanDisp = (($e->uan ?? '') !== '') ? (is_numeric($e->uan) ? number_format((float) $e->uan, 0, '', '') : (string) $e->uan) : '—';
        $emp = $emp ?? ['designation' => '—', 'department' => '—', 'branch' => '—', 'doj' => '—', 'type' => ucfirst((string) ($e->type ?? ''))];
        $employer = $employer ?? ['pf' => 0, 'esi' => 0, 'edli' => 0, 'gratuity' => 0, 'ctc' => 0];
        $g = $grouped['groups'] ?? ['fixed' => [], 'variable' => [], 'reimbursement' => []];
        $sub = $grouped['sub'] ?? ['fixed' => 0, 'variable' => 0, 'reimbursement' => 0];
        $grossAB = ($sub['fixed'] ?? 0) + ($sub['variable'] ?? 0);
        $reimbC = $sub['reimbursement'] ?? 0;
        $dedLines = $dedLines ?? [];
        $ytd = $ytd ?? ['ded_total' => 0, 'net' => 0];
        // rev179 — YTD column toggle (Settings → Payslip Policy). Default ON.
        $sy = $showYtd ?? true;
    @endphp

    <table class="head" style="width:100%;">
        <tr>
            <td style="width:44px;">
                @php $logoSrc = $brand['logo_file'] ?? ($brand['logo'] ?? ''); @endphp
                @if (!empty($logoSrc))
                    <img src="{{ $logoSrc }}" style="max-height:36px;max-width:120px;object-fit:contain;">
                @else
                    <div class="logo" style="background: {{ $brand['color'] ?? '#f97316' }};">{{ strtoupper(substr($brand['display_name'] ?? ($c->name ?? 'S'), 0, 1)) }}</div>
                @endif
            </td>
            <td>
                <div class="brand">{{ $brand['display_name'] ?? ($c->name ?? 'SmartPRS') }}</div>
                @if(!empty($brand['tagline'])) <div class="cmuted" style="font-style:italic;">{{ $brand['tagline'] }}</div> @endif
                @if (count($line1)) <div class="cmuted">{{ implode('  ·  ', $line1) }}</div> @endif
                @if (count($line2)) <div class="cmuted">{{ implode('  ·  ', $line2) }}</div> @endif
            </td>
            <td style="text-align:right;">
                <div class="doc-title">PAYSLIP</div>
                <div class="cmuted">{{ $monthLabel }}</div>
                <div class="cmuted">ID: {{ $payslipId ?? '—' }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td><div class="label">Employee</div><div class="val">{{ $e->name }}</div></td>
            <td><div class="label">Employee Code</div><div class="val">{{ $e->emp_code }}</div></td>
            <td><div class="label">Designation</div><div class="val">{{ $emp['designation'] }}</div></td>
        </tr>
        <tr>
            <td><div class="label">Department</div><div class="val">{{ $emp['department'] }}</div></td>
            <td><div class="label">Branch / Location</div><div class="val">{{ $emp['branch'] }}</div></td>
            <td><div class="label">Date of Joining</div><div class="val">{{ $emp['doj'] }}</div></td>
        </tr>
        <tr>
            <td><div class="label">PAN</div><div class="val">{{ $e->pan ?? '—' }}</div></td>
            <td><div class="label">PF No. (UAN)</div><div class="val">{{ $uanDisp }}</div></td>
            <td><div class="label">ESI</div><div class="val">{{ $esiStat }}</div></td>
        </tr>
        <tr>
            <td><div class="label">Bank A/C</div><div class="val">{{ $e->bank_acc ?? '—' }} ({{ $e->ifsc ?? '—' }})</div></td>
            <td><div class="label">Pay Period</div><div class="val">{{ $monthLabel }}</div>@if(!empty($periodLabel))<div style="font-size:8.5px;color:#555;margin-top:1px">{{ $periodLabel }}</div>@endif</td>
            <td><div class="label">Paid Days / LOP</div><div class="val">{{ $paidDays ?? '—' }} / {{ $lopDays ?? 0 }}</div></td>
        </tr>
        <tr>
            <td><div class="label">Employee Type</div><div class="val">{{ $emp['type'] ?: '—' }}</div></td>
            <td><div class="label">Annual CTC</div><div class="val">{{ $m($e->ctc) }}</div></td>
            <td><div class="label">Pay Date</div><div class="val">{{ isset($payDate) ? \Illuminate\Support\Carbon::parse($payDate)->format('d M Y') : \Illuminate\Support\Carbon::parse($month.'-01')->endOfMonth()->format('d M Y') }}</div></td>
        </tr>
    </table>

    <table style="width:100%;">
        <tr>
            <td style="width:52%;vertical-align:top;padding-right:6px;">
                <h2>Earnings</h2>
                <table class="grid" style="width:100%;">
                    <tr><th>Component</th><th class="amt">Month</th>@if ($sy)<th class="amt">YTD</th>@endif</tr>

                    @if (count($g['fixed']))
                        <tr class="grp"><td colspan="{{ $sy ? 3 : 2 }}">A &middot; Fixed (guaranteed)</td></tr>
                        @foreach ($g['fixed'] as $ln)
                            <tr><td>{{ $ln['name'] }}</td><td class="amt">{{ $m($ln['amt']) }}</td>@if ($sy)<td class="amt ytd">{{ $ln['ytd'] ? $m($ln['ytd']) : '—' }}</td>@endif</tr>
                        @endforeach
                    @endif
                    @if (count($g['variable']))
                        <tr class="grp"><td colspan="{{ $sy ? 3 : 2 }}">B &middot; Variable (performance)</td></tr>
                        @foreach ($g['variable'] as $ln)
                            <tr><td>{{ $ln['name'] }}</td><td class="amt">{{ $m($ln['amt']) }}</td>@if ($sy)<td class="amt ytd">{{ $ln['ytd'] ? $m($ln['ytd']) : '—' }}</td>@endif</tr>
                        @endforeach
                    @endif
                    <tr class="tot"><td>Gross Earnings (A + B)</td><td class="amt">{{ $m($grossAB) }}</td>@if ($sy)<td class="amt"></td>@endif</tr>

                    @if (count($g['reimbursement']))
                        <tr class="grp"><td colspan="{{ $sy ? 3 : 2 }}">C &middot; Reimbursements (non-taxable, paid on top)</td></tr>
                        @foreach ($g['reimbursement'] as $ln)
                            <tr><td>{{ $ln['name'] }}</td><td class="amt">{{ $m($ln['amt']) }}</td>@if ($sy)<td class="amt ytd">{{ $ln['ytd'] ? $m($ln['ytd']) : '—' }}</td>@endif</tr>
                        @endforeach
                        <tr class="subt"><td>Reimbursements (C)</td><td class="amt">{{ $m($reimbC) }}</td>@if ($sy)<td class="amt"></td>@endif</tr>
                    @endif
                </table>
            </td>
            <td style="width:48%;vertical-align:top;padding-left:6px;">
                <h2>Deductions</h2>
                <table class="grid" style="width:100%;">
                    <tr><th>Component</th><th class="amt">Month</th>@if ($sy)<th class="amt">YTD</th>@endif</tr>
                    @foreach ($dedLines as $ln)
                        <tr><td>{{ $ln['name'] }}</td><td class="amt">{{ $m($ln['amt']) }}</td>@if ($sy)<td class="amt ytd">{{ $ln['ytd'] ? $m($ln['ytd']) : '—' }}</td>@endif</tr>
                    @endforeach
                    <tr class="tot"><td>Total Deductions</td><td class="amt">{{ $m($s['total_ded']) }}</td>@if ($sy)<td class="amt ytd">{{ ($ytd['ded_total'] ?? 0) ? $m($ytd['ded_total']) : '' }}</td>@endif</tr>
                </table>

                <table class="memo" style="width:100%;">
                    <tr><td colspan="2"><div class="label">Employer contribution — cost to company (not deducted)</div></td></tr>
                    <tr>
                        <td style="width:50%;"><div class="label">Employer PF</div><div class="mval">{{ $m($employer['pf']) }}</div></td>
                        <td style="width:50%;"><div class="label">Employer ESI</div><div class="mval">{{ $m($employer['esi']) }}</div></td>
                    </tr>
                    <tr>
                        <td><div class="label">EDLI + Gratuity accrual</div><div class="mval">{{ $m($employer['edli'] + $employer['gratuity']) }}</div></td>
                        <td><div class="label">Monthly CTC (indicative)</div><div class="mval">{{ $m($employer['ctc']) }}</div></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (!empty($leaveStr))
        <div class="leave"><strong>Leave taken (this year):</strong> {{ $leaveStr }}</div>
    @endif

    <table class="net" style="width:100%;">
        <tr>
            <td>
                Net Pay ({{ $monthLabel }})
                <div style="color:#fff;font-size:11px;font-weight:bold;margin-top:2px">{{ $netWords ?? '' }}</div>
                <div class="words">Net = Gross earnings (A+B){{ $reimbC > 0 ? ' + reimbursements (C) '.$m($reimbC) : '' }} &minus; total deductions {{ $m($s['total_ded']) }}</div>
            </td>
            <td style="text-align:right;" class="big">{{ $m($s['net']) }}</td>
        </tr>
    </table>

    @if (!empty($note))
        <div class="note-wrap"><strong>How this was calculated:</strong> {{ $note }}</div>
    @endif

    <div class="foot">
        Computer-generated payslip — no signature required. Statutory deductions (PF/ESI/PT/TDS) computed per configured rates in Settings; reimbursements are non-taxable and excluded from the PF/ESI wage base.
        Verify authenticity using Payslip ID {{ $payslipId ?? '' }}. Generated {{ now()->format('d M Y, H:i') }}.
    </div>
</body>
</html>
