<?php

namespace App\Http\Controllers;

use App\Services\WaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * rev 94 — BULK WhatsApp for recruitment & hiring (Ejaz: bulk candidate data
 * from job portals → instead of calling, send a bulk WhatsApp for interview /
 * walk-in, with complete tracking).
 *
 * Audiences: Talent Pool selection, shortlist, a requisition's pipeline, or a
 * fresh CSV upload. Each send becomes a CAMPAIGN; every recipient is a tracked
 * MESSAGE (sent/failed + Interakt error, optional delivered/read via webhook,
 * and a recruiter-updated response funnel: interested / will attend / attended
 * / hired / declined).
 *
 * Variable contract (so one form personalises N messages): the chosen template's
 * {{1}} is ALWAYS the candidate name; {{2}}.. are the shared campaign values the
 * recruiter fills once (company, role, date, venue, mode, link).
 */
class RecruitMessagingController extends Controller
{
    private function guard(Request $request)
    {
        return ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager']);
    }

    public static function ensure(): void
    {
        if (! Schema::hasTable('recruitment_campaigns')) {
            Schema::create('recruitment_campaigns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('job_id')->nullable();
                $t->string('title')->nullable();
                $t->string('template')->nullable();
                $t->string('purpose', 30)->nullable();        // interview_schedule | walkin_invite | custom
                $t->text('vars_json')->nullable();            // shared values {{2}}..
                $t->integer('total')->default(0);
                $t->integer('sent')->default(0);
                $t->integer('failed')->default(0);
                $t->string('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('recruitment_messages')) {
            Schema::create('recruitment_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('campaign_id')->index();
                $t->unsignedBigInteger('candidate_id')->nullable();
                $t->string('name')->nullable();
                $t->string('mobile', 20)->nullable();
                $t->string('template')->nullable();
                $t->string('status', 20)->default('queued');  // queued|sent|failed|delivered|read
                $t->text('error')->nullable();
                $t->string('wa_message_id')->nullable()->index();   // for webhook matching
                $t->string('response', 30)->nullable();        // interested|not_interested|will_attend|attended|hired|declined
                $t->timestamp('responded_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
            });
        }
    }

    /** Resolve the recipient set from the request (ids / pipeline / CSV rows). */
    private function recipients(Request $request, ?int $tid): array
    {
        $out = [];
        $seen = [];   // dedupe by 10-digit mobile

        $push = function ($name, $mobile, $cid = null) use (&$out, &$seen) {
            $digits = preg_replace('/\D+/', '', (string) $mobile);
            $ten = substr($digits, -10);
            if (strlen($ten) < 10) {
                $out[] = ['candidate_id' => $cid, 'name' => $name, 'mobile' => $mobile, 'skip' => 'No valid mobile'];

                return;
            }
            if (isset($seen[$ten])) {
                return;
            }
            $seen[$ten] = true;
            $out[] = ['candidate_id' => $cid, 'name' => $name ?: 'Candidate', 'mobile' => $ten];
        };

        $ids = array_filter(array_map('intval', (array) $request->input('candidate_ids', [])));
        if ($request->filled('job_id') && empty($ids)) {
            // Whole pipeline of a requisition.
            $rows = DB::table('recruitment')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('job_id', (int) $request->input('job_id'))
                ->get(['id', 'name', 'mobile']);
            foreach ($rows as $r) {
                $push($r->name, $r->mobile, (int) $r->id);
            }
        }
        if ($ids) {
            $rows = DB::table('recruitment')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereIn('id', $ids)->get(['id', 'name', 'mobile']);
            foreach ($rows as $r) {
                $push($r->name, $r->mobile, (int) $r->id);
            }
        }
        // Fresh CSV rows (parsed in-browser): [{name, mobile}, ...]
        foreach ((array) $request->input('rows', []) as $row) {
            $push($row['name'] ?? '', $row['mobile'] ?? '', null);
        }

        return $out;
    }

    /** POST /app/recruitment/messages/send — fire the bulk campaign. */
    public function send(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            self::ensure();
            $tid = $request->user()->tenant_id;
            $v = $request->validate([
                'template' => ['required', 'string', 'max:120'],
                'purpose' => ['nullable', 'string', 'max:30'],
                'title' => ['nullable', 'string', 'max:150'],
                'job_id' => ['nullable', 'integer'],
                'drive_id' => ['nullable', 'integer'],    // rev 95: invite fired from a Hiring Drive
                'vars' => ['nullable', 'array'],          // shared {{2}}.. values, in order
            ]);
            if (! WaService::config()) {
                return response()->json(['ok' => false, 'error' => 'WhatsApp is not connected yet — add the Interakt API key in WhatsApp API first.'], 422);
            }

            $recips = $this->recipients($request, $tid);
            $valid = array_values(array_filter($recips, fn ($r) => empty($r['skip'])));
            if (! $valid) {
                return response()->json(['ok' => false, 'error' => 'No recipients with a valid WhatsApp mobile number.'], 422);
            }
            if (count($valid) > 2000) {
                return response()->json(['ok' => false, 'error' => 'Please send to 2000 candidates or fewer per campaign.'], 422);
            }

            $sharedVars = array_values(array_map('strval', $v['vars'] ?? []));
            $campaignRow = [
                'tenant_id' => $tid, 'job_id' => $v['job_id'] ?? null,
                'title' => $v['title'] ?: ('WhatsApp campaign '.now()->format('d M H:i')),
                'template' => $v['template'], 'purpose' => $v['purpose'] ?? 'custom',
                'vars_json' => json_encode($sharedVars), 'total' => count($valid),
                'sent' => 0, 'failed' => 0,
                'created_by' => $request->user()->name ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ];
            // rev 95: tag the campaign with the drive when sent from a Hiring Drive.
            if (! empty($v['drive_id'])) {
                try {
                    \App\Http\Controllers\HiringDriveController::ensure();
                    if (Schema::hasColumn('recruitment_campaigns', 'drive_id')) {
                        $campaignRow['drive_id'] = (int) $v['drive_id'];
                    }
                } catch (\Throwable $e) {
                }
            }
            $campaignId = DB::table('recruitment_campaigns')->insertGetId($campaignRow);
            // Build the drive roster from the same valid recipients.
            if (! empty($v['drive_id'])) {
                try {
                    \App\Http\Controllers\HiringDriveController::addToRoster($tid, (int) $v['drive_id'], $valid);
                } catch (\Throwable $e) {
                }
            }

            $sent = 0;
            $failed = 0;
            foreach ($valid as $r) {
                // {{1}} = candidate name, then the shared values.
                $bodyValues = array_merge([$r['name']], $sharedVars);
                $ok = WaService::sendTemplate([
                    'tenant_id' => $tid,
                    'mobile' => $r['mobile'],
                    'template' => $v['template'],
                    'kind' => 'recruit.bulk',
                    'bodyValues' => $bodyValues,
                ]);
                $err = $ok ? null : DB::table('wa_log')->where('template', $v['template'])->orderByDesc('id')->value('error');
                DB::table('recruitment_messages')->insert([
                    'tenant_id' => $tid, 'campaign_id' => $campaignId,
                    'candidate_id' => $r['candidate_id'], 'name' => $r['name'], 'mobile' => $r['mobile'],
                    'template' => $v['template'], 'status' => $ok ? 'sent' : 'failed',
                    'error' => $err, 'sent_at' => $ok ? now() : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                // Stamp the candidate so the pool/pipeline shows "contacted".
                if ($r['candidate_id']) {
                    try {
                        $this->stampCandidate((int) $r['candidate_id'], $ok);
                    } catch (\Throwable $e) {
                    }
                }
                $ok ? $sent++ : $failed++;
            }
            // Also log the invalid-mobile rows as failed (visible in tracking).
            foreach ($recips as $r) {
                if (empty($r['skip'])) {
                    continue;
                }
                DB::table('recruitment_messages')->insert([
                    'tenant_id' => $tid, 'campaign_id' => $campaignId,
                    'candidate_id' => $r['candidate_id'], 'name' => $r['name'], 'mobile' => $r['mobile'],
                    'template' => $v['template'], 'status' => 'failed', 'error' => $r['skip'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $failed++;
            }
            DB::table('recruitment_campaigns')->where('id', $campaignId)->update([
                'sent' => $sent, 'failed' => $failed, 'total' => $sent + $failed, 'updated_at' => now(),
            ]);

            return response()->json([
                'ok' => true, 'campaign_id' => $campaignId,
                'message' => 'Campaign sent — '.$sent.' delivered to WhatsApp, '.$failed.' failed.',
                'sent' => $sent, 'failed' => $failed,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Add the contact columns to the recruitment table if missing, then stamp. */
    private function stampCandidate(int $id, bool $ok): void
    {
        foreach (['last_contacted_at' => 'ts', 'contact_status' => 'str'] as $c => $ty) {
            if (! Schema::hasColumn('recruitment', $c)) {
                Schema::table('recruitment', function (Blueprint $t) use ($c, $ty) {
                    $ty === 'ts' ? $t->timestamp($c)->nullable() : $t->string($c)->nullable();
                });
            }
        }
        DB::table('recruitment')->where('id', $id)->update([
            'last_contacted_at' => now(),
            'contact_status' => $ok ? 'messaged' : 'message_failed',
            'updated_at' => now(),
        ]);
    }

    /** GET /app/recruitment/campaigns — list with live counts. */
    public function campaigns(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        self::ensure();
        $tid = $request->user()->tenant_id;
        $rows = DB::table('recruitment_campaigns')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->orderByDesc('id')->limit(200)->get();
        // Response-funnel counts per campaign (one grouped query).
        $resp = DB::table('recruitment_messages')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->selectRaw('campaign_id, response, count(*) n')->groupBy('campaign_id', 'response')->get();
        $byCamp = [];
        foreach ($resp as $r) {
            $byCamp[$r->campaign_id][$r->response ?: 'none'] = (int) $r->n;
        }
        $deliv = DB::table('recruitment_messages')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->selectRaw('campaign_id, status, count(*) n')->groupBy('campaign_id', 'status')->get();
        $byStatus = [];
        foreach ($deliv as $r) {
            $byStatus[$r->campaign_id][$r->status] = (int) $r->n;
        }

        return response()->json(['ok' => true, 'rows' => $rows->map(fn ($c) => [
            'id' => (int) $c->id, 'title' => $c->title, 'template' => $c->template,
            'purpose' => $c->purpose, 'total' => (int) $c->total, 'sent' => (int) $c->sent,
            'failed' => (int) $c->failed, 'createdBy' => $c->created_by,
            'when' => $c->created_at ? \Illuminate\Support\Carbon::parse($c->created_at)->format('d M Y H:i') : '',
            'funnel' => $byCamp[$c->id] ?? [], 'delivery' => $byStatus[$c->id] ?? [],
        ])->values()]);
    }

    /** GET /app/recruitment/campaign/{id} — per-candidate tracking. */
    public function campaign(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        self::ensure();
        $tid = $request->user()->tenant_id;
        $c = DB::table('recruitment_campaigns')->where('id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $c) {
            return response()->json(['ok' => false, 'error' => 'Campaign not found.'], 404);
        }
        $msgs = DB::table('recruitment_messages')->where('campaign_id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->orderBy('id')->get();

        return response()->json([
            'ok' => true,
            'campaign' => ['id' => (int) $c->id, 'title' => $c->title, 'template' => $c->template, 'total' => (int) $c->total, 'sent' => (int) $c->sent, 'failed' => (int) $c->failed],
            'rows' => $msgs->map(fn ($m) => [
                'id' => (int) $m->id, 'candidateId' => $m->candidate_id ? (int) $m->candidate_id : null,
                'name' => $m->name, 'mobile' => $m->mobile, 'status' => $m->status,
                'error' => $m->error, 'response' => $m->response,
                'sentAt' => $m->sent_at ? \Illuminate\Support\Carbon::parse($m->sent_at)->format('d M H:i') : null,
            ])->values(),
        ]);
    }

    /** POST /app/recruitment/message/{id}/response — recruiter funnel update. */
    public function setResponse(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        self::ensure();
        $tid = $request->user()->tenant_id;
        $v = $request->validate(['response' => ['nullable', 'in:interested,not_interested,will_attend,attended,hired,declined']]);
        $m = DB::table('recruitment_messages')->where('id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $m) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }
        DB::table('recruitment_messages')->where('id', $id)->update([
            'response' => $v['response'] ?: null,
            'responded_at' => $v['response'] ? now() : null,
            'updated_at' => now(),
        ]);
        // Mirror onto the candidate record for the pool/pipeline view.
        if ($m->candidate_id && $v['response']) {
            try {
                if (! Schema::hasColumn('recruitment', 'contact_status')) {
                    Schema::table('recruitment', fn (Blueprint $t) => $t->string('contact_status')->nullable());
                }
                DB::table('recruitment')->where('id', $m->candidate_id)->update(['contact_status' => $v['response'], 'updated_at' => now()]);
            } catch (\Throwable $e) {
            }
        }

        return response()->json(['ok' => true, 'message' => 'Updated']);
    }

    /** GET /app/recruitment/campaign/{id}/export — CSV of the tracking. */
    public function export(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        self::ensure();
        $tid = $request->user()->tenant_id;
        $rows = DB::table('recruitment_messages')->where('campaign_id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->orderBy('id')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Mobile', 'WhatsApp status', 'Error', 'Response', 'Sent at']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->name, $r->mobile, $r->status, $r->error, $r->response, $r->sent_at]);
            }
            fclose($out);
        }, 'recruitment-campaign-'.$id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * PUBLIC webhook for Interakt message-status callbacks (delivered/read/
     * failed). Subscribe https://<site>/webhooks/interakt in Interakt (needs
     * their Growth/Advanced plan). Fail-soft + idempotent; matches by the
     * message id we stored, or by recipient mobile as a fallback.
     */
    public function interaktWebhook(Request $request)
    {
        try {
            self::ensure();
            $payload = $request->all();
            // Interakt nests differently across event types; scan defensively.
            $flat = json_encode($payload);
            $status = null;
            foreach (['read', 'delivered', 'sent', 'failed'] as $s) {
                if (stripos($flat, '"'.$s.'"') !== false) {
                    $status = $s;
                }
            }
            // Best-effort id + phone extraction.
            $msgId = $payload['data']['message']['id'] ?? ($payload['message_id'] ?? ($payload['id'] ?? null));
            $phone = $payload['data']['customer']['phone_number'] ?? ($payload['phone_number'] ?? null);
            $ten = $phone ? substr(preg_replace('/\D+/', '', (string) $phone), -10) : null;

            if ($status && ($msgId || $ten)) {
                $q = DB::table('recruitment_messages');
                if ($msgId) {
                    $q->where('wa_message_id', $msgId);
                } else {
                    $q->where('mobile', $ten);
                }
                // Never downgrade read→delivered.
                $rank = ['queued' => 0, 'sent' => 1, 'failed' => 1, 'delivered' => 2, 'read' => 3];
                $row = (clone $q)->orderByDesc('id')->first();
                if ($row && ($rank[$status] ?? 0) >= ($rank[$row->status] ?? 0)) {
                    DB::table('recruitment_messages')->where('id', $row->id)->update(['status' => $status, 'updated_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            // never error back to Interakt
        }

        return response()->json(['ok' => true]);
    }
}
