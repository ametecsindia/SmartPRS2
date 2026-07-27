<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev 95 — HIRING DRIVES (Ejaz): a planned hiring event that holds the
 * position, timing, venue + Google Maps link, the interview PANEL (each
 * interviewer's name / designation / phone / round) and a coordinator. The
 * drive's details auto-fill the bulk WhatsApp invite (see RecruitMessaging),
 * and the drive tracks the funnel: invited → will-attend → attended →
 * interviewed (panel score) → selected → hired.
 *
 * Types: walkin | scheduled | telephonic. Attendance is marked by the
 * coordinator (a tick). Builds on the bulk-WhatsApp + Interviews features.
 */
class HiringDriveController extends Controller
{
    private function guard(Request $request)
    {
        return ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager']);
    }

    public static function ensure(): void
    {
        if (! Schema::hasTable('hiring_drives')) {
            Schema::create('hiring_drives', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('job_id')->nullable();
                $t->string('title');
                $t->string('position')->nullable();
                $t->string('company')->nullable();
                $t->string('type', 20)->default('walkin');     // walkin|scheduled|telephonic
                $t->string('status', 20)->default('planned');  // planned|open|completed|cancelled
                $t->date('drive_date')->nullable();
                $t->date('date_to')->nullable();
                $t->string('time_from', 20)->nullable();
                $t->string('time_to', 20)->nullable();
                $t->string('venue')->nullable();
                $t->text('location_link')->nullable();
                $t->string('coordinator_name')->nullable();
                $t->string('coordinator_phone', 20)->nullable();
                $t->text('instructions')->nullable();
                $t->text('panel_json')->nullable();            // [{name,designation,phone,round}]
                $t->text('slots_json')->nullable();            // scheduled type: [{time,capacity}]
                $t->string('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hiring_drive_candidates')) {
            Schema::create('hiring_drive_candidates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('drive_id')->index();
                $t->unsignedBigInteger('candidate_id')->nullable();
                $t->string('name')->nullable();
                $t->string('mobile', 20)->nullable();
                $t->string('response', 30)->nullable();        // mirrors campaign funnel
                $t->boolean('attended')->default(false);
                $t->timestamp('attended_at')->nullable();
                $t->integer('score')->nullable();              // 0-10 panel score
                $t->string('recommendation', 30)->nullable();  // strong_hire|hire|hold|no_hire
                $t->string('outcome', 20)->default('pending'); // pending|selected|rejected|hold
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }
        // Link a WhatsApp campaign back to its drive.
        if (Schema::hasTable('recruitment_campaigns') && ! Schema::hasColumn('recruitment_campaigns', 'drive_id')) {
            try {
                Schema::table('recruitment_campaigns', fn (Blueprint $t) => $t->unsignedBigInteger('drive_id')->nullable()->index());
            } catch (\Throwable $e) {
            }
        }
    }

    private const DEFAULT_INSTRUCTIONS = 'Please carry an updated resume, original photo ID proof and a pen. Formal dress.';

    public function index(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        self::ensure();
        $tid = $request->user()->tenant_id;
        $drives = DB::table('hiring_drives')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->orderByDesc('id')->limit(200)->get();
        // Roster funnel per drive.
        $ros = DB::table('hiring_drive_candidates')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->selectRaw('drive_id,
                count(*) invited,
                sum(case when response in ("will_attend","interested") then 1 else 0 end) will_attend,
                sum(case when attended = 1 then 1 else 0 end) attended,
                sum(case when score is not null then 1 else 0 end) interviewed,
                sum(case when outcome = "selected" then 1 else 0 end) selected')
            ->groupBy('drive_id')->get()->keyBy('drive_id');

        return response()->json(['ok' => true, 'rows' => $drives->map(function ($d) use ($ros) {
            $f = $ros[$d->id] ?? null;

            return [
                'id' => (int) $d->id, 'title' => $d->title, 'type' => $d->type, 'status' => $d->status,
                'position' => $d->position, 'company' => $d->company,
                'date' => $d->drive_date, 'dateTo' => $d->date_to,
                'timeFrom' => $d->time_from, 'timeTo' => $d->time_to,
                'venue' => $d->venue,
                'funnel' => [
                    'invited' => (int) ($f->invited ?? 0), 'willAttend' => (int) ($f->will_attend ?? 0),
                    'attended' => (int) ($f->attended ?? 0), 'interviewed' => (int) ($f->interviewed ?? 0),
                    'selected' => (int) ($f->selected ?? 0),
                ],
            ];
        })->values()]);
    }

    public function save(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            self::ensure();
            $tid = $request->user()->tenant_id;
            $v = $request->validate([
                'id' => ['nullable', 'integer'],
                'title' => ['required', 'string', 'max:160'],
                'job_id' => ['nullable', 'integer'],
                'position' => ['nullable', 'string', 'max:120'],
                'company' => ['nullable', 'string', 'max:150'],
                'type' => ['required', 'in:walkin,scheduled,telephonic'],
                'status' => ['nullable', 'in:planned,open,completed,cancelled'],
                'drive_date' => ['nullable', 'date'],
                'date_to' => ['nullable', 'date'],
                'time_from' => ['nullable', 'string', 'max:20'],
                'time_to' => ['nullable', 'string', 'max:20'],
                'venue' => ['nullable', 'string', 'max:255'],
                'location_link' => ['nullable', 'string', 'max:500'],
                'coordinator_name' => ['nullable', 'string', 'max:120'],
                'coordinator_phone' => ['nullable', 'string', 'max:20'],
                'instructions' => ['nullable', 'string', 'max:1000'],
                'panel' => ['nullable', 'array'],
                'slots' => ['nullable', 'array'],
            ]);

            // Clean the panel: keep rows with at least a name.
            $panel = [];
            foreach ((array) ($v['panel'] ?? []) as $p) {
                $name = trim((string) ($p['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $panel[] = [
                    'name' => $name,
                    'designation' => trim((string) ($p['designation'] ?? '')),
                    'phone' => trim((string) ($p['phone'] ?? '')),
                    'round' => trim((string) ($p['round'] ?? '')),
                ];
            }
            $slots = [];
            foreach ((array) ($v['slots'] ?? []) as $s) {
                $time = trim((string) ($s['time'] ?? ''));
                if ($time === '') {
                    continue;
                }
                $slots[] = ['time' => $time, 'capacity' => (int) ($s['capacity'] ?? 0)];
            }

            $row = [
                'tenant_id' => $tid, 'job_id' => $v['job_id'] ?? null,
                'title' => $v['title'], 'position' => $v['position'] ?? null, 'company' => $v['company'] ?? null,
                'type' => $v['type'], 'status' => $v['status'] ?? 'planned',
                'drive_date' => $v['drive_date'] ?? null, 'date_to' => $v['date_to'] ?? null,
                'time_from' => $v['time_from'] ?? null, 'time_to' => $v['time_to'] ?? null,
                'venue' => $v['venue'] ?? null, 'location_link' => $v['location_link'] ?? null,
                'coordinator_name' => $v['coordinator_name'] ?? null, 'coordinator_phone' => $v['coordinator_phone'] ?? null,
                'instructions' => $v['instructions'] ?: self::DEFAULT_INSTRUCTIONS,
                'panel_json' => json_encode($panel), 'slots_json' => json_encode($slots),
                'updated_at' => now(),
            ];

            if (! empty($v['id'])) {
                $exists = DB::table('hiring_drives')->where('id', $v['id'])
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->exists();
                if (! $exists) {
                    return response()->json(['ok' => false, 'error' => 'Drive not found.'], 404);
                }
                DB::table('hiring_drives')->where('id', $v['id'])->update($row);

                return response()->json(['ok' => true, 'id' => (int) $v['id'], 'message' => 'Hiring drive saved']);
            }
            $row['created_by'] = $request->user()->name ?? null;
            $row['created_at'] = now();
            $id = DB::table('hiring_drives')->insertGetId($row);

            return response()->json(['ok' => true, 'id' => $id, 'message' => 'Hiring drive created']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        self::ensure();
        $tid = $request->user()->tenant_id;
        $d = DB::table('hiring_drives')->where('id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $d) {
            return response()->json(['ok' => false, 'error' => 'Drive not found.'], 404);
        }
        $roster = DB::table('hiring_drive_candidates')->where('drive_id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->orderBy('name')->get();
        // Delivery status from the linked campaign(s).
        $deliv = DB::table('recruitment_campaigns')->where('drive_id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->selectRaw('coalesce(sum(sent),0) sent, coalesce(sum(failed),0) failed, coalesce(sum(total),0) total')->first();

        return response()->json([
            'ok' => true,
            'drive' => $this->driveOut($d),
            'roster' => $roster->map(fn ($r) => [
                'id' => (int) $r->id, 'candidateId' => $r->candidate_id ? (int) $r->candidate_id : null,
                'name' => $r->name, 'mobile' => $r->mobile, 'response' => $r->response,
                'attended' => (bool) $r->attended, 'score' => $r->score, 'recommendation' => $r->recommendation,
                'outcome' => $r->outcome, 'notes' => $r->notes,
            ])->values(),
            'delivery' => ['sent' => (int) ($deliv->sent ?? 0), 'failed' => (int) ($deliv->failed ?? 0), 'total' => (int) ($deliv->total ?? 0)],
        ]);
    }

    private function driveOut($d): array
    {
        return [
            'id' => (int) $d->id, 'title' => $d->title, 'jobId' => $d->job_id ? (int) $d->job_id : null,
            'position' => $d->position, 'company' => $d->company, 'type' => $d->type, 'status' => $d->status,
            'date' => $d->drive_date, 'dateTo' => $d->date_to, 'timeFrom' => $d->time_from, 'timeTo' => $d->time_to,
            'venue' => $d->venue, 'locationLink' => $d->location_link,
            'coordinatorName' => $d->coordinator_name, 'coordinatorPhone' => $d->coordinator_phone,
            'instructions' => $d->instructions,
            'panel' => json_decode($d->panel_json ?: '[]', true) ?: [],
            'slots' => json_decode($d->slots_json ?: '[]', true) ?: [],
        ];
    }

    public function destroy(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        self::ensure();
        $tid = $request->user()->tenant_id;
        DB::table('hiring_drives')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->delete();
        DB::table('hiring_drive_candidates')->where('drive_id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->delete();

        return response()->json(['ok' => true, 'message' => 'Drive deleted']);
    }

    public function setStatus(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        self::ensure();
        $tid = $request->user()->tenant_id;
        $v = $request->validate(['status' => ['required', 'in:planned,open,completed,cancelled']]);
        $n = DB::table('hiring_drives')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->update(['status' => $v['status'], 'updated_at' => now()]);

        return response()->json(['ok' => (bool) $n, 'message' => $n ? 'Status updated' : 'Not found']);
    }

    /** Roster row update: attendance tick, panel score/recommendation, outcome. */
    public function updateCandidate(Request $request, int $id, int $cid)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        self::ensure();
        $tid = $request->user()->tenant_id;
        $row = DB::table('hiring_drive_candidates')->where('id', $cid)->where('drive_id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $row) {
            return response()->json(['ok' => false, 'error' => 'Candidate not on this drive.'], 404);
        }
        $upd = ['updated_at' => now()];
        if ($request->has('attended')) {
            $att = (bool) $request->input('attended');
            $upd['attended'] = $att;
            $upd['attended_at'] = $att ? now() : null;
        }
        if ($request->has('score')) {
            $upd['score'] = $request->input('score') === '' ? null : (int) $request->input('score');
        }
        if ($request->has('recommendation')) {
            $upd['recommendation'] = $request->input('recommendation') ?: null;
        }
        if ($request->has('outcome')) {
            $upd['outcome'] = $request->input('outcome') ?: 'pending';
        }
        if ($request->has('response')) {
            $upd['response'] = $request->input('response') ?: null;
        }
        if ($request->has('notes')) {
            $upd['notes'] = $request->input('notes');
        }
        DB::table('hiring_drive_candidates')->where('id', $cid)->update($upd);

        // When SELECTED, move the candidate to the Offer stage in the pipeline.
        if (($upd['outcome'] ?? null) === 'selected' && $row->candidate_id) {
            try {
                DB::table('recruitment')->where('id', $row->candidate_id)->update(['stage' => 'offer', 'updated_at' => now()]);
            } catch (\Throwable $e) {
            }
        }

        return response()->json(['ok' => true, 'message' => 'Updated']);
    }

    /**
     * Upsert recipients onto a drive's roster — called by RecruitMessaging::send
     * when an invite is fired from a drive (static so it can be reused).
     */
    public static function addToRoster(?int $tid, int $driveId, array $recipients): void
    {
        self::ensure();
        foreach ($recipients as $r) {
            $q = DB::table('hiring_drive_candidates')->where('drive_id', $driveId);
            if (! empty($r['candidate_id'])) {
                $q->where('candidate_id', $r['candidate_id']);
            } else {
                $q->where('mobile', $r['mobile'] ?? '')->whereNull('candidate_id');
            }
            if ($q->exists()) {
                continue;
            }
            DB::table('hiring_drive_candidates')->insert([
                'tenant_id' => $tid, 'drive_id' => $driveId,
                'candidate_id' => $r['candidate_id'] ?? null,
                'name' => $r['name'] ?? null, 'mobile' => $r['mobile'] ?? null,
                'outcome' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** CSV export of the drive roster + outcomes. */
    public function export(Request $request, int $id)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        self::ensure();
        $tid = $request->user()->tenant_id;
        $rows = DB::table('hiring_drive_candidates')->where('drive_id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->orderBy('name')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Mobile', 'Response', 'Attended', 'Score', 'Recommendation', 'Outcome', 'Notes']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->name, $r->mobile, $r->response, $r->attended ? 'Yes' : 'No', $r->score, $r->recommendation, $r->outcome, $r->notes]);
            }
            fclose($out);
        }, 'hiring-drive-'.$id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
