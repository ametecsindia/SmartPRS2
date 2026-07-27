<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee transfers (rev 77) — branch↔branch and company↔company (master ↔
 * subsidiary, either direction) within ONE tenant group.
 *
 * The request itself lives in the `transfers` table and rides the generic
 * approval engine (RequestController + ApprovalService — same chain as leave
 * and expenses). This service performs the actual MOVE:
 *
 *   - applyIfDue(): called the moment a transfer is APPROVED. If the
 *     effective date has arrived (or none was set) the employee record is
 *     updated immediately; otherwise the transfer waits, already approved.
 *   - applyDue(): the daily `transfers:apply` command — applies every
 *     approved transfer whose effective date has arrived (future-dated
 *     transfers auto-apply on the day, per Ejaz 4 Jun 2026).
 *
 * Rules: the employee KEEPS the same emp_code across the group (one lifetime
 * record); from_company/from_branch are snapshotted at apply time so the
 * register shows the complete movement trail; the row ends as status
 * 'applied' with applied_at set — a permanent history that is never deleted.
 * Seat counts are tenant-level so transfers never touch the subscription.
 */
class TransferService
{
    /** Apply one APPROVED transfer now if its effective date has arrived. */
    public static function applyIfDue(int $transferId): bool
    {
        $r = DB::table('transfers')->where('id', $transferId)->first();
        if (! $r || $r->status !== 'approved' || ! empty($r->applied_at)) {
            return false;
        }
        if (! empty($r->effective_date) && Carbon::parse($r->effective_date)->startOfDay()->isFuture()) {
            return false;   // approved, waiting for the effective date (daily job applies it)
        }

        return self::apply($r);
    }

    /** Daily sweep: apply all approved transfers whose effective date has arrived. */
    public static function applyDue(?callable $log = null): int
    {
        if (! Schema::hasTable('transfers')) {
            return 0;
        }
        $due = DB::table('transfers')->where('status', 'approved')->whereNull('applied_at')
            ->where(function ($q) {
                $q->whereNull('effective_date')->orWhere('effective_date', '<=', now()->toDateString());
            })->get();
        $n = 0;
        foreach ($due as $r) {
            try {
                if (self::apply($r)) {
                    $n++;
                    if ($log) {
                        $log('applied transfer #'.$r->id.' (employee '.$r->employee_id.')');
                    }
                }
            } catch (\Throwable $e) {
                if ($log) {
                    $log('transfer #'.$r->id.' failed: '.$e->getMessage());
                }
            }
        }

        return $n;
    }

    /**
     * Destination onboarding for a transferred employee (rev 77c): appears on
     * the Onboarding board ("formalities pending") until HR assigns the new
     * role/manager/team and completes the checklist. Mirrors the new-hire
     * pattern (RecruitmentController::ensureOnboarding) but is keyed on an
     * IN-PROGRESS row — an old completed onboarding from the original hire
     * never blocks a fresh transfer onboarding.
     */
    private static function startTransferOnboarding(object $r, object $emp, int $toCompanyId): void
    {
        try {
            if (! Schema::hasTable('onboarding')) {
                return;
            }
            $open = DB::table('onboarding')
                ->when($r->tenant_id, fn ($q) => $q->where('tenant_id', $r->tenant_id))
                ->where('employee', $emp->name)
                ->where('status', '!=', 'completed')
                ->exists();
            if ($open) {
                return;
            }
            $companyName = (string) DB::table('companies')->where('id', $toCompanyId)->value('name');
            DB::table('onboarding')->insert(\App\Http\Controllers\ApprovalService::safeRow('onboarding', [
                'tenant_id' => $r->tenant_id, 'company_id' => $toCompanyId,
                'employee_id' => $emp->id, 'employee' => $emp->name,
                'company_name' => $companyName,
                'stage' => 'Transfer',   // rev 78: transfer onboarding gets its own checklist (incl. hierarchy)
                'joined_on' => $r->effective_date ?: now()->toDateString(),
                'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
            ]));
        } catch (\Throwable $e) {
            // onboarding is best-effort; the transfer itself stands
        }
    }

