<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Recruitment / ATS (rev 60). A robust manpower-requisition → pipeline → hire flow:
 *
 *   1. Raise a manpower REQUISITION (role, headcount, location, CTC band, target
 *      date, justification) → it starts as PENDING approval.
 *   2. An admin APPROVES (or rejects) it → an approved + open requisition becomes
 *      a recruitable position.
 *   3. CANDIDATES are added/imported and LINKED to a requisition; they move through
 *      stages (applied → screening → interview → offer → hired/rejected).
 *   4. INTERVIEWS are scheduled per candidate with panel, score, recommendation and
 *      written feedback.
 *   5. HIRING captures offered CTC, joining date, department, branch and reporting
 *      manager → creates a complete employee, increments the requisition's FILLED
 *      count and auto-closes it once fully filled.
 *
 * Tenant-scoped, admin/HR guarded, fail-soft, self-creating schema. Candidates live
 * in `recruitment`, requisitions in `job_openings`, interviews in `interviews`.
 */
class RecruitmentController extends Controller
{
    private const STAGES = ['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'];

    private const APPROVAL = ['draft', 'pending', 'approved', 'rejected'];

    private const INT_MODES = ['phone', 'video', 'in_person', 'online_test'];

    private const INT_STATUS = ['scheduled', 'completed', 'cancelled', 'no_show'];

    private const RECOMMEND = ['strong_hire', 'hire', 'hold', 'no_hire', 'strong_no_hire'];

    private function guard(Request $request)
    {
        return ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager']);
    }

    /** Stricter guard for approving/rejecting a requisition. */
    private function approverGuard(Request $request)
    {
        return ApprovalService::denyUnlessRole($request, ['admin']);
    }

    private function canApprove(Request $request): bool
    {
        try {
            $u = $request->user();

            return $u && ($u->hasRole('super_admin') || $u->hasAnyRole(['admin']));
        } catch (\Throwable $e) {
            return true;   // fail open — availability over strictness
        }
    }

    /** Add any missing columns to an existing table (idempotent). */
    private function addCols(string $table, array $cols): void
    {
        foreach ($cols as $c => $ty) {
            if (Schema::hasColumn($table, $c)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($c, $ty) {
                if ($ty === 'int') {
                    $t->integer($c)->nullable();
                } elseif ($ty === 'bigint') {
                    $t->unsignedBigInteger($c)->nullable();
                } elseif ($ty === 'date') {
                    $t->date($c)->nullable();
                } elseif ($ty === 'datetime') {
                    $t->dateTime($c)->nullable();
                } elseif ($ty === 'decimal') {
                    $t->decimal($c, 14, 2)->nullable();
                } elseif ($ty === 'text') {
                    $t->text($c)->nullable();
                } else {
                    $t->string($c)->nullable();
                }
            });
        }
    }

    /** Self-create + enrich all three tables. */
    private function ensure(): void
    {
        if (! Schema::hasTable('recruitment')) {
            Schema::create('recruitment', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->timestamps();
            });
        }
        $this->addCols('recruitment', [
            'name' => 'string', 'company_name' => 'string', 'company_id' => 'int', 'position' => 'string',
            'source' => 'string', 'stage' => 'string', 'mobile' => 'string', 'email' => 'string',
            'notes' => 'text', 'rating' => 'int', 'applied_on' => 'date',
            // rev 60 — link + offer
            'job_id' => 'bigint', 'offered_ctc' => 'decimal', 'offer_doj' => 'date',
            'offer_status' => 'string', 'reject_reason' => 'string',
            // rev 62 — portal profile + talent pool
            'location' => 'string', 'experience_years' => 'decimal', 'current_company' => 'string',
            'current_designation' => 'string', 'current_ctc' => 'decimal', 'expected_ctc' => 'decimal',
            'skills' => 'text', 'resume_url' => 'string', 'pool' => 'int',
            // rev 63 — interview outcome + final offer
            'proposed_ctc' => 'decimal', 'interview_remarks' => 'text',
            'offer_incentive' => 'decimal', 'joining_bonus' => 'decimal', 'offer_notes' => 'text',
        ]);

