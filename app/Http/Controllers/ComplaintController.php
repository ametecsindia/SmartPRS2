<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 30 Sep 2026 (Ejaz) — "Raise a Complaint" (table posh_complaints, master type
 * 'posh'): the POSH Internal Committee, complaint evidence, and who may see what.
 *
 * VISIBILITY
 *   - The complainant always sees their own complaint.
 *   - POSH complaints: ONLY the Internal Committee members for that company
 *     (Admin / HR do not see them once a committee exists). Until a committee is
 *     set up, Admin / HR see them so nothing is orphaned — the screen nags.
 *   - General complaints: Admin / HR (as before).
 * The same rule governs the list, edit/delete, evidence and notifications.
 *
 * COMMITTEE  table posh_committee: one row per member login, optionally tied to
 * one company (blank = every company), with a role (Presiding Officer / Member /
 * External Member). Set by Admin / Super Admin.
 *
 * EVIDENCE  table complaint_evidence; files on the PRIVATE local disk
 * (complaint-evidence/{tenant}/{complaint}/…), streamed only after the same
 * visibility check. 10 MB each, 10 per complaint.
 */
class ComplaintController extends Controller
{
    private const MAX_FILES = 10;

    private const MIMES = 'jpg,jpeg,png,webp,gif,heic,pdf,doc,docx,txt,mp3,m4a,wav,ogg,mp4,mov';

