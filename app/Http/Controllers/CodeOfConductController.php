<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Code of Conduct — read-and-acknowledge screen.
 *
 * Shows the Code of Conduct policy (sourced from the global Knowledge Base
 * articles in the "Code of Conduct" category) and lets the signed-in user
 * acknowledge that they have read and accepted it. The acknowledgement is
 * recorded in code_of_conduct_ack (one row per user). Admins / HR / Super Admin
 * additionally see the list of who has acknowledged and when.
 *
 * Tenant-scoped for acknowledgements; fail-soft JSON.
 */
class CodeOfConductController extends Controller
{
    private function ensure(): void
    {
        if (! Schema::hasTable('code_of_conduct_ack')) {
            Schema::create('code_of_conduct_ack', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('employee')->nullable();
                $t->boolean('acknowledged')->default(true);
                $t->date('ack_date')->nullable();
                $t->timestamps();
            });

            return;
        }
        foreach (['user_id' => 'int', 'employee' => 'string', 'acknowledged' => 'bool', 'ack_date' => 'date', 'tenant_id' => 'int'] as $col => $type) {
            if (Schema::hasColumn('code_of_conduct_ack', $col)) {
                continue;
            }
            try {
                Schema::table('code_of_conduct_ack', function (Blueprint $t) use ($col, $type) {
                    if ($type === 'int') {
                        $t->unsignedBigInteger($col)->nullable()->index();
                    } elseif ($type === 'bool') {
                        $t->boolean($col)->default(true);
                    } elseif ($type === 'date') {
                        $t->date($col)->nullable();
                    } else {
                        $t->string($col)->nullable();
                    }
                });
            } catch (\Throwable $e) {
            }
        }

        // Legacy table (from the old ack-tracker master) created employee_id as
        // NOT NULL with no default, which breaks the per-USER acknowledgement
        // insert. Relax it to nullable. Fail-soft.
        if (Schema::hasColumn('code_of_conduct_ack', 'employee_id')) {
            try {
                if (Schema::getConnection()->getDriverName() === 'mysql') {
                    DB::statement('ALTER TABLE `code_of_conduct_ack` MODIFY `employee_id` BIGINT UNSIGNED NULL');
                }
            } catch (\Throwable $e) {
            }
        }
    }

    /** Resolve a friendly name for the current user (employee name if linked). */
    private function personName(Request $request): string
    {
        $u = $request->user();
        $name = $u->name ?? 'User';
        try {
            $emp = DB::table('employees')
                ->when($u->tenant_id, fn ($q) => $q->where('tenant_id', $u->tenant_id))
                ->where(function ($q) use ($u) {
                    $q->where('email', $u->email)->orWhere('name', $u->name);
                })->whereNull('deleted_at')->first(['name', 'emp_code']);
            if ($emp) {
                $name = $emp->name.($emp->emp_code ? ' ('.$emp->emp_code.')' : '');
            }
        } catch (\Throwable $e) {
        }

        return $name;
    }

    public function show(Request $request)
    {
        try {
            $this->ensure();
            $u = $request->user();
            $tid = $u->tenant_id;

            // Policy content from the global KB "Code of Conduct" articles.
            $content = [];
            if (Schema::hasTable('kb_topics')) {
                $rows = DB::table('kb_topics')->where('category', 'Code of Conduct')
                    ->orderBy('sort')->orderBy('id')->get(['title', 'body']);
                foreach ($rows as $r) {
                    $paras = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $r->body)), fn ($p) => $p !== ''));
                    $content[] = ['title' => $r->title, 'paras' => $paras];
                }
            }

            // Current user's acknowledgement.
            $mine = DB::table('code_of_conduct_ack')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('user_id', $u->id)->where('acknowledged', 1)
                ->orderByDesc('id')->first();

            $canManage = $u->hasAnyRole(['admin', 'hr_manager', 'super_admin']);
            $acks = [];
            if ($canManage) {
                $acks = DB::table('code_of_conduct_ack')
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->where('acknowledged', 1)->orderByDesc('ack_date')->orderByDesc('id')->limit(500)
                    ->get(['employee', 'ack_date'])
                    ->map(fn ($a) => [
                        'employee' => $a->employee ?: '—',
                        'date' => $a->ack_date ? Carbon::parse($a->ack_date)->format('d M Y') : '',
                    ])->values();
            }

            return response()->json([
                'content' => $content,
                'acknowledged' => (bool) $mine,
                'ackDate' => $mine && $mine->ack_date ? Carbon::parse($mine->ack_date)->format('d M Y') : null,
                'me' => $this->personName($request),
                'canManage' => $canManage,
                'acks' => $acks,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['content' => [], 'error' => $e->getMessage()]);
        }
    }

    public function acknowledge(Request $request)
    {
        try {
            $this->ensure();
            $u = $request->user();
            $tid = $u->tenant_id;
            $name = $this->personName($request);

            $existing = DB::table('code_of_conduct_ack')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('user_id', $u->id)->first();

            // Belt-and-braces for legacy schemas with a NOT-NULL employee_id:
            // link the user's employee row if one exists, else 0. (safeRow drops
            // the key entirely when the column is absent.)
            $empId = 0;
            try {
                $empId = (int) (DB::table('employees')
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->where(function ($q) use ($u) {
                        $q->where('email', $u->email)->orWhere('name', $u->name);
                    })->whereNull('deleted_at')->value('id') ?: 0);
            } catch (\Throwable $e) {
            }

            $data = ApprovalService::safeRow('code_of_conduct_ack', [
                'tenant_id' => $tid, 'user_id' => $u->id, 'employee' => $name, 'employee_id' => $empId,
                'acknowledged' => 1, 'ack_date' => now()->toDateString(), 'updated_at' => now(),
            ]);
            if ($existing) {
                DB::table('code_of_conduct_ack')->where('id', $existing->id)->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('code_of_conduct_ack')->insert($data);
            }

            return response()->json(['ok' => true, 'message' => 'Thank you — your acknowledgement is recorded.', 'date' => now()->format('d M Y')]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
