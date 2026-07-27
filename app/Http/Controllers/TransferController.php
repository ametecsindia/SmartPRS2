<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transfer ORDER letter + employee acknowledgement (rev 77b, Ejaz 4 Jun 2026).
 *
 * The transfer REQUEST itself rides the generic approval engine
 * (RequestController + `transfers` table). This controller adds the paperwork:
 *
 *   - On APPROVAL, sendOrder() emails the employee the formal Transfer Order
 *     PDF (branded letterhead) with an "Acknowledge transfer" link.
 *   - GET /app/transfers/{id}/letter — the same PDF, downloadable from the
 *     register anytime (managers, or the transferred employee themself).
 *   - GET /transfer/accept/{token} — PUBLIC acknowledgement page (token is
 *     the secret). Acceptance is an acknowledgement ONLY: the transfer applies
 *     on its effective date regardless; the click is recorded with date/time
 *     on the register for the employee's file.
 */
class TransferController extends Controller
{
    /** Tenant-scoped fetch + access: managers, or the employee being moved. */
    private function row(Request $request, int $id): ?object
    {
        $tid = $request->user()->tenant_id;
        $t = DB::table('transfers')->where('id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $t) {
            return null;
        }
        if ($request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager'])) {
            return $t;
        }
        $emp = DB::table('employees')->where('id', $t->employee_id)->first();
        $u = $request->user();
        $mine = $emp && (
            (! empty($u->employee_id) && (int) $u->employee_id === (int) $emp->id)
            || (! empty($emp->email) && strtolower($emp->email) === strtolower($u->email))
        );

        return $mine ? $t : null;
    }

