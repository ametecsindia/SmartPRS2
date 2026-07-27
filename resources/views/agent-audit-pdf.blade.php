{{-- rev173b — single-agent wrapper; the report body lives in agent-audit-body
     (shared with the bulk report). All per-agent data comes from
     ComplianceController::agentAuditData(). --}}
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
</style>
</head>
<body>
@include('agent-audit-body')
</body>
</html>
