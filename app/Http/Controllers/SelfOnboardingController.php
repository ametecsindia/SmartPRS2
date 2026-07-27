<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use App\Services\WaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Candidate & existing-employee SELF-ONBOARDING portal.
 * Public token-secured portal (progressive save, OTP, selfie, docs) + an HR
 * verification console (verify, request-correction, set HR fields, approve &
 * inject into the employees master). Bulk CSV import with two-phase matching.
 * On approval the link is disabled but archived (never deleted).
 */
class SelfOnboardingController extends Controller
{
    private const SECTIONS = ['personal', 'contact', 'statutory', 'bank'];

    public static function ensureTables(): void
    {
        if (! Schema::hasTable('self_onboarding')) {
            Schema::create('self_onboarding', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->string('token', 64)->unique();
                $t->string('temp_emp_code')->index();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('candidate_id')->nullable()->index();
                $t->unsignedBigInteger('employee_id')->nullable()->index();
                $t->string('mode')->default('new');
                $t->string('name')->nullable();
                $t->string('email')->nullable();
                $t->boolean('email_verified')->default(false);
                $t->string('mobile', 20)->nullable();
                $t->boolean('mobile_verified')->default(false);
                $t->string('whatsapp', 20)->nullable();
                $t->boolean('wa_verified')->default(false);
                $t->json('data')->nullable();
                $t->json('flags')->nullable();
                $t->string('selfie_path')->nullable();
                $t->unsignedTinyInteger('progress')->default(0);
                $t->string('status')->default('link_sent');
                $t->string('pin_hash')->nullable();
                $t->timestamp('link_expires_at')->nullable();
                $t->timestamp('link_disabled_at')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('self_onboarding_otps')) {
            Schema::create('self_onboarding_otps', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('self_onboarding_id')->index();
                $t->string('channel', 10);
                $t->string('code_hash');
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('verified_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('self_onboarding_docs')) {
            Schema::create('self_onboarding_docs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('self_onboarding_id')->index();
                $t->string('kind');
                $t->string('path');
                $t->string('status')->default('pending');
                $t->timestamps();
            });
        }
    }

    public static function issue(array $c): object
    {
        self::ensureTables();
        if (! empty($c['candidate_id'])) {
            $existing = DB::table('self_onboarding')->where('candidate_id', $c['candidate_id'])
                ->when(! empty($c['tenant_id']), fn ($q) => $q->where('tenant_id', $c['tenant_id']))
                ->whereNull('deleted_at')->whereNull('link_disabled_at')->orderByDesc('id')->first();
            if ($existing) {
                return $existing;
            }
        }
        $year = date('Y');
        $prefix = 'TMP-'.$year.'-';
        $max = DB::table('self_onboarding')->where('temp_emp_code', 'like', $prefix.'%')->orderByDesc('temp_emp_code')->value('temp_emp_code');
        $n = $max ? ((int) substr($max, strlen($prefix))) + 1 : 1;
        $code = $prefix.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
        $id = DB::table('self_onboarding')->insertGetId([
            'uuid' => (string) Str::uuid(), 'token' => bin2hex(random_bytes(24)), 'temp_emp_code' => $code,
            'tenant_id' => $c['tenant_id'] ?? null, 'company_id' => $c['company_id'] ?? null,
            'candidate_id' => $c['candidate_id'] ?? null, 'employee_id' => $c['employee_id'] ?? null,
            'mode' => $c['mode'] ?? 'new', 'name' => $c['name'] ?? null, 'email' => $c['email'] ?? null,
            'mobile' => $c['mobile'] ?? null, 'whatsapp' => $c['whatsapp'] ?? ($c['mobile'] ?? null),
            'data' => isset($c['data']) ? json_encode($c['data']) : null,
            'status' => 'link_sent', 'link_expires_at' => now()->addDays(14),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('self_onboarding')->where('id', $id)->first();
    }

    public static function sendLink(object $rec, array $ctx = []): void
    {
        try {
            $link = route('selfonboard.start', $rec->token);
            $first = $rec->name ? explode(' ', trim($rec->name))[0] : '';
            if (! empty($rec->email)) {
                MailService::queue([
                    'tenant_id' => $rec->tenant_id, 'company_id' => $rec->company_id, 'to' => $rec->email, 'to_name' => $rec->name,
                    'subject' => 'Complete your onboarding'.(! empty($ctx['brand']) ? ' — '.$ctx['brand'] : ''),
                    'heading' => 'Welcome aboard'.($first ? ', '.$first : '').'!',
                    'intro' => 'Please complete your onboarding — it only takes a few minutes, one simple step at a time.',
                    'lines' => array_filter(['Reference' => $rec->temp_emp_code, 'Position' => $ctx['position'] ?? null]),
                    'cta_label' => 'Start Self-Onboarding', 'cta_url' => $link, 'kind' => 'onboarding.selflink', 'sync' => true,
                ]);
            }
        } catch (\Throwable $e) {
        }
    }

    /* ------------------------------------------------------------------ portal */

    public function start(string $token)
    {
        $this->ensureTables();
        $rec = DB::table('self_onboarding')->where('token', $token)->whereNull('deleted_at')->first();
        if (! $rec) {
            return response()->view('self-onboarding.message', ['title' => 'Link not valid', 'msg' => 'This onboarding link is not recognised. Please contact the HR team that sent it.'], 404);
        }
        if ($rec->link_disabled_at) {
            return response()->view('self-onboarding.message', ['title' => 'Onboarding complete', 'msg' => 'Your onboarding has been submitted and approved. Thank you — there is nothing more to do here.']);
        }
        if ($rec->link_expires_at && now()->greaterThan($rec->link_expires_at)) {
            return response()->view('self-onboarding.message', ['title' => 'Link expired', 'msg' => 'This onboarding link has expired. Please ask the HR team to send you a fresh link.']);
        }
        if (($rec->status ?? '') === 'link_sent') {
            DB::table('self_onboarding')->where('id', $rec->id)->update(['status' => 'opened', 'updated_at' => now()]);
        }
        $docs = DB::table('self_onboarding_docs')->where('self_onboarding_id', $rec->id)->pluck('kind')->all();

        return view('self-onboarding.portal', [
            'rec' => $rec, 'data' => json_decode($rec->data ?: '{}', true) ?: [], 'docKinds' => $docs,
            'hasSelfie' => (bool) $rec->selfie_path, 'logoUrl' => ConfigController::appLogoUrlFor($rec->tenant_id),
        ]);
    }

    private function gate(string $token)
    {
        $this->ensureTables();
        $rec = DB::table('self_onboarding')->where('token', $token)->whereNull('deleted_at')->first();
        if (! $rec) {
            return [null, response()->json(['ok' => false, 'error' => 'This onboarding link is not valid.'], 404)];
        }
        if ($rec->link_disabled_at) {
            return [null, response()->json(['ok' => false, 'error' => 'Your onboarding is already complete.'], 403)];
        }
        if ($rec->link_expires_at && now()->greaterThan($rec->link_expires_at)) {
            return [null, response()->json(['ok' => false, 'error' => 'This link has expired.'], 403)];
        }

        return [$rec, null];
    }

    public function otpSend(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $channel = $r->input('channel');
        if (! in_array($channel, ['email', 'whatsapp'], true)) {
            return response()->json(['ok' => false, 'error' => 'Unknown channel.'], 422);
        }
        $to = $channel === 'email' ? $rec->email : ($rec->whatsapp ?: $rec->mobile);
        if (! $to) {
            return response()->json(['ok' => false, 'error' => 'No '.$channel.' on file. Please contact HR.'], 422);
        }
        $code = (string) random_int(100000, 999999);
        DB::table('self_onboarding_otps')->insert(['self_onboarding_id' => $rec->id, 'channel' => $channel, 'code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
        if ($channel === 'email') {
            MailService::queue(['tenant_id' => $rec->tenant_id, 'company_id' => $rec->company_id, 'to' => $rec->email, 'to_name' => $rec->name, 'subject' => 'Your SmartPRS verification code', 'heading' => 'Verify your email', 'intro' => 'Use the one-time code below to verify your email. It is valid for 10 minutes. Do not share it.', 'lines' => ['Verification code' => $code], 'kind' => 'onboarding.otp', 'sync' => true]);
        } else {
            WaService::sendTemplate(['tenant_id' => $rec->tenant_id, 'mobile' => $to, 'template' => WaService::templateNameFor('otp', $rec->tenant_id), 'bodyValues' => [$code], 'kind' => 'onboarding.otp']);
        }
        if (($rec->status ?? '') === 'opened') {
            DB::table('self_onboarding')->where('id', $rec->id)->update(['status' => 'verifying', 'updated_at' => now()]);
        }
        $out = ['ok' => true, 'sent' => $channel];
        if (app()->environment('local') || config('app.debug')) {
            $out['dev_code'] = $code;
        }

        return response()->json($out);
    }

    public function otpVerify(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $channel = $r->input('channel');
        $code = trim((string) $r->input('code'));
        if (! in_array($channel, ['email', 'whatsapp'], true) || $code === '') {
            return response()->json(['ok' => false, 'error' => 'Enter the code.'], 422);
        }
        $row = DB::table('self_onboarding_otps')->where('self_onboarding_id', $rec->id)->where('channel', $channel)->whereNull('verified_at')->where('expires_at', '>', now())->orderByDesc('id')->first();
        if (! $row) {
            return response()->json(['ok' => false, 'error' => 'Code expired — please resend.'], 422);
        }
        if ($row->attempts >= 5) {
            return response()->json(['ok' => false, 'error' => 'Too many attempts — resend a new code.'], 429);
        }
        if (! Hash::check($code, $row->code_hash)) {
            DB::table('self_onboarding_otps')->where('id', $row->id)->increment('attempts');

            return response()->json(['ok' => false, 'error' => 'Incorrect code. Please try again.'], 422);
        }
        DB::table('self_onboarding_otps')->where('id', $row->id)->update(['verified_at' => now(), 'updated_at' => now()]);
        $upd = ['updated_at' => now()];
        if ($channel === 'email') {
            $upd['email_verified'] = true;
        } else {
            $upd['wa_verified'] = true;
            $upd['mobile_verified'] = true;
        }
        DB::table('self_onboarding')->where('id', $rec->id)->update($upd);
        $rec = DB::table('self_onboarding')->where('id', $rec->id)->first();

        return response()->json(['ok' => true, 'verified' => ['email' => (bool) $rec->email_verified, 'mobile' => (bool) $rec->mobile_verified, 'whatsapp' => (bool) $rec->wa_verified]]);
    }

    public function save(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $section = (string) $r->input('section');
        if (! in_array($section, self::SECTIONS, true)) {
            return response()->json(['ok' => false, 'error' => 'Unknown section.'], 422);
        }
        $all = json_decode($rec->data ?: '{}', true) ?: [];
        $all[$section] = (array) $r->input('data', []);
        $progress = $this->calcProgress($all, $rec);
        DB::table('self_onboarding')->where('id', $rec->id)->update(['data' => json_encode($all), 'progress' => $progress, 'status' => in_array($rec->status, ['submitted', 'verified', 'approved', 'injected'], true) ? $rec->status : 'in_progress', 'updated_at' => now()]);

        return response()->json(['ok' => true, 'progress' => $progress]);
    }

    public function selfie(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $bin = base64_decode((string) preg_replace('#^data:image/\w+;base64,#', '', (string) $r->input('image')), true);
        if (! $bin || strlen($bin) < 500) {
            return response()->json(['ok' => false, 'error' => 'Could not read the photo — please retake.'], 422);
        }
        if (strlen($bin) > 4 * 1024 * 1024) {
            return response()->json(['ok' => false, 'error' => 'Photo too large.'], 422);
        }
        $path = 'self-onboarding/'.$rec->id.'/selfie.jpg';
        Storage::disk('local')->put($path, $bin);
        DB::table('self_onboarding')->where('id', $rec->id)->update(['selfie_path' => $path, 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function document(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $file = $r->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['ok' => false, 'error' => 'No file received.'], 422);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return response()->json(['ok' => false, 'error' => 'File too large (max 5 MB).'], 422);
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            return response()->json(['ok' => false, 'error' => 'Only PDF, JPG or PNG allowed.'], 422);
        }
        $path = $file->store('self-onboarding/'.$rec->id, 'local');
        DB::table('self_onboarding_docs')->insert(['self_onboarding_id' => $rec->id, 'kind' => (string) $r->input('kind', 'other'), 'path' => $path, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->recomputeProgress($rec->id);

        return response()->json(['ok' => true, 'kinds' => DB::table('self_onboarding_docs')->where('self_onboarding_id', $rec->id)->pluck('kind')->all()]);
    }

    public function submit(Request $r, string $token)
    {
        [$rec, $err] = $this->gate($token);
        if ($err) {
            return $err;
        }
        $all = json_decode($rec->data ?: '{}', true) ?: [];
        $miss = [];
        if (! $rec->email_verified) {
            $miss[] = 'verify your email';
        }
        if (! $rec->mobile_verified && ! $rec->wa_verified) {
            $miss[] = 'verify your mobile/WhatsApp';
        }
        if (empty($all['personal'])) {
            $miss[] = 'personal details';
        }
        if (empty($all['bank'])) {
            $miss[] = 'bank details';
        }
        if (! $rec->selfie_path) {
            $miss[] = 'take a selfie';
        }
        if ($miss) {
            return response()->json(['ok' => false, 'error' => 'Please complete: '.implode(', ', $miss).'.'], 422);
        }
        DB::table('self_onboarding')->where('id', $rec->id)->update(['status' => 'submitted', 'submitted_at' => now(), 'progress' => 100, 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function calcProgress(array $all, $rec): int
    {
        $done = 0;
        $total = 8;
        if (($rec->email_verified ?? false) && (($rec->wa_verified ?? false) || ($rec->mobile_verified ?? false))) {
            $done++;
        }
        foreach (self::SECTIONS as $s) {
            if (! empty($all[$s])) {
                $done++;
            }
        }
        if (! empty($rec->selfie_path)) {
            $done++;
        }
        try {
            if (DB::table('self_onboarding_docs')->where('self_onboarding_id', $rec->id)->exists()) {
                $done++;
            }
        } catch (\Throwable $e) {
        }

        return (int) round($done / $total * 100);
    }

    private function recomputeProgress(int $id): void
    {
        $rec = DB::table('self_onboarding')->where('id', $id)->first();
        if (! $rec) {
            return;
        }
        $all = json_decode($rec->data ?: '{}', true) ?: [];
        DB::table('self_onboarding')->where('id', $id)->update(['progress' => $this->calcProgress($all, $rec), 'updated_at' => now()]);
    }

    public function selfieImg(string $token)
    {
        $rec = DB::table('self_onboarding')->where('token', $token)->whereNull('deleted_at')->first();
        if (! $rec || ! $rec->selfie_path || ! Storage::disk('local')->exists($rec->selfie_path)) {
            abort(404);
        }

        return response(Storage::disk('local')->get($rec->selfie_path), 200, ['Content-Type' => 'image/jpeg']);
    }

    /* ------------------------------------------------------------- HR console */

    private function hrDeny(Request $r)
    {
        return ApprovalService::denyUnlessRole($r, ['admin', 'hr_manager']);
    }

    private function hrRec(Request $r, int $id)
    {
        $tid = $r->user()->tenant_id ?? null;

        return DB::table('self_onboarding')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->first();
    }

    public function hrConsole(Request $r)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }

        return view('self-onboarding.hr-console', ['logoUrl' => ConfigController::appLogoUrlFor($r->user()->tenant_id ?? null)]);
    }

    public function hrList(Request $r)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $this->ensureTables();
        $tid = $r->user()->tenant_id ?? null;
        $counts = DB::table('self_onboarding_docs')->select('self_onboarding_id', DB::raw('count(*) as c'))->groupBy('self_onboarding_id')->pluck('c', 'self_onboarding_id');
        $rows = DB::table('self_onboarding')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->whereIn('status', ['submitted', 'correction', 'verified', 'approved', 'injected'])->orderByDesc('submitted_at')->orderByDesc('id')->get();

        return response()->json(['ok' => true, 'rows' => $rows->map(fn ($x) => ['id' => $x->id, 'temp_emp_code' => $x->temp_emp_code, 'name' => $x->name, 'status' => $x->status, 'mode' => $x->mode, 'progress' => $x->progress, 'email_verified' => (bool) $x->email_verified, 'mobile_verified' => (bool) ($x->mobile_verified || $x->wa_verified), 'docs' => (int) ($counts[$x->id] ?? 0), 'selfie' => (bool) $x->selfie_path, 'submitted_at' => $x->submitted_at])]);
    }

    public function hrShow(Request $r, int $id)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $rec = $this->hrRec($r, $id);
        if (! $rec) {
            return response()->json(['ok' => false, 'error' => 'Not found.'], 404);
        }
        $all = json_decode($rec->data ?: '{}', true) ?: [];
        $docs = DB::table('self_onboarding_docs')->where('self_onboarding_id', $id)->get()->map(fn ($d) => ['id' => $d->id, 'kind' => $d->kind, 'status' => $d->status, 'url' => url('/app/self-onboarding/'.$id.'/doc/'.$d->id)]);

        return response()->json(['ok' => true, 'rec' => [
            'id' => $rec->id, 'temp_emp_code' => $rec->temp_emp_code, 'name' => $rec->name, 'email' => $rec->email,
            'mobile' => $rec->mobile, 'whatsapp' => $rec->whatsapp, 'mode' => $rec->mode, 'employee_id' => $rec->employee_id,
            'email_verified' => (bool) $rec->email_verified, 'mobile_verified' => (bool) $rec->mobile_verified, 'wa_verified' => (bool) $rec->wa_verified,
            'status' => $rec->status, 'progress' => $rec->progress, 'data' => $all, 'hr' => $all['hr'] ?? [],
            'flags' => json_decode($rec->flags ?: '[]', true) ?: [],
            'selfie' => $rec->selfie_path ? url('/app/self-onboarding/'.$id.'/selfie') : null, 'docs' => $docs,
        ]]);
    }

    public function hrSelfie(Request $r, int $id)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $rec = $this->hrRec($r, $id);
        if (! $rec || ! $rec->selfie_path || ! Storage::disk('local')->exists($rec->selfie_path)) {
            abort(404);
        }

        return response(Storage::disk('local')->get($rec->selfie_path), 200, ['Content-Type' => 'image/jpeg']);
    }

    public function hrDoc(Request $r, int $id, int $doc)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $rec = $this->hrRec($r, $id);
        $row = $rec ? DB::table('self_onboarding_docs')->where('id', $doc)->where('self_onboarding_id', $id)->first() : null;
        if (! $row || ! Storage::disk('local')->exists($row->path)) {
            abort(404);
        }
        $mime = str_ends_with(strtolower($row->path), '.pdf') ? 'application/pdf' : 'image/jpeg';

        return response(Storage::disk('local')->get($row->path), 200, ['Content-Type' => $mime]);
    }

    /** Search existing employees for the invite / matching (by code or name). */
    public function hrEmployees(Request $r)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $tid = $r->user()->tenant_id ?? null;
        $q = trim((string) $r->input('q'));
        $rows = DB::table('employees')->when($tid, fn ($x) => $x->where('tenant_id', $tid))->whereNull('deleted_at')
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('emp_code', 'like', '%'.$q.'%')->orWhere('name', 'like', '%'.$q.'%')))
            ->orderBy('name')->limit(15)->get(['id', 'emp_code', 'name', 'email', 'mobile']);

        return response()->json(['ok' => true, 'rows' => $rows]);
    }

    /** Save HR-entered fields (designation/department/DOJ/CTC/PF/ESIC…) onto the record. */
    public function hrSetFields(Request $r, int $id)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $rec = $this->hrRec($r, $id);
        if (! $rec) {
            return response()->json(['ok' => false, 'error' => 'Not found.'], 404);
        }
        $keys = ['designation', 'department', 'branch', 'doj', 'employment_type', 'work_type', 'ctc', 'pf_uan', 'pf_applicable', 'esic_no', 'esi_applicable', 'pt_state'];
        $hr = [];
        foreach ($keys as $k) {
            $v = $r->input($k);
            if ($v !== null && $v !== '') {
                $hr[$k] = is_string($v) ? trim($v) : $v;
            }
        }
        $all = json_decode($rec->data ?: '{}', true) ?: [];
        $all['hr'] = $hr;
        DB::table('self_onboarding')->where('id', $id)->update(['data' => json_encode($all), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function hrCorrection(Request $r, int $id)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $rec = $this->hrRec($r, $id);
        if (! $rec) {
            return response()->json(['ok' => false, 'error' => 'Not found.'], 404);
        }
        $items = array_values(array_filter(array_map('trim', (array) $r->input('items', []))));
        if (! $items) {
            return response()->json(['ok' => false, 'error' => 'Add at least one item to correct.'], 422);
        }
        $note = trim((string) $r->input('note', ''));
        DB::table('self_onboarding')->where('id', $id)->update(['status' => 'correction', 'flags' => json_encode($items), 'updated_at' => now()]);
        try {
            if ($rec->email) {
                MailService::queue(['tenant_id' => $rec->tenant_id, 'company_id' => $rec->company_id, 'to' => $rec->email, 'to_name' => $rec->name, 'subject' => 'Action needed on your onboarding', 'heading' => 'A few items need your attention', 'intro' => $note ?: 'Please correct the following and re-submit your onboarding:', 'lines' => ['Please correct' => implode('; ', $items)], 'cta_label' => 'Open my onboarding', 'cta_url' => route('selfonboard.start', $rec->token), 'kind' => 'onboarding.correction', 'sync' => true]);
            }
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true]);
    }

    public function hrVerify(Request $r, int $id)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $rec = $this->hrRec($r, $id);
        if (! $rec) {
            return response()->json(['ok' => false, 'error' => 'Not found.'], 404);
        }
        DB::table('self_onboarding')->where('id', $id)->update(['status' => 'verified', 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function hrApprove(Request $r, int $id)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $rec = $this->hrRec($r, $id);
        if (! $rec) {
            return response()->json(['ok' => false, 'error' => 'Not found.'], 404);
        }
        if ($rec->status === 'injected') {
            return response()->json(['ok' => true, 'already' => true, 'emp_code' => DB::table('employees')->where('id', $rec->employee_id)->value('emp_code')]);
        }
        if ($rec->status !== 'verified') {
            return response()->json(['ok' => false, 'error' => 'Mark the submission Verified before approving.'], 422);
        }
        $res = $this->injectOne($rec);

        return response()->json(['ok' => true, 'emp_code' => $res['emp_code'], 'employee_id' => $res['employee_id'], 'updated' => $res['updated']]);
    }

    public function hrInvite(Request $r)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $name = trim((string) $r->input('name'));
        $email = trim((string) $r->input('email'));
        $mobile = trim((string) $r->input('mobile'));
        if ($name === '' || ($email === '' && $mobile === '')) {
            return response()->json(['ok' => false, 'error' => 'Enter a name and at least an email or mobile.'], 422);
        }
        $u = $r->user();
        $rec = self::issue(['tenant_id' => $u->tenant_id ?? null, 'company_id' => $u->company_id ?? null, 'name' => $name, 'email' => $email ?: null, 'mobile' => $mobile ?: null, 'mode' => 'new']);
        self::sendLink($rec);

        return response()->json(['ok' => true, 'temp_emp_code' => $rec->temp_emp_code, 'link' => route('selfonboard.start', $rec->token)]);
    }

    public function hrInviteExisting(Request $r)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $tid = $r->user()->tenant_id ?? null;
        $empId = (int) $r->input('employee_id');
        $key = trim((string) $r->input('emp'));
        $emp = null;
        if ($empId) {
            $emp = DB::table('employees')->where('id', $empId)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->first();
        }
        if (! $emp && $key !== '') {
            $emp = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->where(fn ($q) => $q->where('emp_code', $key)->orWhere('name', 'like', '%'.$key.'%'))->orderBy('id')->first();
        }
        if (! $emp) {
            return response()->json(['ok' => false, 'error' => 'No employee found. Search and pick one.'], 404);
        }
        $snapshot = [
            'personal' => array_filter(['full_name' => $emp->name, 'dob' => $emp->dob, 'gender' => $emp->gender]),
            'contact' => array_filter(['current_address' => $emp->address]),
            'statutory' => array_filter(['pan' => $emp->pan, 'uan' => $emp->uan]),
            'bank' => array_filter(['acc_name' => $emp->name, 'bank_name' => $emp->bank_name, 'acc_no' => $emp->bank_acc, 'ifsc' => $emp->ifsc]),
        ];
        $rec = self::issue(['tenant_id' => $tid, 'company_id' => $emp->company_id, 'employee_id' => $emp->id, 'mode' => 'existing', 'name' => $emp->name, 'email' => $emp->email, 'mobile' => $emp->mobile, 'whatsapp' => $emp->whatsapp ?? $emp->mobile, 'data' => $snapshot]);
        self::sendLink($rec);

        return response()->json(['ok' => true, 'name' => $emp->name, 'emp_code_existing' => $emp->emp_code, 'temp_emp_code' => $rec->temp_emp_code, 'link' => route('selfonboard.start', $rec->token)]);
    }

    /* ------------------------------------------------------------------ inject */

    private function ensureOnboardingTable(): void
    {
        if (! Schema::hasTable('onboarding')) {
            Schema::create('onboarding', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('employee')->nullable();
                $t->string('company_name')->nullable();
                $t->string('stage')->nullable();
                $t->date('joined_on')->nullable();
                $t->string('status')->nullable();
                $t->timestamps();
            });
        }
    }

    /** Build an employees-column patch from the HR-entered fields (resolving FKs). */
    private function hrPatch(array $hr, $tid): array
    {
        $this->ensureEmployeeCols();
        $patch = [];
        if (! empty($hr['designation'])) {
            $did = DB::table('designations')->where('name', $hr['designation'])->when($tid, fn ($q) => $q->where('tenant_id', $tid))->value('id');
            if (! $did) {
                $did = DB::table('designations')->insertGetId(ApprovalService::safeRow('designations', ['tenant_id' => $tid, 'name' => $hr['designation'], 'created_at' => now(), 'updated_at' => now()]));
            }
            $patch['designation_id'] = $did;
        }
        if (! empty($hr['department'])) {
            $dep = DB::table('departments')->where('name', $hr['department'])->when($tid, fn ($q) => $q->where('tenant_id', $tid))->value('id');
            if ($dep) {
                $patch['department_id'] = $dep;
            }
        }
        if (! empty($hr['branch'])) {
            $br = DB::table('branches')->where('name', $hr['branch'])->when($tid, fn ($q) => $q->where('tenant_id', $tid))->value('id');
            if ($br) {
                $patch['branch_id'] = $br;
            }
        }
        if (! empty($hr['doj'])) {
            $patch['doj'] = $hr['doj'];
        }
        if (isset($hr['ctc']) && $hr['ctc'] !== '') {
            $patch['ctc'] = (float) $hr['ctc'];
        }
        if (! empty($hr['work_type']) && in_array(strtolower($hr['work_type']), ['office', 'field'], true)) {
            $patch['type'] = strtolower($hr['work_type']);
        }
        if (! empty($hr['pf_uan'])) {
            $patch['uan'] = $hr['pf_uan'];
        }
        if (isset($hr['pf_applicable'])) {
            $patch['pf_applicable'] = in_array(strtolower((string) $hr['pf_applicable']), ['1', 'yes', 'true', 'on'], true);
        }
        if (! empty($hr['esi_applicable'])) {
            $patch['esi_applicable'] = in_array(strtolower($hr['esi_applicable']), ['yes', 'no', 'auto'], true) ? strtolower($hr['esi_applicable']) : 'auto';
        }
        if (! empty($hr['esic_no'])) {
            $patch['esic_no'] = $hr['esic_no'];   // applied only if the column exists (safeRow filters)
        }
        if (! empty($hr['pt_state'])) {
            $patch['pt_state'] = $hr['pt_state'];
        }
        if (! empty($hr['employment_type'])) {
            $patch['employment_type'] = $hr['employment_type']; // applied only if the column exists
        }

        return $patch;
    }

    /** Create a new employee (or update the matched one) from a record; apply HR fields. */
    private function injectOne(object $rec): array
    {
        $all = json_decode($rec->data ?: '{}', true) ?: [];
        $p = $all['personal'] ?? [];
        $ct = $all['contact'] ?? [];
        $st = $all['statutory'] ?? [];
        $bk = $all['bank'] ?? [];
        $hr = $all['hr'] ?? [];
        $tid = $rec->tenant_id;
        $companyId = $rec->company_id;
        $hrPatch = $this->hrPatch(is_array($hr) ? $hr : [], $tid);
        $candExtra = array_filter(['blood_group' => $p['blood_group'] ?? null, 'marital_status' => $p['marital'] ?? null, 'category' => $st['category'] ?? null, 'esic_no' => $st['esic'] ?? null, 'father' => $p['father_name'] ?? null, 'nationality' => $p['nationality'] ?? null, 'aadhaar' => $st['aadhaar'] ?? null, 'permanent_address' => $ct['permanent_address'] ?? null, 'emergency_name' => $ct['emergency_name'] ?? null, 'emergency_phone' => $ct['emergency_phone'] ?? null, 'dra_declared' => $st['dra_status'] ?? null, 'pcc_declared' => $st['pcc_status'] ?? null]);

        if ($rec->employee_id) {
            $patch = ['email_verified' => (bool) $rec->email_verified, 'mobile_verified' => (bool) $rec->mobile_verified, 'wa_verified' => (bool) $rec->wa_verified, 'docs_status' => 'approved', 'updated_at' => now()];
            foreach ([['dob', $p['dob'] ?? null], ['gender', $p['gender'] ?? null], ['address', $ct['current_address'] ?? null], ['pan', ! empty($st['pan']) ? strtoupper($st['pan']) : null], ['uan', $st['uan'] ?? null], ['bank_name', $bk['bank_name'] ?? null], ['bank_acc', $bk['acc_no'] ?? null], ['ifsc', ! empty($bk['ifsc']) ? strtoupper($bk['ifsc']) : null]] as $kv) {
                if (! empty($kv[1])) {
                    $patch[$kv[0]] = $kv[1];
                }
            }
            $patch = array_merge($patch, $candExtra, $hrPatch);
            DB::table('employees')->where('id', $rec->employee_id)->update(ApprovalService::safeRow('employees', $patch));
            $code = DB::table('employees')->where('id', $rec->employee_id)->value('emp_code');
            $empId = $rec->employee_id;
            $updated = true;
        } else {
            $n = (int) DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->count() + 1;
            $code = 'EMP'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            while (DB::table('employees')->where('emp_code', $code)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->exists()) {
                $n++;
                $code = 'EMP'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            }
            $base = ['uuid' => (string) Str::uuid(), 'tenant_id' => $tid, 'company_id' => $companyId, 'emp_code' => $code, 'name' => $p['full_name'] ?? $rec->name, 'dob' => $p['dob'] ?? null, 'gender' => $p['gender'] ?? null, 'email' => $rec->email, 'email_verified' => (bool) $rec->email_verified, 'mobile' => $rec->mobile, 'mobile_verified' => (bool) $rec->mobile_verified, 'whatsapp' => $rec->whatsapp, 'wa_verified' => (bool) $rec->wa_verified, 'address' => $ct['current_address'] ?? null, 'pan' => ! empty($st['pan']) ? strtoupper($st['pan']) : null, 'uan' => $st['uan'] ?? null, 'bank_name' => $bk['bank_name'] ?? null, 'bank_acc' => $bk['acc_no'] ?? null, 'ifsc' => ! empty($bk['ifsc']) ? strtoupper($bk['ifsc']) : null, 'docs_status' => 'approved', 'type' => 'office', 'salary_type' => 'only_salary', 'status' => 'active', 'doj' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()];
            $empId = DB::table('employees')->insertGetId(ApprovalService::safeRow('employees', array_merge($base, $candExtra, $hrPatch)));
            try {
                $this->ensureOnboardingTable();
                DB::table('onboarding')->insert(ApprovalService::safeRow('onboarding', ['tenant_id' => $tid, 'company_id' => $companyId, 'employee_id' => $empId, 'employee' => $p['full_name'] ?? $rec->name, 'joined_on' => now()->toDateString(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]));
            } catch (\Throwable $e) {
            }
            $updated = false;
        }
        DB::table('self_onboarding')->where('id', $rec->id)->update(['status' => 'injected', 'employee_id' => $empId, 'approved_at' => now(), 'link_disabled_at' => now(), 'updated_at' => now()]);

        return ['emp_code' => $code, 'employee_id' => $empId, 'updated' => $updated];
    }

    /* --------------------------------------------------------------- BULK import */

    private function norm($s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
    }

    public function hrBulkTemplate(Request $r)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $headers = ['Old Emp Code', 'Full Name', 'DOB (DD-MM-YYYY)', 'Gender', 'PAN', 'Aadhaar', 'Email', 'Mobile', 'WhatsApp', 'UAN', 'ESIC', 'Bank Name', 'Account No', 'IFSC', 'Blood Group', 'Marital', 'Category'];
        $sample = ['EMP-1042', 'Ravi Kumar', '12-03-1990', 'Male', 'ABCDE1234F', 'xxxx-xxxx-1234', 'ravi@email.com', '9800000001', '9800000001', '100123456789', '41-00-123456', 'HDFC Bank', '000123456789', 'HDFC0000123', 'O+', 'Married', 'General'];
        $csv = implode(',', $headers)."\n".implode(',', $sample)."\n";

        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="smartprs_bulk_import_template.csv"']);
    }

    public function hrBulkUpload(Request $r)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $this->ensureTables();
        $file = $r->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['ok' => false, 'error' => 'No file received.'], 422);
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            return response()->json(['ok' => false, 'error' => 'Please upload a CSV file (export your sheet as CSV).'], 422);
        }
        $tid = $r->user()->tenant_id ?? null;
        $companyId = $r->user()->company_id ?? null;
        $h = fopen($file->getRealPath(), 'r');
        if (! $h) {
            return response()->json(['ok' => false, 'error' => 'Could not read the file.'], 422);
        }
        $header = fgetcsv($h);
        if (! $header) {
            fclose($h);

            return response()->json(['ok' => false, 'error' => 'The file is empty.'], 422);
        }
        $idx = [];
        foreach ($header as $i => $col) {
            $idx[$this->norm($col)] = $i;
        }
        $get = function ($row, $keys) use ($idx) {
            foreach ((array) $keys as $k) {
                if (isset($idx[$k], $row[$idx[$k]])) {
                    $v = trim((string) $row[$idx[$k]]);
                    if ($v !== '') {
                        return $v;
                    }
                }
            }

            return null;
        };
        $total = 0;
        $matched = 0;
        $new = 0;
        $errors = 0;
        $preview = [];
        while (($row = fgetcsv($h)) !== false) {
            if (count(array_filter($row, fn ($x) => trim((string) $x) !== '')) === 0) {
                continue;
            }
            $name = $get($row, ['fullname', 'name', 'employeename']);
            if (! $name) {
                $errors++;
                continue;
            }
            $old = $get($row, ['oldempcode', 'empcode', 'employeecode', 'code']);
            $pan = $get($row, ['pan']);
            $email = $get($row, ['email']);
            $mobile = $get($row, ['mobile', 'phone']);
            $ifsc = $get($row, ['ifsc']);
            $snap = [
                'personal' => array_filter(['full_name' => $name, 'dob' => $get($row, ['dobddmmyyyy', 'dob', 'dateofbirth']), 'gender' => $get($row, ['gender']), 'blood_group' => $get($row, ['bloodgroup']), 'marital' => $get($row, ['marital', 'maritalstatus'])]),
                'contact' => [],
                'statutory' => array_filter(['pan' => $pan ? strtoupper($pan) : null, 'uan' => $get($row, ['uan']), 'aadhaar' => $get($row, ['aadhaar']), 'esic' => $get($row, ['esic']), 'category' => $get($row, ['category'])]),
                'bank' => array_filter(['acc_name' => $name, 'bank_name' => $get($row, ['bankname']), 'acc_no' => $get($row, ['accountno', 'accno', 'accountnumber']), 'ifsc' => $ifsc ? strtoupper($ifsc) : null]),
            ];
            $match = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->where(function ($w) use ($old, $pan, $email, $mobile) {
                if ($old) {
                    $w->orWhere('emp_code', $old);
                }
                if ($pan) {
                    $w->orWhere('pan', strtoupper($pan));
                }
                if ($email) {
                    $w->orWhere('email', $email);
                }
                if ($mobile) {
                    $w->orWhere('mobile', $mobile);
                }
            })->first();
            $empId = $match->id ?? null;
            $match ? $matched++ : $new++;
            $rec = self::issue(['tenant_id' => $tid, 'company_id' => $companyId, 'employee_id' => $empId, 'mode' => 'bulk', 'name' => $name, 'email' => $email, 'mobile' => $mobile, 'whatsapp' => $get($row, ['whatsapp']) ?: $mobile, 'data' => $snap]);
            DB::table('self_onboarding')->where('id', $rec->id)->update(['status' => 'submitted', 'progress' => 100, 'submitted_at' => now(), 'updated_at' => now()]);
            $total++;
            if (count($preview) < 10) {
                $preview[] = ['name' => $name, 'temp' => $rec->temp_emp_code, 'match' => $match->emp_code ?? null];
            }
        }
        fclose($h);

        return response()->json(['ok' => true, 'total' => $total, 'matched' => $matched, 'new' => $new, 'errors' => $errors, 'preview' => $preview]);
    }

    public function hrBulkCommit(Request $r)
    {
        if ($d = $this->hrDeny($r)) {
            return $d;
        }
        $tid = $r->user()->tenant_id ?? null;
        $rows = DB::table('self_onboarding')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('mode', 'bulk')->whereNull('deleted_at')->where('status', '!=', 'injected')->get();
        $created = 0;
        $updated = 0;
        foreach ($rows as $rec) {
            try {
                $res = $this->injectOne($rec);
                $res['updated'] ? $updated++ : $created++;
            } catch (\Throwable $e) {
            }
        }

        return response()->json(['ok' => true, 'created' => $created, 'updated' => $updated]);
    }

    /** Add optional master columns used by self-onboarding if they don't exist yet. */
    private function ensureEmployeeCols(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }
        foreach (['esic_no', 'employment_type', 'marital_status', 'father', 'nationality', 'aadhaar', 'permanent_address', 'emergency_name', 'emergency_phone', 'blood_group', 'category'] as $c) {
            if (! Schema::hasColumn('employees', $c)) {
                try {
                    Schema::table('employees', function (Blueprint $b) use ($c) {
                        $b->string($c)->nullable();
                    });
                } catch (\Throwable $e) {
                }
            }
        }
    }
}