    /** Build the branded Transfer Order PDF. Returns ['pdf','file'] or null. */
    public static function buildLetter(object $t): ?array
    {
        $emp = DB::table('employees')->where('id', $t->employee_id)->first();
        if (! $emp) {
            return null;
        }
        $NL = "\n";
        $isCompanyMove = stripos((string) $t->type, 'compan') !== false;
        $isDeptMove = ! $isCompanyMove && stripos((string) $t->type, 'depart') !== false;

        // From/to: prefer the applied snapshots; before apply, read live values.
        $fromCompany = $t->from_company ?: ($emp->company_id ? (string) DB::table('companies')->where('id', $emp->company_id)->value('name') : '');
        $fromBranch = $t->from_branch ?: (string) ($emp->branch ?? '');
        $toCompany = $isCompanyMove ? ($t->to_company ?: '') : $fromCompany;
        $toBranch = $t->to_branch ?: ($isCompanyMove || $isDeptMove ? '' : $fromBranch);
        $toDept = (string) ($t->to_department ?? '');

        $effective = ! empty($t->effective_date) ? Carbon::parse($t->effective_date)->format('d M Y') : 'the date of this order';
        $fromTxt = trim($fromCompany.($fromBranch !== '' ? ', '.$fromBranch.' branch' : ''));
        $toTxt = trim($toCompany.($toBranch !== '' ? ', '.$toBranch.' branch' : '').($toDept !== '' ? ', '.$toDept.' department' : ''));

        $body = 'Dear '.$emp->name.' ('.($emp->emp_code ?: '—').'),'.$NL.$NL
            .'This is to formally inform you that, further to management approval, you are transferred '
            .($isCompanyMove ? 'within the group ' : '')
            .'from '.($fromTxt !== '' ? $fromTxt : 'your current posting').' to '.$toTxt
            .', with effect from '.$effective.'.'.$NL.$NL
            .(! empty($t->reason) ? 'Reason / purpose: '.$t->reason.$NL.$NL : '')
            .'Your employee code ('.($emp->emp_code ?: '—').') and your terms of employment remain unchanged. '
            .($isCompanyMove
                ? 'On joining your new company you will complete the joining formalities, after which your role, reporting manager and team will be assigned. '
                : 'Your reporting lines and payroll will follow your new posting from the effective date. ')
            .$NL.$NL
            .'Please acknowledge receipt of this order using the link sent to your registered email. '
            .'We wish you continued success in your new role.';

        try {
            $brand = ConfigController::brandFor($t->tenant_id, $emp->company_id);
        } catch (\Throwable $e) {
            $brand = [];
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('letter-pdf', [
            'title' => 'Transfer Order',
            'body' => $body,
            'brand' => $brand,
            'company' => $fromCompany ?: ($toCompany ?: 'SmartPRS'),
            'companyAddress' => '',
            'date' => now()->format('d M Y'),
            'empName' => $emp->name,
            'empCode' => $emp->emp_code,
        ]);

        return ['pdf' => $pdf, 'file' => 'Transfer-Order-'.($emp->emp_code ?: $emp->id).'.pdf'];
    }

    /**
     * Email the Transfer Order (PDF attached) + acknowledgement link to the
     * employee. Called from RequestController::decide when a transfer is
     * APPROVED. Fail-soft: the approval/apply stand even if the email fails.
     */
    public static function sendOrder(int $id): void
    {
        $t = DB::table('transfers')->where('id', $id)->first();
        if (! $t) {
            return;
        }
        $emp = DB::table('employees')->where('id', $t->employee_id)->first();
        if (! $emp || empty($emp->email)) {
            return;
        }

        // Acknowledgement token (the email link's secret). Null-safe: a table
        // created pre-77b may lack the column until ensureAuditCols heals it.
        $token = $t->accept_token ?? null;
        if (! $token) {
            $token = Str::random(48);
            DB::table('transfers')->where('id', $id)->update(ApprovalService::safeRow('transfers', [
                'accept_token' => $token, 'updated_at' => now(),
            ]));
        }

        $b = self::buildLetter($t);
        $effective = ! empty($t->effective_date) ? Carbon::parse($t->effective_date)->format('d M Y') : 'immediately';
        $isCompanyMove = stripos((string) $t->type, 'compan') !== false;
        $isDeptMove = ! $isCompanyMove && stripos((string) $t->type, 'depart') !== false;

        \App\Services\MailService::queue([
            'tenant_id' => $t->tenant_id,
            'company_id' => $emp->company_id,
            'to' => $emp->email,
            'to_name' => $emp->name,
            'subject' => 'Transfer Order — effective '.$effective,
            'heading' => 'Your transfer has been approved',
            'intro' => 'Please find your formal Transfer Order attached. Kindly acknowledge it using the button below — your acknowledgement is recorded on your file.',
            'lines' => array_filter([
                'Transfer type' => $isCompanyMove ? 'Company transfer (within the group)' : ($isDeptMove ? 'Department transfer' : 'Branch transfer'),
                'To' => trim(($t->to_company ?: '').(! empty($t->to_branch) ? ' / '.$t->to_branch : '').(! empty($t->to_department) ? ' / '.$t->to_department.' dept.' : '')) ?: null,
                'Effective from' => $effective,
            ]),
            'body' => 'Your employee code and terms of employment remain unchanged.',
            'cta_label' => 'Acknowledge transfer',
            'cta_url' => url('/transfer/accept/'.$token),
            'kind' => 'transfer.order',
            'attach_b64' => $b ? base64_encode($b['pdf']->output()) : null,
            'attach_name' => $b ? $b['file'] : null,
        ]);

        // Timeline stamp for the process tracker (rev 78).
        DB::table('transfers')->where('id', $id)->update(ApprovalService::safeRow('transfers', [
            'order_sent_at' => now(), 'updated_at' => now(),
        ]));
    }

    /** Stream the Transfer Order PDF (register download). */
    public function letter(Request $request, int $id)
    {
        $t = $this->row($request, $id);
        if (! $t) {
            return response('Transfer not found.', 404)->header('Content-Type', 'text/plain');
        }
        $b = self::buildLetter($t);
        if (! $b) {
            return response('Employee not found for this transfer.', 404)->header('Content-Type', 'text/plain');
        }

        return $b['pdf']->stream($b['file']);
    }

    /** PUBLIC acknowledgement (token-secured). Records accepted_at once. */
    public function accept(string $token)
    {
        $page = function (string $h, string $p, string $color) {
            return response('<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SmartPRS — Transfer</title></head>'
                .'<body style="font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
                .'<div style="background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.08);padding:36px 40px;max-width:480px;text-align:center">'
                .'<div style="width:56px;height:56px;border-radius:50%;background:'.$color.'1a;color:'.$color.';font-size:24px;line-height:56px;margin:0 auto 14px">&#10003;</div>'
                .'<h2 style="margin:0 0 8px;color:#0f172a">'.$h.'</h2>'
                .'<p style="color:#475569;line-height:1.6;margin:0">'.$p.'</p>'
                .'</div></body></html>')->header('Content-Type', 'text/html; charset=UTF-8');
        };

        try {
            $t = DB::table('transfers')->where('accept_token', $token)->first();
            if (! $t) {
                return $page('Link not valid', 'This acknowledgement link is not valid. Please contact your HR department.', '#dc2626');
            }
            if (! empty($t->accepted_at)) {
                return $page('Already acknowledged', 'You acknowledged this transfer on '.Carbon::parse($t->accepted_at)->format('d M Y H:i').'. No further action is needed.', '#16a34a');
            }
            DB::table('transfers')->where('id', $t->id)->update(ApprovalService::safeRow('transfers', [
                'accepted_at' => now(), 'updated_at' => now(),
            ]));

            return $page('Transfer acknowledged', 'Thank you — your acknowledgement has been recorded on your file'
                .(! empty($t->effective_date) ? '. The transfer takes effect on '.Carbon::parse($t->effective_date)->format('d M Y').'.' : '.'), '#16a34a');
        } catch (\Throwable $e) {
            return $page('Something went wrong', 'Please try the link again, or contact your HR department.', '#dc2626');
        }
    }
}
