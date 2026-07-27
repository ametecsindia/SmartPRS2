<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding as a real checklist workflow. Each onboarding record (a new hire)
 * gets a standard checklist; ticking items advances the record's status
 * automatically (Pending → In Progress → Completed). Admin/HR managed.
 */
class OnboardingController extends Controller
{
    private const DEFAULT_ITEMS = [
        'Offer accepted',
        'Documents collected (ID, PAN, address)',
        'Bank / PF / ESI details captured',
        'ID card issued',
        'Email / IT / system access setup',
        'Orientation & policy briefing done',
    ];

    /**
     * rev 78: checklist for an employee TRANSFERRED into this company
     * (TransferService sets stage 'Transfer'). The hierarchy item is the key
     * one — the old company's manager/team were cleared on the move, so the
     * formalities are not complete until HR assigns the new ones.
     */
    private const TRANSFER_ITEMS = [
        'Joining formalities at new company (documents verified)',
        'Role, reporting manager & team assigned',
        'Bank / PF / ESI details re-verified',
        'ID card re-issued for the new company',
        'System access updated (email / portals)',
        'Orientation at the new company done',
    ];

    private function ensureTables(): void
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
        if (! Schema::hasTable('onboarding_items')) {
            Schema::create('onboarding_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('onboarding_id')->index();
                $t->string('label')->nullable();
                $t->boolean('done')->default(false);
                $t->integer('seq')->default(0);
                $t->timestamps();
            });
        }
    }

    public function board(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $this->ensureTables();
            $tid = $request->user()->tenant_id;
            $recs = DB::table('onboarding')
                ->when($tid && Schema::hasColumn('onboarding', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->when(Schema::hasColumn('onboarding', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->orderByDesc('id')->get();

            // rev 81b: heal stale employee links — onboarding rows can outlive a
            // demo reseed (or an employee re-import) and point at a dead id; the
            // "Assign role, manager & team" link then finds nobody. Resolve by
            // NAME when the stored id no longer exists.
            $liveIds = [];
            $byName = [];
            $codeById = [];
            try {
                foreach (DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->whereNull('deleted_at')->get(['id', 'name', 'emp_code']) as $le) {
                    $liveIds[(int) $le->id] = true;
                    $byName[strtolower(trim((string) $le->name))] = (int) $le->id;
                    $codeById[(int) $le->id] = (string) $le->emp_code;
                }
            } catch (\Throwable $e) {
            }

            $rows = [];
            foreach ($recs as $rec) {
                $items = $this->itemsFor($rec, $tid);
                $total = count($items);
                $done = 0;
                foreach ($items as $it) {
                    if ($it->done) {
                        $done++;
                    }
                }
                $status = $total === 0 ? 'pending' : ($done >= $total ? 'completed' : ($done > 0 ? 'in_progress' : 'pending'));
                if (($rec->status ?? '') !== $status) {
                    DB::table('onboarding')->where('id', $rec->id)->update(['status' => $status, 'updated_at' => now()]);
                }
                $empId = $rec->employee_id ?? null;
                if (! $empId || ! isset($liveIds[(int) $empId])) {
                    $empId = $byName[strtolower(trim((string) ($rec->employee ?? '')))] ?? null;
                    if ($empId && ($rec->employee_id ?? null) != $empId) {
                        // persist the healed link so future loads are clean
                        try {
                            DB::table('onboarding')->where('id', $rec->id)->update(['employee_id' => $empId, 'updated_at' => now()]);
                        } catch (\Throwable $e) {
                        }
                    }
                }
                $rows[] = [
                    'id' => $rec->id,
                    'employee' => $rec->employee ?? '',
                    'employeeId' => $empId,
                    // rev 81b: the SPA's employee list is keyed by emp_code, not
                    // the numeric id — the Assign link needs THIS (team test #7).
                    'employeeCode' => $empId ? ($codeById[(int) $empId] ?? null) : null,
                    'transfer' => ($rec->stage ?? '') === 'Transfer',   // rev 78
                    'joined_on' => $rec->joined_on,
                    'status' => $status,
                    'done' => $done,
                    'total' => $total,
                    'items' => array_map(fn ($i) => ['id' => $i->id, 'label' => $i->label, 'done' => (bool) $i->done], $items),
                ];
            }

            return response()->json(['ok' => true, 'rows' => $rows]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'rows' => []]);
        }
    }

    /** Get a record's checklist items, seeding the standard list on first view. */
    private function itemsFor($rec, ?int $tid): array
    {
        $items = DB::table('onboarding_items')->where('onboarding_id', $rec->id)->orderBy('seq')->orderBy('id')->get()->all();
        if (! $items) {
            $seq = 1;
            $ins = [];
            $list = ($rec->stage ?? '') === 'Transfer' ? self::TRANSFER_ITEMS : self::DEFAULT_ITEMS;
            foreach ($list as $label) {
                $ins[] = [
                    'tenant_id' => $tid,
                    'onboarding_id' => $rec->id,
                    'label' => $label,
                    'done' => false,
                    'seq' => $seq++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('onboarding_items')->insert($ins);
            $items = DB::table('onboarding_items')->where('onboarding_id', $rec->id)->orderBy('seq')->orderBy('id')->get()->all();
        }

        return $items;
    }

    public function toggle(Request $request, int $itemId)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $this->ensureTables();
            $it = DB::table('onboarding_items')->where('id', $itemId)->first();
            if (! $it) {
                return response()->json(['ok' => false, 'error' => 'Item not found.'], 404);
            }
            DB::table('onboarding_items')->where('id', $itemId)->update(['done' => ! $it->done, 'updated_at' => now()]);

            // Recompute the parent record status.
            $items = DB::table('onboarding_items')->where('onboarding_id', $it->onboarding_id)->get();
            $total = $items->count();
            $done = $items->where('done', true)->count();
            $status = $total === 0 ? 'pending' : ($done >= $total ? 'completed' : ($done > 0 ? 'in_progress' : 'pending'));
            DB::table('onboarding')->where('id', $it->onboarding_id)->update(['status' => $status, 'updated_at' => now()]);

            return response()->json(['ok' => true, 'status' => $status, 'done' => $done, 'total' => $total]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
