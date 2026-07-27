<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Off-roll / commission-agent KYC (rev 65). Photo + document uploads, bank details,
 * and contact verification for the `offroll_agents` records. Email is verified via a
 * public tokenised link; mobile is a manual HR toggle (SMS/OTP is parked). Files are
 * stored on the public disk and served back through an auth-guarded route.
 */
class OffrollAgentController extends Controller
{
    /** Document slot → column on offroll_agents. */
    private const SLOTS = [
        'photo' => 'photo_path', 'id_proof' => 'doc_id_proof', 'pan' => 'doc_pan',
        'address' => 'doc_address', 'dra' => 'doc_dra', 'pcc' => 'doc_pcc', 'agreement' => 'doc_agreement',
    ];

    private function guard(Request $request)
    {
        return ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager']);
    }

    private function ensure(): void
    {
        if (! Schema::hasTable('offroll_agents')) {
            Schema::create('offroll_agents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('name')->nullable();
                $t->string('company_name')->nullable();
                $t->string('vendor')->nullable();
                $t->string('mobile')->nullable();
                $t->string('payout_type')->nullable();
                $t->decimal('rate', 14, 2)->nullable();
                $t->string('dra')->nullable();
                $t->string('pcc')->nullable();
                $t->string('status')->nullable();
                $t->timestamps();
            });
        }
        $cols = [
            'email' => 'string', 'photo_path' => 'string', 'doc_id_proof' => 'string', 'doc_pan' => 'string',
            'doc_address' => 'string', 'doc_dra' => 'string', 'doc_pcc' => 'string', 'doc_agreement' => 'string',
            'bank_details' => 'string', 'upi' => 'string',
            'email_verified_at' => 'datetime', 'email_verify_token' => 'string', 'mobile_verified_at' => 'datetime',
            'earn_token' => 'string',   // rev 80: public live-earnings link secret
        ];
        foreach ($cols as $c => $ty) {
            if (Schema::hasColumn('offroll_agents', $c)) {
                continue;
            }
            Schema::table('offroll_agents', function (Blueprint $t) use ($c, $ty) {
                $ty === 'datetime' ? $t->dateTime($c)->nullable() : $t->string($c)->nullable();
            });
        }
    }

    private function agent($tid, int $id)
    {
        return DB::table('offroll_agents')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
    }

    /** rev 80: self-created earnings ledger for off-roll agents. */
    private function ensureEarnings(): void
    {
        if (Schema::hasTable('agent_earnings')) {
            return;
        }
        Schema::create('agent_earnings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('agent_id')->index();
            $t->date('entry_date')->nullable();
            $t->string('kind', 20)->default('visit');        // visit|collection|fixed|adjustment|deduction
            $t->decimal('qty', 10, 2)->nullable();            // visits count
            $t->decimal('base_amount', 14, 2)->nullable();    // amount collected (percent payouts)
            $t->decimal('amount', 14, 2)->default(0);         // computed payout (positive; deduction subtracts)
            $t->string('note', 300)->nullable();
            $t->string('status', 20)->default('pending');     // pending|approved|rejected
            $t->string('created_by')->nullable();
            $t->string('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('remarks', 300)->nullable();
            $t->timestamps();
        });
    }

    /**
     * Auto-compute an entry's payout from the agent's payout config
     * (per_visit: rate × visits; percent: collected × rate%; fixed/adjustment/
     * deduction: manual amount). HR can always override with a manual amount.
     */
    private function computeEarning(object $a, string $kind, float $qty, float $base, ?float $manual): float
    {
        if ($manual !== null && $manual > 0) {
            return round($manual, 2);
        }
        $rate = (float) ($a->rate ?? 0);
        if ($kind === 'visit') {
            return round($rate * max(0, $qty), 2);
        }
        if ($kind === 'collection') {
            return round($base * $rate / 100, 2);
        }
        if ($kind === 'fixed') {
            return round($rate, 2);
        }

        return 0.0;
    }

    /** Month summary + entries for one agent (HR/Admin screen). */
    public function earnings(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $this->ensureEarnings();
            $tid = $request->user()->tenant_id;
            $a = $this->agent($tid, $id);
            if (! $a) {
                return response()->json(['ok' => false, 'error' => 'Agent not found'], 404);
            }

            return response()->json(['ok' => true] + $this->earningsPayload($a));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Shared month-to-date payload (HR screen + the public link). */
    private function earningsPayload(object $a): array
    {
        $month = now()->format('Y-m');
        $rows = DB::table('agent_earnings')->where('agent_id', $a->id)
            ->where('entry_date', '>=', $month.'-01')
            ->orderByDesc('entry_date')->orderByDesc('id')->limit(100)->get();
        $net = 0.0;
        $pendingSum = 0.0;
        $entries = [];
        foreach ($rows as $r) {
            $signed = $r->kind === 'deduction' ? -(float) $r->amount : (float) $r->amount;
            if ($r->status === 'approved') {
                $net += $signed;
            } elseif ($r->status === 'pending') {
                $pendingSum += $signed;
            }
            $entries[] = [
                'id' => $r->id,
                'date' => $r->entry_date ? Carbon::parse($r->entry_date)->format('d M') : '',
                'kind' => $r->kind,
                'sign' => $r->kind === 'deduction' ? '-' : '+',
                'head' => ['visit' => 'Field visits', 'collection' => 'Collection commission', 'fixed' => 'Fixed payout', 'adjustment' => 'Adjustment / bonus', 'deduction' => 'Deduction'][$r->kind] ?? ucfirst($r->kind),
                'detail' => trim(implode(' · ', array_filter([
                    $r->qty ? ((float) $r->qty).' visit(s)' : null,
                    $r->base_amount ? 'on ₹'.number_format((float) $r->base_amount).' collected' : null,
                    $r->note ?: null,
                    $r->status === 'approved' && $r->decided_by ? 'approved by '.$r->decided_by : null,
                ]))),
                'amount' => (float) $r->amount,
                'status' => $r->status,
            ];
        }

        return [
            'agent' => ['id' => $a->id, 'name' => $a->name, 'vendor' => $a->vendor ?? '', 'company' => $a->company_name ?? '',
                'payout_type' => $a->payout_type ?? '', 'rate' => (float) ($a->rate ?? 0), 'hasEmail' => ! empty($a->email)],
            'monthLabel' => now()->format('F Y'),
            'net' => round($net, 2),
            'pending' => round($pendingSum, 2),
            'entries' => $entries,
        ];
    }

    /** HR records an earning entry — auto-computed from the payout config. */
    public function addEarning(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $this->ensureEarnings();
            $tid = $request->user()->tenant_id;
            $a = $this->agent($tid, $id);
            if (! $a) {
                return response()->json(['ok' => false, 'error' => 'Agent not found'], 404);
            }
            $v = $request->validate([
                'kind' => ['required', 'in:visit,collection,fixed,adjustment,deduction'],
                'qty' => ['nullable', 'numeric', 'min:0'],
                'base_amount' => ['nullable', 'numeric', 'min:0'],
                'amount' => ['nullable', 'numeric', 'min:0'],
                'entry_date' => ['nullable', 'date'],
                'note' => ['nullable', 'string', 'max:300'],
            ]);
            $amount = $this->computeEarning($a, $v['kind'], (float) ($v['qty'] ?? 0), (float) ($v['base_amount'] ?? 0), isset($v['amount']) ? (float) $v['amount'] : null);
            if ($amount <= 0) {
                return response()->json(['ok' => false, 'error' => 'Computed amount is zero — check the agent’s rate / the quantity / the amount.'], 422);
            }
            DB::table('agent_earnings')->insert([
                'tenant_id' => $tid, 'agent_id' => $a->id,
                'entry_date' => $v['entry_date'] ?? now()->toDateString(),
                'kind' => $v['kind'], 'qty' => $v['qty'] ?? null, 'base_amount' => $v['base_amount'] ?? null,
                'amount' => $amount, 'note' => $v['note'] ?? null,
                'status' => 'pending', 'created_by' => $request->user()->name,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json(['ok' => true, 'amount' => $amount]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Approve / reject an earning entry. */
    public function decideEarning(Request $request, int $eid)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensureEarnings();
            $tid = $request->user()->tenant_id;
            $r = DB::table('agent_earnings')->where('id', $eid)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $r) {
                return response()->json(['ok' => false, 'error' => 'Entry not found'], 404);
            }
            $v = $request->validate(['action' => ['required', 'in:approve,reject'], 'remarks' => ['nullable', 'string', 'max:300']]);
            // rev181 — DRA gate for OFF-ROLL agents too: the agent master's DRA
            // field is free text, so the gate only reacts to a BLANK field (no
            // certification recorded at all). block = refuse; warn = approve but
            // say so. Managed in Settings -> Statutory Rates -> DRA gate.
            $draWarning = null;
            if ($v['action'] === 'approve') {
                try {
                    $gate = (string) (SettingsController::rates($tid)['dra_gate'] ?? 'warn');
                    $a = $this->agent($tid, (int) $r->agent_id);
                    if ($gate !== 'off' && $a && trim((string) ($a->dra ?? '')) === '') {
                        if ($gate === 'block') {
                            return response()->json(['ok' => false, 'error' => 'DRA gate: no DRA certification is recorded for '.($a->name ?? 'this agent').' — fill the DRA field on the Off-roll Agents screen before approving earnings (or set the gate to Warn in Statutory Rates).'], 422);
                        }
                        $draWarning = 'DRA warning: no DRA certification recorded for '.($a->name ?? 'this agent').' — approved anyway (gate is in Warn mode).';
                    }
                } catch (\Throwable $e) {
                    // the gate must never break earning approvals
                }
            }
            DB::table('agent_earnings')->where('id', $eid)->update([
                'status' => $v['action'] === 'approve' ? 'approved' : 'rejected',
                'decided_by' => $request->user()->name, 'decided_at' => now(),
                'remarks' => $v['remarks'] ?? null, 'updated_at' => now(),
            ]);

            return response()->json(['ok' => true, 'warning' => $draWarning]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Email the agent his PRIVATE live-earnings link (token is the secret). */
    public function sendEarningsLink(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $a = $this->agent($tid, $id);
            if (! $a) {
                return response()->json(['ok' => false, 'error' => 'Agent not found'], 404);
            }
            $token = $a->earn_token ?: Str::random(48);
            if (! $a->earn_token) {
                DB::table('offroll_agents')->where('id', $a->id)->update(['earn_token' => $token, 'updated_at' => now()]);
            }
            $url = url('/agent/earnings/'.$token);
            if (! empty($a->email)) {
                try {
                    \App\Services\MailService::queue([
                        'tenant_id' => $tid,
                        'to' => $a->email, 'to_name' => $a->name,
                        'subject' => 'Your live earnings link — '.($a->company_name ?: 'SmartPRS'),
                        'heading' => 'See your earnings any time',
                        'intro' => 'Open the button below from your phone to see your current month earnings, entry by entry, updated live. Keep this link private — it is your personal page.',
                        'cta_label' => 'View my live earnings',
                        'cta_url' => $url,
                        'kind' => 'agent.earnings.link',
                    ]);
                } catch (\Throwable $e) {
                }
            }

            return response()->json(['ok' => true, 'link' => $url, 'emailed' => ! empty($a->email)]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** PUBLIC: the agent's live-earnings page (token-secured, read-only). */
    public function publicEarnings(string $token)
    {
        try {
            $this->ensure();
            $this->ensureEarnings();
            $a = DB::table('offroll_agents')->where('earn_token', $token)->first();
            if (! $a) {
                return response('<!DOCTYPE html><html><body style="font-family:system-ui;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f1f5f9"><div style="background:#fff;padding:30px 36px;border-radius:14px;color:#475569">This earnings link is not valid. Please contact your agency.</div></body></html>', 404)
                    ->header('Content-Type', 'text/html; charset=UTF-8');
            }
            $p = $this->earningsPayload($a);
            $fmt = fn ($n) => '&#8377;'.number_format((float) $n);
            $rows = '';
            foreach ($p['entries'] as $en) {
                $pend = $en['status'] === 'pending';
                $rej = $en['status'] === 'rejected';
                if ($rej) {
                    continue;   // agent sees only pending + approved
                }
                $col = $en['sign'] === '+' ? '#15803d' : '#b91c1c';
                $rows .= '<div style="display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid #e2e8f0'.($pend ? ';opacity:.55' : '').'">'
                    .'<div><div style="font-weight:700;font-size:14px">'.e($en['head']).($pend ? ' <span style="font-size:10px;background:#fef3c7;color:#92400e;padding:1px 8px;border-radius:99px;font-weight:700;text-transform:uppercase">awaiting approval</span>' : '').'</div>'
                    .'<div style="font-size:12px;color:#64748b">'.e($en['date']).($en['detail'] !== '' ? ' &middot; '.e($en['detail']) : '').'</div></div>'
                    .'<b style="color:'.$col.';white-space:nowrap">'.$en['sign'].' '.$fmt($en['amount']).'</b></div>';
            }
            if ($rows === '') {
                $rows = '<div style="padding:18px 0;color:#94a3b8;text-align:center">No entries yet this month</div>';
            }

            return response('<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My Earnings — SmartPRS</title></head>'
                .'<body style="font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f1f5f9;margin:0;padding:18px">'
                .'<div style="max-width:520px;margin:0 auto">'
                .'<div style="background:linear-gradient(135deg,#0f1d33,#1e3a5f);color:#fff;border-radius:16px;padding:24px;text-align:center;margin-bottom:14px">'
                .'<div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;opacity:.75">'.e($a->name).' &middot; '.e($p['monthLabel']).'</div>'
                .'<div style="font-size:42px;font-weight:800;margin:6px 0">'.$fmt($p['net']).'</div>'
                .'<div style="font-size:13px;opacity:.85">earned &amp; approved this month'
                .($p['pending'] > 0 ? ' &middot; '.$fmt($p['pending']).' awaiting approval' : '').'</div></div>'
                .'<div style="background:#fff;border-radius:16px;padding:18px 20px;box-shadow:0 6px 18px rgba(15,23,42,.06)">'
                .'<div style="font-weight:800;margin-bottom:4px">How you earned it</div>'.$rows.'</div>'
                .'<div style="text-align:center;color:#94a3b8;font-size:11px;margin-top:12px">Updated live by '.e($a->company_name ?: 'your agency').' &middot; powered by SmartPRS</div>'
                .'</div></body></html>')
                ->header('Content-Type', 'text/html; charset=UTF-8');
        } catch (\Throwable $e) {
            return response('Something went wrong. Please try again.', 500)->header('Content-Type', 'text/plain');
        }
    }

    public function profile(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $a = $this->agent($tid, $id);
            if (! $a) {
                return response()->json(['ok' => false, 'error' => 'Agent not found'], 404);
            }
            $docs = [];
            foreach (self::SLOTS as $slot => $col) {
                $docs[$slot] = ! empty($a->$col);
            }

            return response()->json([
                'ok' => true,
                'id' => $a->id, 'name' => $a->name, 'vendor' => $a->vendor ?? '', 'company' => $a->company_name ?? '',
                'mobile' => $a->mobile ?? '', 'email' => $a->email ?? '',
                'bank_details' => $a->bank_details ?? '', 'upi' => $a->upi ?? '',
                'payout_type' => $a->payout_type ?? '', 'rate' => $a->rate ?? null, 'status' => $a->status ?? '',
                'docs' => $docs,
                'photo_url' => ! empty($a->photo_path) ? url('/app/offroll-agent/'.$id.'/file/photo') : null,
                'email_verified' => ! empty($a->email_verified_at),
                'mobile_verified' => ! empty($a->mobile_verified_at),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function saveContact(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $v = $request->validate([
                'email' => ['nullable', 'email', 'max:191'],
                'mobile' => ['nullable', 'string', 'max:30'],
                'bank_details' => ['nullable', 'string', 'max:255'],
                'upi' => ['nullable', 'string', 'max:120'],
            ]);
            $a = $this->agent($tid, $id);
            if (! $a) {
                return response()->json(['ok' => false, 'error' => 'Agent not found'], 404);
            }
            $upd = ['updated_at' => now()];
            foreach (['email', 'mobile', 'bank_details', 'upi'] as $k) {
                $upd[$k] = $v[$k] ?? null;
            }
            // Changing the email invalidates a previous verification.
            if (($a->email ?? null) !== ($v['email'] ?? null)) {
                $upd['email_verified_at'] = null;
                $upd['email_verify_token'] = null;
            }
            DB::table('offroll_agents')->where('id', $id)->update(ApprovalService::safeRow('offroll_agents', $upd));

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function uploadDoc(Request $request, int $id, string $slot)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            if (! isset(self::SLOTS[$slot])) {
                return response()->json(['ok' => false, 'error' => 'Unknown document type'], 422);
            }
            $rules = $slot === 'photo'
                ? ['file' => ['required', 'image', 'max:5120']]
                : ['file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240']];
            $request->validate($rules);
            $a = $this->agent($tid, $id);
            if (! $a) {
                return response()->json(['ok' => false, 'error' => 'Agent not found'], 404);
            }
            $path = $request->file('file')->store('offroll-docs', 'public');
            DB::table('offroll_agents')->where('id', $id)->update([self::SLOTS[$slot] => $path, 'updated_at' => now()]);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Serve an uploaded file (auth-guarded). */
    public function serveDoc(Request $request, int $id, string $slot)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $this->ensure();
        $tid = $request->user()->tenant_id;
        if (! isset(self::SLOTS[$slot])) {
            abort(404);
        }
        $a = $this->agent($tid, $id);
        $col = self::SLOTS[$slot];
        if (! $a || empty($a->$col)) {
            abort(404);
        }
        $full = storage_path('app/public/'.$a->$col);
        if (! is_file($full)) {
            abort(404);
        }
        $mime = function_exists('mime_content_type') ? mime_content_type($full) : 'application/octet-stream';

        return response()->file($full, ['Content-Type' => $mime]);
    }

    public function sendEmailVerify(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $a = $this->agent($tid, $id);
            if (! $a) {
                return response()->json(['ok' => false, 'error' => 'Agent not found'], 404);
            }
            if (empty($a->email)) {
                return response()->json(['ok' => false, 'error' => 'Add the agent\'s email first.'], 422);
            }
            $token = $a->email_verify_token ?: Str::random(40);
            DB::table('offroll_agents')->where('id', $id)->update(['email_verify_token' => $token, 'updated_at' => now()]);
            $url = url('/agent/verify/'.$token);
            $company = $a->company_name ?: 'SmartPRS';
            try {
                Mail::html('<p>Dear '.e($a->name).',</p><p>Please confirm your email address with '.e($company).' by clicking the link below:</p><p><a href="'.$url.'">Verify my email</a></p><p>If the link does not work, copy this URL into your browser:<br>'.$url.'</p><p>Regards,<br>'.e($company).'</p>', function ($mail) use ($a, $company) {
                    $mail->to($a->email, $a->name)->subject('Verify your email — '.$company);
                });
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'error' => 'Could not send email: '.$e->getMessage()], 422);
            }

            return response()->json(['ok' => true, 'message' => 'Verification link sent to '.$a->email.'.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function setMobileVerified(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $v = $request->validate(['verified' => ['required', 'boolean']]);
            $a = $this->agent($tid, $id);
            if (! $a) {
                return response()->json(['ok' => false, 'error' => 'Agent not found'], 404);
            }
            DB::table('offroll_agents')->where('id', $id)->update([
                'mobile_verified_at' => $v['verified'] ? now() : null, 'updated_at' => now(),
            ]);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** PUBLIC — the agent clicks the emailed link to confirm their address. */
    public function verifyEmail(string $token)
    {
        $this->ensure();
        $a = DB::table('offroll_agents')->where('email_verify_token', $token)->first();
        $msg = $a ? 'Your email has been verified. Thank you!' : 'This verification link is invalid or has expired.';
        if ($a && empty($a->email_verified_at)) {
            DB::table('offroll_agents')->where('id', $a->id)->update(['email_verified_at' => now(), 'updated_at' => now()]);
        }
        $html = '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>Email verification</title>'
            .'<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f1f5f9;margin:0;padding:0}'
            .'.box{max-width:440px;margin:12vh auto;background:#fff;border-radius:16px;box-shadow:0 12px 40px rgba(15,23,42,.12);padding:32px;text-align:center}'
            .'.ic{font-size:44px}h1{font-size:20px;margin:14px 0 6px;color:#0f172a}p{color:#475569;font-size:14px}</style></head>'
            .'<body><div class="box"><div class="ic">'.($a ? '✅' : '⚠️').'</div><h1>'.($a ? 'Email verified' : 'Link invalid').'</h1><p>'.e($msg).'</p></div></body></html>';

        return response($html)->header('Content-Type', 'text/html');
    }
}
