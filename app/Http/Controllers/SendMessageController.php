<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Send Message / broadcast — actually delivers. Email via the MailService queue;
 * SMS / WhatsApp via the provider configured under SMS Settings / WhatsApp
 * Settings (generic HTTP POST to the configured api_url). Admin/HR guarded,
 * tenant-scoped, fail-soft. Channels that aren't configured are simply skipped.
 */
class SendMessageController extends Controller
{
    public function send(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $v = $request->validate([
                'subject' => ['required', 'string', 'max:191'],
                'body' => ['required', 'string'],
                'target' => ['nullable', 'string', 'max:30'],
                'value' => ['nullable', 'string', 'max:191'],
                'company_id' => ['nullable'],   // back-compat
                'channels' => ['nullable', 'array'],
            ]);
            $tid = $request->user()->tenant_id;
            $target = $v['target'] ?? 'all';
            $value = $v['value'] ?? (! empty($v['company_id']) ? (string) $v['company_id'] : null);
            $channels = $v['channels'] ?? ['email'];
            if (! $channels) {
                $channels = ['email'];
            }

            // Resolve recipients by the chosen audience filter (schema-tolerant,
            // matches on the same resolved names the directory shows).
            [$emps, $targetLabel] = $this->resolveRecipients($tid, $target, $value);
            $companyId = ($target === 'company' && $value) ? (int) $value : null;

            $text = trim(($v['subject'] ? $v['subject'].': ' : '').$v['body']);
            $emailSent = 0;
            $smsSent = 0;
            $waSent = 0;

            // ---- Email (queued via the mail engine) ----
            if (in_array('email', $channels, true)) {
                foreach ($emps as $e) {
                    if (empty($e->email)) {
                        continue;
                    }
                    MailService::queue([
                        'tenant_id' => $tid,
                        'company_id' => $e->company_id,
                        'to' => $e->email,
                        'to_name' => $e->name,
                        'subject' => $v['subject'],
                        'heading' => $v['subject'],
                        'body' => $v['body'],
                        'kind' => 'broadcast',
                    ]);
                    $emailSent++;
                }
            }

            // ---- SMS (provider POST per SMS Settings) ----
            if (in_array('sms', $channels, true)) {
                $cfg = $this->activeProvider('sms_settings', $tid);
                if ($cfg) {
                    foreach ($emps as $e) {
                        $m = $this->mobile($e);
                        if ($m && $this->postProvider($cfg, $m, $text, 'sms')) {
                            $smsSent++;
                        }
                    }
                }
            }

            // ---- WhatsApp (provider POST per WhatsApp Settings) ----
            if (in_array('whatsapp', $channels, true)) {
                $cfg = $this->activeProvider('wa_settings', $tid);
                if ($cfg) {
                    foreach ($emps as $e) {
                        $m = $this->mobile($e);
                        if ($m && $this->postProvider($cfg, $m, $text, 'whatsapp')) {
                            $waSent++;
                        }
                    }
                }
            }

            $usedChannels = [];
            if ($emailSent) {
                $usedChannels[] = 'email';
            }
            if ($smsSent) {
                $usedChannels[] = 'sms';
            }
            if ($waSent) {
                $usedChannels[] = 'whatsapp';
            }

            // Log the broadcast.
            try {
                $row = ApprovalService::safeRow('messages_log', [
                    'tenant_id' => $tid,
                    'company_id' => $companyId ?: DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->value('id'),
                    'target' => $targetLabel,
                    'recipients' => $emailSent + $smsSent + $waSent,
                    'channels' => $usedChannels ? implode(', ', $usedChannels) : implode(', ', $channels),
                    'message' => $v['subject'].' — '.mb_substr($v['body'], 0, 200),
                    'sent_by' => $request->user()->id,
                    'sent_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('messages_log')->insert($row);
            } catch (\Throwable $e) {
                // logging is best-effort
            }

            $parts = [];
            if (in_array('email', $channels, true)) {
                $parts[] = 'Email '.$emailSent;
            }
            if (in_array('sms', $channels, true)) {
                $parts[] = 'SMS '.$smsSent;
            }
            if (in_array('whatsapp', $channels, true)) {
                $parts[] = 'WhatsApp '.$waSent;
            }
            $msg = $parts ? ('Sent — '.implode(' · ', $parts).'.') : 'Nothing sent.';
            if (in_array('sms', $channels, true) && ! $this->activeProvider('sms_settings', $tid)) {
                $msg .= ' (SMS skipped: no active provider in SMS Settings.)';
            }
            if (in_array('whatsapp', $channels, true) && ! $this->activeProvider('wa_settings', $tid)) {
                $msg .= ' (WhatsApp skipped: no active provider in WhatsApp Settings.)';
            }

            return response()->json(['ok' => true, 'count' => $emailSent + $smsSent + $waSent, 'message' => $msg]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Resolve active recipients for an audience filter. Returns [Collection, label].
     * Supported targets: all, company (value=company_id), department, team,
     * designation, branch (value=name), type (value=employment type),
     * employee (value=emp_code). Names are resolved the same way the directory
     * shows them, so matching works whether the schema stores a string or an FK id.
     */
    private function resolveRecipients(?int $tid, string $target, $value): array
    {
        $rows = DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->where('status', 'active')->whereNull('deleted_at')
            ->get();

        $dept = DB::table('departments')->pluck('name', 'id');
        $desig = DB::table('designations')->pluck('name', 'id');
        $branch = DB::table('branches')->pluck('name', 'id');
        $team = DB::table('teams')->pluck('name', 'id');

        $rows->each(function ($e) use ($dept, $desig, $branch, $team) {
            $a = (array) $e;
            $e->_dept = ($a['department'] ?? null) ?: ($dept[$a['department_id'] ?? 0] ?? '');
            $e->_team = ($a['team'] ?? null) ?: ($team[$a['team_id'] ?? 0] ?? '');
            $e->_desig = ($a['designation'] ?? null) ?: ($desig[$a['designation_id'] ?? 0] ?? '');
            $e->_branch = ($a['branch'] ?? null) ?: ($branch[$a['branch_id'] ?? 0] ?? '');
            // Mirror the directory's display label so the picker value matches.
            $e->_type = (($a['type'] ?? '') === 'field') ? 'Field / FOS' : 'Office';
        });

        $val = is_string($value) ? trim($value) : $value;
        $eq = fn ($a, $b) => strcasecmp((string) $a, (string) $b) === 0;

        switch ($target) {
            case 'company':
                $cid = (int) $val;

                return [$rows->filter(fn ($e) => (int) ($e->company_id ?? 0) === $cid)->values(), 'Company'];
            case 'department':
                return [$rows->filter(fn ($e) => $eq($e->_dept, $val))->values(), 'Department: '.$val];
            case 'team':
                return [$rows->filter(fn ($e) => $eq($e->_team, $val))->values(), 'Team: '.$val];
            case 'designation':
                return [$rows->filter(fn ($e) => $eq($e->_desig, $val))->values(), 'Designation: '.$val];
            case 'branch':
                return [$rows->filter(fn ($e) => $eq($e->_branch, $val))->values(), 'Branch: '.$val];
            case 'type':
                return [$rows->filter(fn ($e) => $eq($e->_type, $val))->values(), 'Type: '.$val];
            case 'employee':
                return [$rows->filter(fn ($e) => $eq($e->emp_code ?? '', $val))->values(), 'Individual employee'];
            default:
                return [$rows->values(), 'All active employees'];
        }
    }

    /** The active provider config row for a settings table (or null). */
    private function activeProvider(string $table, ?int $tid)
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        try {
            return DB::table($table)
                ->when($tid && Schema::hasColumn($table, 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->whereRaw("LOWER(COALESCE(status, 'active')) = 'active'")
                ->whereNotNull('api_url')->where('api_url', '!=', '')
                ->orderByDesc('id')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function mobile($e): ?string
    {
        $m = property_exists($e, 'mobile') ? trim((string) ($e->mobile ?? '')) : '';

        return $m !== '' ? $m : null;
    }

    /**
     * Generic provider POST. Sends a superset of common field names so it works
     * with MSG91/Twilio-style endpoints the admin points api_url at. Returns true
     * on a 2xx response. Never throws.
     */
    private function postProvider(object $cfg, string $mobile, string $text, string $kind): bool
    {
        if (empty($cfg->api_url)) {
            return false;
        }
        $sender = $kind === 'whatsapp'
            ? ($cfg->sender_number ?? '')
            : ($cfg->sender_id ?? '');
        $payload = [
            'apikey' => $cfg->api_key ?? '',
            'authkey' => $cfg->api_key ?? '',
            'api_key' => $cfg->api_key ?? '',
            'sender' => $sender,
            'from' => $sender,
            'mobile' => $mobile,
            'mobiles' => $mobile,
            'to' => $mobile,
            'message' => $text,
            'text' => $text,
            'body' => $text,
        ];
        if ($kind === 'sms' && ! empty($cfg->dlt_entity_id)) {
            $payload['DLT_TE_ID'] = $cfg->dlt_entity_id;
            $payload['entity_id'] = $cfg->dlt_entity_id;
        }
        try {
            $resp = Http::timeout(15)->asForm()->post($cfg->api_url, $payload);

            return $resp->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