    /** Perform the move + snapshot the trail. Caller has checked status/due. */
    private static function apply(object $r): bool
    {
        $emp = DB::table('employees')->where('id', $r->employee_id)->first();
        if (! $emp) {
            return false;
        }

        // Snapshots for the permanent register.
        $fromCompany = $emp->company_id
            ? (string) DB::table('companies')->where('id', $emp->company_id)->value('name')
            : '';
        $fromBranch = (string) ($emp->branch ?? '');

        $empUpd = ['updated_at' => now()];
        $isCompanyMove = stripos((string) $r->type, 'compan') !== false;
        $isDeptMove = ! $isCompanyMove && stripos((string) $r->type, 'depart') !== false;

        if ($isCompanyMove) {
            // Resolve the destination company (id wins; else by name, tenant-scoped).
            $toId = $r->to_company_id;
            if (! $toId && ! empty($r->to_company)) {
                $toId = DB::table('companies')
                    ->when($r->tenant_id, fn ($q) => $q->where('tenant_id', $r->tenant_id))
                    ->whereNull('deleted_at')->where('name', $r->to_company)->value('id');
            }
            if (! $toId) {
                return false;   // destination company unknown — leave approved, admin can fix
            }
            $empUpd['company_id'] = (int) $toId;
            if (! empty($r->to_branch) && Schema::hasColumn('employees', 'branch')) {
                $empUpd['branch'] = $r->to_branch;
            }
            if (! empty($r->to_department) && Schema::hasColumn('employees', 'department')) {
                $empUpd['department'] = $r->to_department;
            }
            // rev 77c (Ejaz): the OLD company's hierarchy makes no sense in the
            // new company — clear it so HR must assign fresh ones via the
            // destination ONBOARDING (designation/role is kept, reviewable).
            foreach (['reporting_manager', 'team_leader', 'team'] as $h) {
                if (Schema::hasColumn('employees', $h)) {
                    $empUpd[$h] = null;
                }
            }
            if (Schema::hasColumn('employees', 'reporting_manager_id')) {
                $empUpd['reporting_manager_id'] = null;
            }
        } elseif ($isDeptMove) {
            if (empty($r->to_department)) {
                return false;   // a department transfer needs a destination department
            }
            if (Schema::hasColumn('employees', 'department')) {
                $empUpd['department'] = $r->to_department;
            }
        } else {
            if (empty($r->to_branch)) {
                return false;   // a branch transfer needs a destination branch
            }
            if (Schema::hasColumn('employees', 'branch')) {
                $empUpd['branch'] = $r->to_branch;
            }
        }

        DB::table('employees')->where('id', $emp->id)->update($empUpd);

        // rev 77c: a COMPANY transfer puts the employee through ONBOARDING at
        // the destination — formalities checklist (assign role, reporting
        // manager, team/hierarchy) until HR completes it. Idempotent: skipped
        // if an in-progress onboarding already exists for this employee.
        if ($isCompanyMove) {
            self::startTransferOnboarding($r, $emp, (int) $empUpd['company_id']);
        }

        $upd = [
            'from_company' => $fromCompany, 'from_branch' => $fromBranch,
            'status' => 'applied', 'applied_at' => now(), 'updated_at' => now(),
        ];
        if ($isCompanyMove && ! empty($empUpd['company_id'])) {
            $upd['to_company_id'] = $empUpd['company_id'];
        }
        DB::table('transfers')->where('id', $r->id)->update(
            \App\Http\Controllers\ApprovalService::safeRow('transfers', $upd)
        );

        // Tell the employee (best-effort).
        try {
            if (! empty($emp->email)) {
                $dest = $isCompanyMove
                    ? ('company "'.($r->to_company ?: '').'"'.(! empty($r->to_branch) ? ', branch '.$r->to_branch : ''))
                    : ('branch "'.$r->to_branch.'"');
                MailService::queue([
                    'tenant_id' => $emp->tenant_id,
                    'company_id' => $empUpd['company_id'] ?? $emp->company_id,
                    'to' => $emp->email,
                    'to_name' => $emp->name,
                    'subject' => 'Your transfer is effective today',
                    'heading' => 'Transfer applied',
                    'intro' => 'Your transfer to '.$dest.' is now in effect. Your employee code stays the same.',
                    'kind' => 'transfer.applied',
                ]);
            }
        } catch (\Throwable $e) {
        }

        return true;
    }
}