        if (! Schema::hasTable('job_openings')) {
            Schema::create('job_openings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->string('title');
                $t->string('department')->nullable();
                $t->unsignedInteger('openings')->default(1);
                $t->string('status')->default('open');
                $t->text('description')->nullable();
                $t->timestamps();
            });
        }
        $this->addCols('job_openings', [
            // rev 60 — requisition workflow
            'req_code' => 'string', 'location' => 'string', 'ctc_min' => 'decimal', 'ctc_max' => 'decimal',
            'priority' => 'string', 'justification' => 'text', 'target_date' => 'date',
            'requested_by' => 'string', 'approval_status' => 'string',
            'approved_by' => 'string', 'approved_at' => 'datetime', 'reject_reason' => 'string',
            'filled' => 'int',
            // rev 64 — on-roll employee vs off-roll commission agent
            'engagement' => 'string',
        ]);

        if (! Schema::hasTable('interviews')) {
            Schema::create('interviews', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('candidate_id')->index();
                $t->unsignedInteger('round')->default(1);
                $t->string('round_label')->nullable();
                $t->dateTime('scheduled_at')->nullable();
                $t->string('mode')->nullable();
                $t->string('panel')->nullable();
                $t->string('status')->default('scheduled');
                $t->integer('score')->nullable();
                $t->string('recommendation')->nullable();
                $t->text('feedback')->nullable();
                $t->timestamps();
            });
        }
    }

    /** Legacy rows have no approval_status — treat them as already approved. */
    private function apprOf($row): string
    {
        $a = $row->approval_status ?? null;

        return in_array($a, self::APPROVAL, true) ? $a : 'approved';
    }

    /** Normalise a recruitment row (array) into the candidate shape used by the UI. */
    private function candidateRow(array $a, $companies): array
    {
        return [
            'id' => $a['id'], 'name' => $a['name'] ?? '', 'position' => $a['position'] ?? '',
            'company' => $a['company_name'] ?? (! empty($a['company_id']) ? ($companies[$a['company_id']] ?? '') : ''),
            'company_name' => $a['company_name'] ?? '', 'company_id' => isset($a['company_id']) ? (int) $a['company_id'] : null,
            'source' => $a['source'] ?? '', 'stage' => $a['stage'] ?? 'applied', 'mobile' => $a['mobile'] ?? '',
            'email' => $a['email'] ?? '', 'rating' => (int) ($a['rating'] ?? 0), 'notes' => $a['notes'] ?? '',
            'job_id' => isset($a['job_id']) ? (int) $a['job_id'] : null,
            'offered_ctc' => $a['offered_ctc'] ?? null, 'offer_doj' => $a['offer_doj'] ?? null,
            'offer_status' => $a['offer_status'] ?? null, 'reject_reason' => $a['reject_reason'] ?? null,
            'location' => $a['location'] ?? '', 'experience_years' => $a['experience_years'] ?? null,
            'current_company' => $a['current_company'] ?? '', 'current_designation' => $a['current_designation'] ?? '',
            'current_ctc' => $a['current_ctc'] ?? null, 'expected_ctc' => $a['expected_ctc'] ?? null,
            'skills' => $a['skills'] ?? '', 'resume_url' => $a['resume_url'] ?? '',
            'pool' => (int) ($a['pool'] ?? 0),
            'proposed_ctc' => $a['proposed_ctc'] ?? null, 'interview_remarks' => $a['interview_remarks'] ?? '',
            'offer_incentive' => $a['offer_incentive'] ?? null, 'joining_bonus' => $a['joining_bonus'] ?? null,
            'offer_notes' => $a['offer_notes'] ?? '',
        ];
    }

    public function board(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $companies = DB::table('companies')->pluck('name', 'id');

            // ---- Interview summary per candidate -----------------------------
            $ivAll = DB::table('interviews')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->orderBy('id')->get();
            $ivByCand = [];
            foreach ($ivAll as $iv) {
                $cid = (int) $iv->candidate_id;
                if (! isset($ivByCand[$cid])) {
                    $ivByCand[$cid] = ['count' => 0, 'done' => 0, 'lastRec' => '', 'avg' => null, 'scores' => []];
                }
                $ivByCand[$cid]['count']++;
                if (($iv->status ?? '') === 'completed') {
                    $ivByCand[$cid]['done']++;
                    if ($iv->recommendation) {
                        $ivByCand[$cid]['lastRec'] = $iv->recommendation;
                    }
                    if ($iv->score !== null && $iv->score !== '') {
                        $ivByCand[$cid]['scores'][] = (float) $iv->score;
                    }
                }
            }
            foreach ($ivByCand as $cid => &$s) {
                $s['avg'] = count($s['scores']) ? round(array_sum($s['scores']) / count($s['scores']), 1) : null;
                unset($s['scores']);
            }
            unset($s);

            // ---- Candidates (pipeline only; talent-pool entries excluded) -----
            $cands = DB::table('recruitment')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where(fn ($w) => $w->where('pool', '<>', 1)->orWhereNull('pool'))
                ->orderByDesc('id')->limit(2000)->get()
                ->map(function ($r) use ($companies, $ivByCand) {
                    $a = (array) $r;
                    $stage = in_array($a['stage'] ?? '', self::STAGES, true) ? $a['stage'] : 'applied';
                    $iv = $ivByCand[(int) $a['id']] ?? ['count' => 0, 'done' => 0, 'lastRec' => '', 'avg' => null];

                    return $this->candidateRow($a, $companies) + [
                        'stage' => $stage,
                        'iv_count' => $iv['count'], 'iv_done' => $iv['done'], 'iv_rec' => $iv['lastRec'], 'iv_avg' => $iv['avg'],
                    ];
                })->values();

            $byStage = [];
            foreach (self::STAGES as $s) {
                $byStage[$s] = $cands->where('stage', $s)->values();
            }

            // ---- Requisitions (job_openings) ---------------------------------
            $hiredByJob = $cands->where('stage', 'hired')->groupBy('job_id');
            $reqs = DB::table('job_openings')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->orderByDesc('id')->get()
                ->map(function ($j) use ($companies, $hiredByJob) {
                    $appr = $this->apprOf($j);
                    $openings = (int) ($j->openings ?? 1);
                    $filled = (int) ($j->filled ?? 0);
                    // Reconcile filled with actual hired candidates linked to this req.
                    $hiredHere = isset($hiredByJob[$j->id]) ? count($hiredByJob[$j->id]) : 0;
                    $filled = max($filled, $hiredHere);
                    $recruitable = $appr === 'approved' && ($j->status ?? 'open') === 'open' && $filled < $openings;

                    return [
                        'id' => $j->id, 'req_code' => $j->req_code ?? '', 'title' => $j->title,
                        'department' => $j->department, 'location' => $j->location ?? '',
                        'openings' => $openings, 'filled' => $filled, 'remaining' => max(0, $openings - $filled),
                        'status' => $j->status ?? 'open', 'approval_status' => $appr,
                        'priority' => $j->priority ?? 'normal',
                        'ctc_min' => $j->ctc_min ?? null, 'ctc_max' => $j->ctc_max ?? null,
                        'target_date' => $j->target_date ?? null, 'requested_by' => $j->requested_by ?? '',
                        'approved_by' => $j->approved_by ?? '', 'reject_reason' => $j->reject_reason ?? '',
                        'justification' => $j->justification ?? '', 'description' => $j->description ?? '',
                        'company' => $j->company_id ? ($companies[$j->company_id] ?? '') : '',
                        'engagement' => (($j->engagement ?? 'on_roll') === 'off_roll') ? 'off_roll' : 'on_roll',
                        'recruitable' => $recruitable,
                    ];
                })->values();

            // ---- Masters for the hire dialog & pickers -----------------------
            $designations = Schema::hasTable('designations')
                ? DB::table('designations')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()
                : [];
            $departments = Schema::hasTable('departments')
                ? DB::table('departments')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()
                : [];
            $branches = Schema::hasTable('branches')
                ? DB::table('branches')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values()
                : [];
            $managers = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->orderBy('name')->limit(2000)
                ->get(['id', 'name', 'emp_code'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name.($e->emp_code ? ' ('.$e->emp_code.')' : '')])->values();
            $companyList = DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values();

            return response()->json([
                'stages' => self::STAGES,
                'byStage' => $byStage,
                'jobs' => $reqs,
                'modes' => self::INT_MODES,
                'intStatus' => self::INT_STATUS,
                'recommend' => self::RECOMMEND,
                'priorities' => ['low', 'normal', 'high', 'urgent'],
                'canApprove' => $this->canApprove($request),
                'designations' => $designations,
                'departments' => $departments,
                'branches' => $branches,
                'managers' => $managers,
                'companiesFull' => $companyList,
                'companies' => $companyList->pluck('name')->values(),
                'stats' => [
                    'candidates' => $cands->count(),
                    'inPipeline' => $cands->whereNotIn('stage', ['hired', 'rejected'])->count(),
                    'hired' => $cands->where('stage', 'hired')->count(),
                    'openJobs' => $reqs->where('recruitable', true)->count(),
                    'pendingReqs' => $reqs->where('approval_status', 'pending')->count(),
                    'poolCount' => (int) DB::table('recruitment')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('pool', 1)->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['byStage' => [], 'error' => $e->getMessage()]);
        }
    }

    public function saveCandidate(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
            $v = $request->validate([
                'id' => ['nullable', 'integer'],
                'name' => ['required', 'string', 'max:191'],
                'position' => ['nullable', 'string', 'max:191'],
                'company_name' => ['nullable', 'string'],
                'job_id' => ['nullable', 'integer'],
                'source' => ['nullable', 'string', 'max:120'],
                'stage' => ['nullable', 'in:'.implode(',', self::STAGES)],
                'mobile' => ['nullable', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:191'],
                'rating' => ['nullable', 'integer', 'min:0', 'max:5'],
                'notes' => ['nullable', 'string'],
                'location' => ['nullable', 'string', 'max:191'],
                'experience_years' => ['nullable', 'numeric', 'min:0', 'max:80'],
                'current_company' => ['nullable', 'string', 'max:191'],
                'current_designation' => ['nullable', 'string', 'max:191'],
                'current_ctc' => ['nullable', 'numeric', 'min:0'],
                'expected_ctc' => ['nullable', 'numeric', 'min:0'],
                'skills' => ['nullable', 'string'],
                'resume_url' => ['nullable', 'string', 'max:500'],
            ]);
            $v = AppDataController::stripHtmlDeep($v); // rev172 (H3) — candidate free-text can't inject script when rendered in the SPA
            $row = ['tenant_id' => $tid, 'name' => $v['name'], 'position' => $v['position'] ?? null,
                'company_name' => $v['company_name'] ?? null, 'source' => $v['source'] ?? null,
                'stage' => $v['stage'] ?? 'applied', 'mobile' => $v['mobile'] ?? null, 'email' => $v['email'] ?? null,
                'rating' => $v['rating'] ?? 0, 'notes' => $v['notes'] ?? null,
                'location' => $v['location'] ?? null, 'experience_years' => $v['experience_years'] ?? null,
                'current_company' => $v['current_company'] ?? null, 'current_designation' => $v['current_designation'] ?? null,
                'current_ctc' => $v['current_ctc'] ?? null, 'expected_ctc' => $v['expected_ctc'] ?? null,
                'skills' => $v['skills'] ?? null, 'resume_url' => $v['resume_url'] ?? null, 'updated_at' => now()];

            // Link to a requisition; inherit its title/department/company if blank.
            if (! empty($v['job_id'])) {
                $job = DB::table('job_openings')->where('id', $v['job_id'])->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
                if ($job) {
                    $row['job_id'] = $job->id;
                    if (empty($row['position'])) {
                        $row['position'] = $job->title;
                    }
                    if (empty($row['company_name']) && ! empty($job->company_id)) {
                        $row['company_id'] = $job->company_id;
                    }
                }
            }
            if (! empty($v['company_name'])) {
                $cid = DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('name', $v['company_name'])->value('id');
                if ($cid) {
                    $row['company_id'] = $cid;
                }
            }
            $row = ApprovalService::safeRow('recruitment', $row);
            if (! empty($v['id'])) {
                DB::table('recruitment')->where('id', $v['id'])->when($tid, fn ($q) => $q->where('tenant_id', $tid))->update($row);
            } else {
                $row['created_at'] = now();
                $row['applied_on'] = $row['applied_on'] ?? now()->toDateString();
                DB::table('recruitment')->insert(ApprovalService::safeRow('recruitment', $row));
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function moveStage(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $v = $request->validate([
                'stage' => ['required', 'in:'.implode(',', self::STAGES)],
                'reject_reason' => ['nullable', 'string', 'max:500'],
            ]);
            $tid = $request->user()->tenant_id;
            $upd = ['stage' => $v['stage'], 'updated_at' => now()];
            if ($v['stage'] === 'rejected' && ! empty($v['reject_reason'])) {
                $upd['reject_reason'] = $v['reject_reason'];
            }
            DB::table('recruitment')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->update(ApprovalService::safeRow('recruitment', $upd));

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function deleteCandidate(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            DB::table('recruitment')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->delete();
            if (Schema::hasTable('interviews')) {
                DB::table('interviews')->where('candidate_id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->delete();
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- Interviews ----------------------------------------------------------

    public function interviews(Request $request, int $candidateId)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $rows = DB::table('interviews')->where('candidate_id', $candidateId)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->orderBy('round')->orderBy('id')
                ->get()->map(fn ($r) => [
                    'id' => $r->id, 'round' => (int) $r->round, 'round_label' => $r->round_label ?? '',
                    'scheduled_at' => $r->scheduled_at, 'mode' => $r->mode ?? '', 'panel' => $r->panel ?? '',
                    'status' => $r->status ?? 'scheduled', 'score' => $r->score,
                    'recommendation' => $r->recommendation ?? '', 'feedback' => $r->feedback ?? '',
                ])->values();

            return response()->json(['ok' => true, 'interviews' => $rows]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function saveInterview(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
            $v = $request->validate([
                'id' => ['nullable', 'integer'],
                'candidate_id' => ['required', 'integer'],
                'round' => ['nullable', 'integer', 'min:1', 'max:20'],
                'round_label' => ['nullable', 'string', 'max:120'],
                'scheduled_at' => ['nullable', 'string', 'max:40'],
                'mode' => ['nullable', 'in:'.implode(',', self::INT_MODES)],
                'panel' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', 'in:'.implode(',', self::INT_STATUS)],
                'score' => ['nullable', 'numeric', 'min:0', 'max:10'],
                'recommendation' => ['nullable', 'in:'.implode(',', self::RECOMMEND)],
                'feedback' => ['nullable', 'string'],
            ]);
            $row = [
                'tenant_id' => $tid, 'candidate_id' => $v['candidate_id'],
                'round' => $v['round'] ?? 1, 'round_label' => $v['round_label'] ?? null,
                'scheduled_at' => ! empty($v['scheduled_at']) ? $v['scheduled_at'] : null,
                'mode' => $v['mode'] ?? null, 'panel' => $v['panel'] ?? null,
                'status' => $v['status'] ?? 'scheduled',
                'score' => isset($v['score']) && $v['score'] !== '' ? $v['score'] : null,
                'recommendation' => $v['recommendation'] ?? null, 'feedback' => $v['feedback'] ?? null,
                'updated_at' => now(),
            ];
            $row = ApprovalService::safeRow('interviews', $row);
            if (! empty($v['id'])) {
                DB::table('interviews')->where('id', $v['id'])->when($tid, fn ($q) => $q->where('tenant_id', $tid))->update($row);
            } else {
                $row['created_at'] = now();
                DB::table('interviews')->insert($row);
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function deleteInterview(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            DB::table('interviews')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->delete();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- Hire (richer) -------------------------------------------------------

    /** Hire: create a complete employee from the candidate + mark them hired. */
    public function convertToEmployee(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
            $v = $request->validate([
                'offered_ctc' => ['nullable', 'numeric', 'min:0'],
                'doj' => ['nullable', 'date'],
                'designation_id' => ['nullable', 'integer'],
                'department_id' => ['nullable', 'integer'],
                'branch_id' => ['nullable', 'integer'],
                'company_id' => ['nullable', 'integer'],
                'reporting_manager_id' => ['nullable', 'integer'],
                'salary_type' => ['nullable', 'string', 'max:40'],
                'type' => ['nullable', 'string', 'max:40'],
                'engagement' => ['nullable', 'in:on_roll,off_roll'],
                'vendor' => ['nullable', 'string', 'max:191'],
                'payout_type' => ['nullable', 'in:per_visit,fixed,percent'],
                'rate' => ['nullable', 'numeric', 'min:0'],
                'dra' => ['nullable', 'string', 'max:120'],
                'pcc' => ['nullable', 'string', 'max:120'],
            ]);
            $c = DB::table('recruitment')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $c) {
                return response()->json(['ok' => false, 'error' => 'Candidate not found'], 404);
            }
            // Off-roll / commission-only agent — no payroll employee.
            if (($v['engagement'] ?? 'on_roll') === 'off_roll') {
                $r = self::createOffrollFromCandidate($tid, $c, $v);

                return response()->json(['ok' => true, 'offroll' => true, 'created' => $r['created'],
                    'message' => $r['created'] ? ($c->name.' engaged as an off-roll agent (commission).') : ($c->name.' is already an off-roll agent.')]);
            }
            $res = self::createEmployeeFromCandidate($tid, $c, $v);
            $msg = $res['created'] ? ($c->name.' hired as employee '.$res['code'].'.') : ('Already an employee ('.$res['code'].').');

            return response()->json(['ok' => true, 'code' => $res['code'], 'created' => $res['created'], 'message' => $msg]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Shared hire routine (no Request) so both the in-app Hire dialog and the public
     * offer-acceptance link create the SAME complete employee. Idempotent: if an
     * employee with this name already exists, it just marks the candidate hired.
     * $opts may carry offered_ctc, doj, designation_id, department_id, branch_id,
     * company_id, reporting_manager_id, salary_type, type.
     */
    public static function createEmployeeFromCandidate($tid, $c, array $opts = []): array
    {
        $tid = $tid ?: DB::table('tenants')->value('id');
        $offerUpd = ApprovalService::safeRow('recruitment', [
            'offered_ctc' => $opts['offered_ctc'] ?? ($c->offered_ctc ?? null),
            'offer_doj' => $opts['doj'] ?? ($c->offer_doj ?? null),
            'offer_status' => 'accepted', 'stage' => 'hired', 'updated_at' => now(),
        ]);

        $existing = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->where('name', $c->name)->whereNull('deleted_at')->value('emp_code');
        if ($existing) {
            DB::table('recruitment')->where('id', $c->id)->update($offerUpd);
            self::reconcileFillStatic($tid, $c->job_id ?? null);
            self::ensureOnboarding($tid, $c);

            return ['code' => $existing, 'created' => false];
        }

        // SEAT LIMIT (rev 75): hiring creates a NEW employee — it must fit within
        // the tenant's subscribed seats (active on-roll count). Callers surface
        // this as their normal JSON error (convertToEmployee / OfferAccept both
        // catch Throwable), so the HR user sees the friendly upgrade message.
        $seat = \App\Services\SubscriptionService::canAddEmployees($tid ? (int) $tid : null, 1);
        if (! $seat['ok']) {
            throw new \RuntimeException($seat['error']);
        }

        $companyId = $opts['company_id'] ?? ($c->company_id ?: DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->value('id'));

        $desigId = $opts['designation_id'] ?? null;
        if (! $desigId && ! empty($c->position) && Schema::hasTable('designations')) {
            $desigId = DB::table('designations')->where('name', $c->position)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->value('id');
            if (! $desigId) {
                $desigId = DB::table('designations')->insertGetId(ApprovalService::safeRow('designations', [
                    'tenant_id' => $tid, 'name' => $c->position, 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
        }

        $n = (int) DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->count() + 1;
        $code = 'EMP'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        while (DB::table('employees')->where('emp_code', $code)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->exists()) {
            $n++;
            $code = 'EMP'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        }

        DB::table('employees')->insert(ApprovalService::safeRow('employees', [
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tid, 'company_id' => $companyId,
            'emp_code' => $code, 'name' => $c->name, 'email' => $c->email, 'mobile' => $c->mobile,
            'designation_id' => $desigId, 'department_id' => $opts['department_id'] ?? null,
            'branch_id' => $opts['branch_id'] ?? null, 'reporting_manager_id' => $opts['reporting_manager_id'] ?? null,
            'salary_type' => $opts['salary_type'] ?? 'only_salary', 'type' => $opts['type'] ?? 'office',
            'status' => 'active', 'ctc' => $opts['offered_ctc'] ?? ($c->offered_ctc ?? 0),
            'doj' => ! empty($opts['doj']) ? $opts['doj'] : ($c->offer_doj ?: now()->toDateString()),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        DB::table('recruitment')->where('id', $c->id)->update($offerUpd);
        self::reconcileFillStatic($tid, $c->job_id ?? null);
        self::ensureOnboarding($tid, $c);

        return ['code' => $code, 'created' => true];
    }

    /**
     * Engage a candidate as an OFF-ROLL / commission-only field agent (no payroll
     * employee). Creates an offroll_agents row. Idempotent by name within tenant.
     * $opts: vendor, payout_type (per_visit|fixed|percent), rate, dra, pcc.
     */
    public static function createOffrollFromCandidate($tid, $c, array $opts = []): array
    {
        $tid = $tid ?: DB::table('tenants')->value('id');
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
        $companyName = $c->company_name ?: ($c->company_id ? DB::table('companies')->where('id', $c->company_id)->value('name') : null);
        $candUpd = ApprovalService::safeRow('recruitment', ['stage' => 'hired', 'offer_status' => 'accepted', 'updated_at' => now()]);

        $exists = DB::table('offroll_agents')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('name', $c->name)->exists();
        if ($exists) {
            DB::table('recruitment')->where('id', $c->id)->update($candUpd);
            self::reconcileFillStatic($tid, $c->job_id ?? null);

            return ['created' => false];
        }
        DB::table('offroll_agents')->insert(ApprovalService::safeRow('offroll_agents', [
            'tenant_id' => $tid, 'name' => $c->name, 'company_name' => $companyName,
            'vendor' => $opts['vendor'] ?? null, 'mobile' => $c->mobile,
            'payout_type' => $opts['payout_type'] ?? 'per_visit', 'rate' => $opts['rate'] ?? null,
            'dra' => $opts['dra'] ?? null, 'pcc' => $opts['pcc'] ?? null, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]));
        DB::table('recruitment')->where('id', $c->id)->update($candUpd);
        self::reconcileFillStatic($tid, $c->job_id ?? null);

        return ['created' => true];
    }

    /** Partial update of a candidate (interview outcome / final offer) by id. */
    public function patchCandidate(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $v = $request->validate([
                'interview_remarks' => ['nullable', 'string'],
                'expected_ctc' => ['nullable', 'numeric', 'min:0'],
                'proposed_ctc' => ['nullable', 'numeric', 'min:0'],
                'offered_ctc' => ['nullable', 'numeric', 'min:0'],
                'offer_incentive' => ['nullable', 'numeric', 'min:0'],
                'joining_bonus' => ['nullable', 'numeric', 'min:0'],
                'offer_doj' => ['nullable', 'date'],
                'offer_notes' => ['nullable', 'string'],
            ]);
            $upd = [];
            foreach ($v as $k => $val) {
                $upd[$k] = ($val === '' ? null : $val);
            }
            if (! $upd) {
                return response()->json(['ok' => true]);
            }
            $upd['updated_at'] = now();
            DB::table('recruitment')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->update(ApprovalService::safeRow('recruitment', $upd));

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Start onboarding for a hired candidate (creates the onboarding record). */
    public function startOnboarding(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $c = DB::table('recruitment')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $c) {
                return response()->json(['ok' => false, 'error' => 'Candidate not found'], 404);
            }
            if (! Schema::hasTable('onboarding')) {
                return response()->json(['ok' => false, 'error' => 'Onboarding is not set up yet — open the Onboarding screen once.'], 422);
            }
            $made = self::ensureOnboarding($tid, $c);

            return response()->json(['ok' => true, 'message' => ($made ? 'Onboarding started for ' : 'Onboarding already exists for ').$c->name.'.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Create the onboarding record for a candidate if absent. Returns true if created. */
    private static function ensureOnboarding($tid, $c): bool
    {
        if (! Schema::hasTable('onboarding')) {
            return false;
        }
        $exists = DB::table('onboarding')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('employee', $c->name)->exists();
        if ($exists) {
            return false;
        }
        $emp = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('name', $c->name)->whereNull('deleted_at')->first();
        $companyName = $c->company_name ?: ($c->company_id ? DB::table('companies')->where('id', $c->company_id)->value('name') : null);
        DB::table('onboarding')->insert(ApprovalService::safeRow('onboarding', [
            'tenant_id' => $tid, 'company_id' => $c->company_id ?: ($emp->company_id ?? null),
            'employee_id' => $emp->id ?? null, 'employee' => $c->name, 'company_name' => $companyName,
            'stage' => 'Documents', 'joined_on' => $c->offer_doj ?: now()->toDateString(),
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return true;
    }

    /** Recompute a requisition's filled count from hired candidates + auto-close. */
    private static function reconcileFillStatic($tid, $jobId): void
    {
        if (empty($jobId)) {
            return;
        }
        try {
            $job = DB::table('job_openings')->where('id', $jobId)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $job) {
                return;
            }
            $hired = (int) DB::table('recruitment')->where('job_id', $jobId)->where('stage', 'hired')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->count();
            $upd = ['filled' => $hired, 'updated_at' => now()];
            if ($hired >= (int) ($job->openings ?? 1) && ($job->status ?? 'open') === 'open') {
                $upd['status'] = 'closed';
            }
            DB::table('job_openings')->where('id', $jobId)->update(ApprovalService::safeRow('job_openings', $upd));
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /** Download a CSV template for bulk candidate import. */
    public function template(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        // Standard SmartPRS recruitment import format (same columns shown in the Talent Pool).
        $csv = "Name,Email,Mobile,Location,Experience (Years),Current Company,Current Designation,Current CTC,Expected CTC,Skills,Resume URL,Source,Position\r\n"
            .'"John Doe","john@example.com","+919999999999","Mumbai","6","ABC Recovery Pvt Ltd","Senior Collections Officer","550000","700000","Field collection, negotiation, FOIR","https://example.com/resume/john","Naukri","Field Recovery Officer"'."\r\n"
            .'"Jane Smith","jane@example.com","+918888888888","Pune","3","XYZ ARC","Tele-caller","350000","450000","Tele-calling, soft skills, CRM","","LinkedIn","Tele-caller"'."\r\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="candidates-import-template.csv"',
        ]);
    }

    /** Bulk-import candidates from a CSV upload (export your Excel as CSV). */
    public function importCandidates(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);
            $this->ensure();
            $tid = $request->user()->tenant_id ?? DB::table('tenants')->value('id');

            $lines = file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (! $lines || count($lines) < 2) {
                return response()->json(['ok' => false, 'error' => 'The CSV is empty or has no data rows (needs a header row + at least one candidate).'], 422);
            }
            $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
            $idx = fn ($name) => array_search($name, $header, true);
            $nameCol = $idx('name');
            if ($nameCol === false) {
                return response()->json(['ok' => false, 'error' => 'CSV must have a "name" column. Download the template for the right headers.'], 422);
            }
            $companyByName = DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->pluck('id', 'name');
            $jobByTitle = DB::table('job_openings')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->pluck('id', 'title');
            $valid = self::STAGES;

            $count = 0;
            $skipped = 0;
            foreach ($lines as $line) {
                $cells = str_getcsv($line);
                $get = function ($key) use ($idx, $cells) {
                    $i = $idx($key);

                    return ($i !== false && isset($cells[$i])) ? trim((string) $cells[$i]) : '';
                };
                $name = trim((string) ($cells[$nameCol] ?? ''));
                if ($name === '') {
                    $skipped++;

                    continue;
                }
                $stage = strtolower($get('stage'));
                $companyName = $get('company');
                $jobTitle = $get('job') ?: $get('requisition');
                $row = [
                    'tenant_id' => $tid, 'name' => $name, 'position' => $get('position') ?: null,
                    'company_name' => $companyName ?: null, 'source' => $get('source') ?: null,
                    'stage' => in_array($stage, $valid, true) ? $stage : 'applied',
                    'mobile' => $get('mobile') ?: null, 'email' => $get('email') ?: null,
                    'rating' => is_numeric($get('rating')) ? max(0, min(5, (int) $get('rating'))) : 0,
                    'notes' => $get('notes') ?: null, 'applied_on' => now()->toDateString(),
                    'created_at' => now(), 'updated_at' => now(),
                ];
                if ($companyName && isset($companyByName[$companyName])) {
                    $row['company_id'] = $companyByName[$companyName];
                }
                if ($jobTitle && isset($jobByTitle[$jobTitle])) {
                    $row['job_id'] = $jobByTitle[$jobTitle];
                }
                DB::table('recruitment')->insert(ApprovalService::safeRow('recruitment', $row));
                $count++;
            }

            return response()->json(['ok' => true, 'count' => $count, 'skipped' => $skipped,
                'message' => 'Imported '.$count.' candidate(s)'.($skipped ? ' ('.$skipped.' skipped — no name)' : '').'.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- Job-portal import (xlsx/csv parsed in-browser → JSON rows) ----------

    /** Map a wide range of job-portal column names → our candidate fields. */
    private function importAliases(): array
    {
        return [
            'name' => ['name', 'candidate name', 'full name', 'applicant name', 'candidate', 'candidate_name'],
            'email' => ['email', 'email id', 'email address', 'e-mail', 'emailid', 'email_id'],
            'mobile' => ['mobile', 'phone', 'phone number', 'contact', 'contact number', 'mobile number', 'mobile no', 'phone no', 'contact no', 'mobile_no'],
            'location' => ['location', 'current location', 'city', 'present location', 'base location', 'current city'],
            'experience_years' => ['experience', 'total experience', 'exp', 'years of experience', 'total exp', 'experience (years)', 'work experience', 'total experience (yrs)', 'experience years'],
            'current_company' => ['current company', 'company', 'present company', 'current employer', 'employer', 'current organization', 'organisation'],
            'current_designation' => ['designation', 'current designation', 'current role', 'job title', 'current job title', 'title', 'role', 'current title'],
            'current_ctc' => ['current ctc', 'ctc', 'present ctc', 'current salary', 'annual ctc', 'current ctc (lpa)', 'current ctc (inr)'],
            'expected_ctc' => ['expected ctc', 'expected salary', 'expected', 'expected ctc (lpa)', 'expected ctc (inr)'],
            'skills' => ['skills', 'key skills', 'skill', 'key skill', 'skill set', 'key_skills'],
            'resume_url' => ['resume', 'resume url', 'resume link', 'profile', 'profile url', 'cv', 'resume_url', 'profile link'],
            'position' => ['position', 'applied for', 'role applied', 'job', 'requisition', 'position applied for', 'applied position'],
            'source' => ['source', 'portal', 'job portal', 'channel'],
            'notes' => ['notes', 'remarks', 'comments', 'resume headline', 'summary', 'about', 'headline'],
        ];
    }

    private function numOnly($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = preg_replace('/[^0-9.]/', '', (string) $v);

        return $s === '' ? null : (float) $s;
    }

    /**
     * Import candidates from in-browser-parsed rows (each row = assoc array keyed
     * by the original portal header). Lands them in the TALENT POOL by default,
     * or into a requisition pipeline when job_id is given. De-dupes on email/mobile.
     */
    public function importRows(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
            $data = $request->validate([
                'rows' => ['required', 'array', 'min:1', 'max:20000'],
                'pool' => ['nullable', 'boolean'],
                'job_id' => ['nullable', 'integer'],
                'source' => ['nullable', 'string', 'max:120'],
            ]);
            $toPool = ! array_key_exists('pool', $data) ? true : (bool) $data['pool'];
            $jobId = ! empty($data['job_id']) ? (int) $data['job_id'] : null;
            $defSource = $data['source'] ?? 'Job portal';

            // If targeting a requisition, candidates go into the pipeline (not pool).
            $jobTitle = null;
            if ($jobId) {
                $job = DB::table('job_openings')->where('id', $jobId)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
                $jobTitle = $job->title ?? null;
                $toPool = false;
            }

            $alias = $this->importAliases();
            // Existing email/mobile sets for de-dup.
            $existEmail = DB::table('recruitment')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNotNull('email')->pluck('email')->map(fn ($e) => strtolower(trim($e)))->flip();
            $existMobile = DB::table('recruitment')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNotNull('mobile')->pluck('mobile')->map(fn ($m) => preg_replace('/\D/', '', (string) $m))->filter()->flip();

            $imported = 0;
            $dupes = 0;
            $noName = 0;
            $batch = [];
            foreach ($data['rows'] as $raw) {
                if (! is_array($raw)) {
                    continue;
                }
                // Lowercase + trim the keys for alias matching.
                $low = [];
                foreach ($raw as $k => $val) {
                    $low[strtolower(trim((string) $k))] = is_scalar($val) ? trim((string) $val) : '';
                }
                $pick = function ($field) use ($alias, $low) {
                    foreach ($alias[$field] as $cand) {
                        if (isset($low[$cand]) && $low[$cand] !== '') {
                            return $low[$cand];
                        }
                    }

                    return '';
                };
                $name = $pick('name');
                if ($name === '') {
                    $noName++;

                    continue;
                }
                $email = $pick('email');
                $mobile = $pick('mobile');
                $emKey = strtolower(trim($email));
                $moKey = preg_replace('/\D/', '', $mobile);
                if (($email !== '' && isset($existEmail[$emKey])) || ($moKey !== '' && isset($existMobile[$moKey]))) {
                    $dupes++;

                    continue;
                }
                if ($email !== '') {
                    $existEmail[$emKey] = true;
                }
                if ($moKey !== '') {
                    $existMobile[$moKey] = true;
                }

                $batch[] = ApprovalService::safeRow('recruitment', [
                    'tenant_id' => $tid, 'name' => $name,
                    'position' => $pick('position') ?: $jobTitle ?: null,
                    'source' => $pick('source') ?: $defSource,
                    'mobile' => $mobile ?: null, 'email' => $email ?: null,
                    'location' => $pick('location') ?: null,
                    'experience_years' => $this->numOnly($pick('experience_years')),
                    'current_company' => $pick('current_company') ?: null,
                    'current_designation' => $pick('current_designation') ?: null,
                    'current_ctc' => $this->numOnly($pick('current_ctc')),
                    'expected_ctc' => $this->numOnly($pick('expected_ctc')),
                    'skills' => $pick('skills') ?: null, 'resume_url' => $pick('resume_url') ?: null,
                    'notes' => $pick('notes') ?: null,
                    'pool' => $toPool ? 1 : 0,
                    'job_id' => $jobId,
                    'stage' => $toPool ? null : 'applied',
                    'rating' => 0, 'applied_on' => now()->toDateString(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $imported++;
                if (count($batch) >= 500) {
                    DB::table('recruitment')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                DB::table('recruitment')->insert($batch);
            }

            $msg = 'Imported '.$imported.' candidate(s)'.($toPool ? ' into the Talent Pool' : ($jobTitle ? ' into '.$jobTitle : ''));
            $extra = [];
            if ($dupes) {
                $extra[] = $dupes.' duplicate(s) skipped';
            }
            if ($noName) {
                $extra[] = $noName.' row(s) had no name';
            }
            if ($extra) {
                $msg .= ' ('.implode(', ', $extra).')';
            }

            return response()->json(['ok' => true, 'imported' => $imported, 'duplicates' => $dupes, 'noName' => $noName, 'message' => $msg.'.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- Talent pool (searchable candidate bank for future requisitions) -----

    public function pool(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $companies = DB::table('companies')->pluck('name', 'id');
            $q = trim((string) $request->query('q', ''));
            $minExp = $request->query('min_exp');
            $maxCtc = $request->query('max_ctc');
            $loc = trim((string) $request->query('location', ''));

            $rows = DB::table('recruitment')
                ->when($tid, fn ($qq) => $qq->where('tenant_id', $tid))
                ->where('pool', 1)
                ->when($q !== '', function ($qq) use ($q) {
                    $like = '%'.$q.'%';
                    $qq->where(function ($w) use ($like) {
                        $w->where('name', 'like', $like)->orWhere('skills', 'like', $like)
                            ->orWhere('current_company', 'like', $like)->orWhere('current_designation', 'like', $like)
                            ->orWhere('position', 'like', $like)->orWhere('email', 'like', $like);
                    });
                })
                ->when($loc !== '', fn ($qq) => $qq->where('location', 'like', '%'.$loc.'%'))
                ->when(is_numeric($minExp), fn ($qq) => $qq->where('experience_years', '>=', (float) $minExp))
                ->when(is_numeric($maxCtc), fn ($qq) => $qq->where(function ($w) use ($maxCtc) {
                    $w->where('expected_ctc', '<=', (float) $maxCtc)->orWhere('current_ctc', '<=', (float) $maxCtc)
                        ->orWhere(fn ($x) => $x->whereNull('expected_ctc')->whereNull('current_ctc'));
                }))
                ->orderByDesc('id')->limit(500)->get()
                ->map(fn ($r) => $this->candidateRow((array) $r, $companies))->values();

            $total = (int) DB::table('recruitment')->when($tid, fn ($qq) => $qq->where('tenant_id', $tid))->where('pool', 1)->count();

            return response()->json(['ok' => true, 'candidates' => $rows, 'shown' => $rows->count(), 'total' => $total]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Move a pooled candidate into a requisition's pipeline at 'Applied'. */
    public function assignToReq(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $v = $request->validate(['job_id' => ['required', 'integer']]);
            $job = DB::table('job_openings')->where('id', $v['job_id'])->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $job) {
                return response()->json(['ok' => false, 'error' => 'Requisition not found'], 404);
            }
            $cand = DB::table('recruitment')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            $upd = ['pool' => 0, 'job_id' => $job->id, 'stage' => 'applied', 'updated_at' => now()];
            if ($cand && empty($cand->position)) {
                $upd['position'] = $job->title;
            }
            if ($cand && empty($cand->company_id) && ! empty($job->company_id)) {
                $upd['company_id'] = $job->company_id;
            }
            DB::table('recruitment')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->update(ApprovalService::safeRow('recruitment', $upd));

            return response()->json(['ok' => true, 'message' => 'Moved to '.$job->title.' pipeline.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Bulk-assign a shortlist of pooled candidates into a requisition pipeline. */
    public function assignBulk(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $v = $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['integer'],
                'job_id' => ['required', 'integer'],
            ]);
            $job = DB::table('job_openings')->where('id', $v['job_id'])->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $job) {
                return response()->json(['ok' => false, 'error' => 'Requisition not found'], 404);
            }
            $n = DB::table('recruitment')->whereIn('id', $v['ids'])->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->update(ApprovalService::safeRow('recruitment', [
                    'pool' => 0, 'job_id' => $job->id, 'stage' => 'applied', 'updated_at' => now(),
                ]));

            return response()->json(['ok' => true, 'count' => $n, 'message' => 'Moved '.$n.' candidate(s) into '.$job->title.' pipeline.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- Requisitions (job_openings) ----------------------------------------

    public function saveJob(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
            $v = $request->validate([
                'id' => ['nullable', 'integer'],
                'title' => ['required', 'string', 'max:191'],
                'department' => ['nullable', 'string', 'max:120'],
                'company_name' => ['nullable', 'string'],
                'location' => ['nullable', 'string', 'max:191'],
                'openings' => ['nullable', 'integer', 'min:1'],
                'status' => ['nullable', 'in:open,on_hold,closed'],
                'priority' => ['nullable', 'in:low,normal,high,urgent'],
                'ctc_min' => ['nullable', 'numeric', 'min:0'],
                'ctc_max' => ['nullable', 'numeric', 'min:0'],
                'target_date' => ['nullable', 'date'],
                'requested_by' => ['nullable', 'string', 'max:191'],
                'justification' => ['nullable', 'string'],
                'description' => ['nullable', 'string'],
                'engagement' => ['nullable', 'in:on_roll,off_roll'],
            ]);
            $row = ['tenant_id' => $tid, 'title' => $v['title'], 'department' => $v['department'] ?? null,
                'location' => $v['location'] ?? null, 'openings' => $v['openings'] ?? 1,
                'engagement' => $v['engagement'] ?? 'on_roll',
                'priority' => $v['priority'] ?? 'normal',
                'ctc_min' => $v['ctc_min'] ?? null, 'ctc_max' => $v['ctc_max'] ?? null,
                'target_date' => $v['target_date'] ?? null, 'requested_by' => $v['requested_by'] ?? null,
                'justification' => $v['justification'] ?? null, 'description' => $v['description'] ?? null,
                'updated_at' => now()];
            if (! empty($v['company_name'])) {
                $cid = DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('name', $v['company_name'])->value('id');
                if ($cid) {
                    $row['company_id'] = $cid;
                }
            }
            if (! empty($v['id'])) {
                // Edit — never silently flip an approval; status can be tweaked.
                if (! empty($v['status'])) {
                    $row['status'] = $v['status'];
                }
                DB::table('job_openings')->where('id', $v['id'])->when($tid, fn ($q) => $q->where('tenant_id', $tid))->update(ApprovalService::safeRow('job_openings', $row));
            } else {
                // New requisition — starts PENDING approval.
                $n = (int) DB::table('job_openings')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->count() + 1;
                $reqCode = 'REQ'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
                while (DB::table('job_openings')->where('req_code', $reqCode)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->exists()) {
                    $n++;
                    $reqCode = 'REQ'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
                }
                $row['req_code'] = $reqCode;
                $row['approval_status'] = 'pending';
                $row['status'] = 'open';
                $row['filled'] = 0;
                $row['created_at'] = now();
                DB::table('job_openings')->insert(ApprovalService::safeRow('job_openings', $row));
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Approve a pending requisition → makes it recruitable. Admin only. */
    public function approveJob(Request $request, int $id)
    {
        if ($deny = $this->approverGuard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $by = $request->user()->name ?? ($request->user()->email ?? 'Admin');
            $job = DB::table('job_openings')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            $upd = ['approval_status' => 'approved', 'approved_by' => $by, 'approved_at' => now(), 'reject_reason' => null, 'updated_at' => now()];
            if (! $job || ($job->status ?? 'open') !== 'closed') {
                $upd['status'] = 'open';
            }
            DB::table('job_openings')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->update(ApprovalService::safeRow('job_openings', $upd));

            return response()->json(['ok' => true, 'message' => 'Requisition approved — now open for hiring.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Reject a requisition with a reason. Admin only. */
    public function rejectJob(Request $request, int $id)
    {
        if ($deny = $this->approverGuard($request)) {
            return $deny;
        }
        try {
            $this->ensure();
            $tid = $request->user()->tenant_id;
            $v = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
            DB::table('job_openings')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->update(ApprovalService::safeRow('job_openings', [
                    'approval_status' => 'rejected', 'reject_reason' => $v['reason'] ?? null,
                    'status' => 'closed', 'updated_at' => now(),
                ]));

            return response()->json(['ok' => true, 'message' => 'Requisition rejected.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function deleteJob(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            DB::table('job_openings')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->delete();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