    public static function ensure(): void
    {
        try {
            if (! Schema::hasTable('posh_committee')) {
                Schema::create('posh_committee', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->unsignedBigInteger('user_id')->index();
                    $t->string('company_name')->nullable();   // null = all companies
                    $t->string('role', 60)->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('complaint_evidence')) {
                Schema::create('complaint_evidence', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->unsignedBigInteger('complaint_id')->index();
                    $t->string('original_name');
                    $t->string('path');
                    $t->string('mime', 120)->nullable();
                    $t->unsignedBigInteger('size')->default(0);
                    $t->unsignedBigInteger('uploaded_by')->nullable();
                    $t->string('uploaded_by_name')->nullable();
                    $t->timestamps();
                });
            }
        } catch (\Throwable $e) {
            Log::warning('ComplaintController::ensure '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------ rules

    public static function isManager(Request $request): bool
    {
        return (bool) $request->user()?->hasAnyRole(['super_admin', 'admin', 'hr_manager']);
    }

    public static function hasCommittee(?int $tid): bool
    {
        self::ensure();

        return DB::table('posh_committee')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->exists();
    }

    /** Companies this user sits on the committee for: null = not a member, ['*'] = all. */
    public static function committeeCompanies(Request $request): ?array
    {
        self::ensure();
        $u = $request->user();
        $rows = DB::table('posh_committee')->where('user_id', $u->id)
            ->when($u->tenant_id, fn ($q) => $q->where('tenant_id', $u->tenant_id))->pluck('company_name')->all();
        if (! $rows) {
            return null;
        }

        return in_array(null, $rows, true) || in_array('', $rows, true) ? ['*'] : array_values(array_unique($rows));
    }

    public static function selfEmployee(Request $request): ?object
    {
        $u = $request->user();
        if (! empty($u->employee_id)) {
            $e = DB::table('employees')->where('id', $u->employee_id)->whereNull('deleted_at')->first();
            if ($e) {
                return $e;
            }
        }

        return DB::table('employees')->whereNull('deleted_at')->when($u->tenant_id, fn ($q) => $q->where('tenant_id', $u->tenant_id))
            ->where(fn ($q) => $q->where('email', $u->email)->orWhere('name', $u->name))->first();
    }

    private static function isPosh(object $row): bool
    {
        return strtolower((string) ($row->category ?? 'posh')) !== 'general';   // legacy rows (no category) were POSH
    }

    /** May this login act on (see / edit / add evidence to) this complaint? */
    public static function canAct(Request $request, object $row, bool $asComplainant = true): bool
    {
        if ($asComplainant) {
            $me = self::selfEmployee($request);
            if ($me && (int) ($row->employee_id ?? 0) === (int) $me->id) {
                return true;
            }
        }
        if (! self::isPosh($row)) {
            return self::isManager($request);
        }
        $tid = $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;
        if (! self::hasCommittee($tid)) {
            return self::isManager($request);   // no committee yet → Admin / HR hold it
        }
        $cc = self::committeeCompanies($request);

        return $cc !== null && ($cc === ['*'] || in_array((string) ($row->company_name ?? ''), $cc, true));
    }

    /**
     * Narrow the master list query to what this login may see. Returns
     * [canManage (shows Status / IC comment fields), selfEmployee].
     */
    public static function scopeList($q, Request $request): array
    {
        $mgr = self::isManager($request);
        $me = self::selfEmployee($request);
        $tid = $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;
        $committee = self::hasCommittee($tid);
        $cc = self::committeeCompanies($request);
        // 8 Oct 2026 (Ejaz) — a reporting manager sees (reads) GENERAL complaints raised by
        // their reporting line, so the bell notification opens to something. POSH never.
        $down = $me ? \App\Services\Hierarchy::downline((int) $me->id, $tid) : [];
        $q->where(function ($w) use ($me, $mgr, $committee, $cc, $down) {
            $w->where('employee_id', (int) ($me->id ?? -1));
            if ($down) {
                $w->orWhere(fn ($x) => $x->where('category', 'general')->whereIn('employee_id', $down));
            }
            if ($mgr) {
                $w->orWhere(fn ($x) => $x->where('category', 'general'));
            }
            if (! $committee && $mgr) {
                $w->orWhere(fn ($x) => $x->where('category', '<>', 'general')->orWhereNull('category'));
            } elseif ($cc !== null) {
                $w->orWhere(function ($x) use ($cc) {
                    $x->where(fn ($y) => $y->where('category', '<>', 'general')->orWhereNull('category'));
                    if ($cc !== ['*']) {
                        $x->whereIn('company_name', $cc);
                    }
                });
            }
        });

        return [$mgr || $cc !== null, $me];
    }

    /** Bell notification for a new complaint / ticket. */
    public static function notifyNew(string $type, int $id, array $row, ?int $submitterUserId): void
    {
        try {
            $tid = isset($row['tenant_id']) ? (int) $row['tenant_id'] : null;
            $emp = ! empty($row['employee_id']) ? DB::table('employees')->where('id', $row['employee_id'])->first() : null;
            if ($type === 'helpdesk') {
                if ($emp) {
                    NotificationService::notifyUpline($emp, 'request.helpdesk', 'Helpdesk ticket · '.($emp->name ?? ''),
                        trim(($row['subject'] ?? '').(! empty($row['category']) ? ' · '.$row['category'] : '')), 'helpdesk', $id, null, $submitterUserId);
                }

                return;
            }
            $posh = strtolower((string) ($row['category'] ?? '')) !== 'general';
            // 8 Oct 2026 (Ejaz) — a GENERAL complaint also reaches the employee's reporting
            // hierarchy (plus Admin / HR, via notifyUpline). POSH stays confidential below.
            if (! $posh && $emp) {
                NotificationService::notifyUpline($emp, 'complaint.general', 'New complaint #'.$id.' · '.($emp->name ?? ''),
                    (string) ($row['subject'] ?? ''), 'posh', $id, null, $submitterUserId);

                return;
            }
            if ($posh && self::hasCommittee($tid)) {
                $users = DB::table('posh_committee')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->where(fn ($q) => $q->whereNull('company_name')->orWhere('company_name', '')->orWhere('company_name', (string) ($row['company_name'] ?? '')))
                    ->pluck('user_id')->all();
                $title = 'New POSH complaint #'.$id;
                $body = 'Confidential — for the Internal Committee only. Open Raise a Complaint to view the details and evidence.';
            } else {
                $users = NotificationService::roleUserIds($tid, $posh ? ['admin'] : ['admin', 'hr_manager']);
                $title = $posh ? 'POSH complaint #'.$id.' received — set up the Internal Committee' : 'New complaint #'.$id.' · '.($emp->name ?? '');
                $body = $posh ? 'No POSH Internal Committee is set up yet, so this reaches Admin. Add the committee members from Raise a Complaint.' : (string) ($row['subject'] ?? '');
            }
            foreach (array_diff(array_unique(array_map('intval', $users)), [(int) $submitterUserId]) as $uid) {
                NotificationService::send(['tenant_id' => $tid, 'user_id' => $uid, 'employee_id' => $emp->id ?? null, 'kind' => $posh ? 'complaint.posh' : 'complaint.general',
                    'title' => $title, 'body' => $body, 'url' => 'posh', 'dedupe_key' => 'complaint:'.$id.':'.$uid]);
            }
        } catch (\Throwable $e) {
            Log::warning('ComplaintController::notifyNew '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------ committee

    /** GET /app/complaints/committee */
    public function committee(Request $request)
    {
        self::ensure();
        $u = $request->user();
        $tid = $u->tenant_id ? (int) $u->tenant_id : null;
        $canEdit = $u->hasAnyRole(['super_admin', 'admin']);
        abort_unless($canEdit || self::committeeCompanies($request) !== null, 403);
        $members = DB::table('posh_committee as p')->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->when($tid, fn ($q) => $q->where('p.tenant_id', $tid))->orderBy('p.id')
            ->get(['p.user_id', 'p.company_name', 'p.role', 'u.name', 'u.email']);

        return response()->json([
            'ok' => true,
            'canEdit' => $canEdit,
            'members' => $members,
            'users' => $canEdit ? DB::table('users')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->orderBy('name')->get(['id', 'name', 'email']) : [],
            'companies' => DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->orderBy('name')->pluck('name'),
        ]);
    }

    /** POST /app/complaints/committee {members:[{user_id, company, role}]} — replaces the list. */
    public function saveCommittee(Request $request)
    {
        self::ensure();
        abort_unless($request->user()->hasAnyRole(['super_admin', 'admin']), 403);
        $tid = $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;
        $valid = DB::table('users')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->pluck('id')->map(fn ($x) => (int) $x)->all();
        $rows = [];
        foreach ((array) $request->input('members', []) as $m) {
            $uid = (int) ($m['user_id'] ?? 0);
            if (! in_array($uid, $valid, true)) {
                continue;
            }
            $co = trim((string) ($m['company'] ?? ''));
            $rows[$uid.'|'.$co] = ['tenant_id' => $tid, 'user_id' => $uid, 'company_name' => $co === '' ? null : mb_substr($co, 0, 190),
                'role' => mb_substr(trim((string) ($m['role'] ?? 'Member')), 0, 60), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::transaction(function () use ($tid, $rows) {
            DB::table('posh_committee')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->delete();
            if ($rows) {
                DB::table('posh_committee')->insert(array_values($rows));
            }
        });
        \App\Services\Audit::record($tid, $request->user()->id, 'update', 'posh-committee', 0, ['members' => count($rows)], $request->ip());

        return response()->json(['ok' => true, 'message' => count($rows).' committee member'.(count($rows) === 1 ? '' : 's').' saved']);
    }

    // ------------------------------------------------------------ evidence

    private function complaint(Request $request, int $id): object
    {
        self::ensure();
        $tid = $request->user()->tenant_id;
        $row = DB::table('posh_complaints')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        abort_unless($row && self::canAct($request, $row), 404);   // 404, not 403: don't confirm it exists

        return $row;
    }

    /** GET /app/complaints/{id}/evidence */
    public function evidence(Request $request, int $id)
    {
        $row = $this->complaint($request, $id);
        $files = DB::table('complaint_evidence')->where('complaint_id', $row->id)->orderBy('id')
            ->get(['id', 'original_name', 'mime', 'size', 'uploaded_by_name', 'created_at']);

        return response()->json(['ok' => true, 'files' => $files, 'max' => self::MAX_FILES, 'subject' => $row->subject ?? '']);
    }

    /** POST /app/complaints/{id}/evidence  (multipart files[]) */
    public function upload(Request $request, int $id)
    {
        $row = $this->complaint($request, $id);
        $request->validate(['files' => ['required', 'array', 'max:'.self::MAX_FILES], 'files.*' => ['file', 'max:10240', 'mimes:'.self::MIMES]],
            ['files.*.max' => 'Each file must be 10 MB or smaller.', 'files.*.mimes' => 'Allowed: images, PDF, Word, text, audio or video files.']);
        $have = DB::table('complaint_evidence')->where('complaint_id', $row->id)->count();
        $files = $request->file('files');
        if ($have + count($files) > self::MAX_FILES) {
            return response()->json(['ok' => false, 'error' => 'A complaint can hold at most '.self::MAX_FILES.' files ('.$have.' already attached).'], 422);
        }
        $tid = $row->tenant_id ?? 0;
        $u = $request->user();
        foreach ($files as $f) {
            $name = Str::random(24).'.'.strtolower($f->getClientOriginalExtension() ?: $f->extension());
            $path = $f->storeAs('complaint-evidence/'.$tid.'/'.$row->id, $name, 'local');
            DB::table('complaint_evidence')->insert(['tenant_id' => $row->tenant_id ?? null, 'complaint_id' => $row->id,
                'original_name' => mb_substr($f->getClientOriginalName(), 0, 250), 'path' => $path, 'mime' => $f->getMimeType(), 'size' => $f->getSize(),
                'uploaded_by' => $u->id, 'uploaded_by_name' => $u->name ?? $u->email, 'created_at' => now(), 'updated_at' => now()]);
        }
        \App\Services\Audit::record($tid ?: null, $u->id, 'upload', 'complaint-evidence', (int) $row->id, ['files' => count($files)], $request->ip());

        return response()->json(['ok' => true, 'message' => count($files).' file'.(count($files) === 1 ? '' : 's').' attached']);
    }

    /** GET /app/complaints/evidence/{fid} — stream one file after the visibility check. */
    public function download(Request $request, int $fid)
    {
        self::ensure();
        $f = DB::table('complaint_evidence')->where('id', $fid)->first();
        abort_unless($f, 404);
        $this->complaint($request, (int) $f->complaint_id);
        abort_unless(Storage::disk('local')->exists($f->path), 404);

        return Storage::disk('local')->download($f->path, $f->original_name, ['Cache-Control' => 'private, no-store']);
    }
}
