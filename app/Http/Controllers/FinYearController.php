<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Financial Year (India: 1 Apr - 31 Mar).
 *
 * Stores the tenant's ACTIVE financial year (settings table, key fy_active),
 * exposes helpers to classify a date into its FY, stamps new records with a
 * `fin_year` tag (so records are maintained FY-wise going forward), and powers
 * a Financial Year screen that sets the active FY and shows a per-FY summary of
 * the key money records.
 *
 * FY label format: "2026-27". Default = the FY of today's date.
 */
class FinYearController extends Controller
{
    /** Tables stamped + summarised by financial year. */
    private const MONEY_TABLES = ['commissions', 'payslips', 'expenses', 'advances', 'loans', 'clawbacks', 'bonus_encashment'];

    // ---- helpers ------------------------------------------------------------

    /** FY label for a date (Apr-Mar). e.g. 2026-06-02 -> "2026-27". */
    public static function fyOf($date): string
    {
        try {
            $c = Carbon::parse($date);
        } catch (\Throwable $e) {
            $c = now();
        }
        $start = (int) $c->month >= 4 ? (int) $c->year : (int) $c->year - 1;

        return $start.'-'.substr((string) ($start + 1), 2);
    }

    public static function current(): string
    {
        return self::fyOf(now());
    }

    /** Active FY for a tenant (stored), else the current FY. */
    public static function active(?int $tid): string
    {
        try {
            $v = DB::table('settings')->where('tenant_id', (int) ($tid ?? 0))->where('key', 'fy_active')->value('value');
            if ($v) {
                return $v;
            }
        } catch (\Throwable $e) {
        }

        return self::current();
    }

    /** [startDate, endDate] for an FY label. */
    public static function range(string $fy): array
    {
        $s = (int) substr($fy, 0, 4);

        return [$s.'-04-01', ($s + 1).'-03-31'];
    }

    /** A list of recent FYs (for pickers): from 5 years back through next year. */
    public static function options(): array
    {
        $cur = (int) substr(self::current(), 0, 4);
        $out = [];
        for ($s = $cur + 1; $s >= $cur - 5; $s--) {
            $out[] = $s.'-'.substr((string) ($s + 1), 2);
        }

        return $out;
    }

    /** Ensure a fin_year column exists, then return the active FY to stamp onto a new row. */
    public static function stamp(string $table, ?int $tid): ?string
    {
        try {
            if (! Schema::hasTable($table)) {
                return null;
            }
            if (! Schema::hasColumn($table, 'fin_year')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('fin_year', 9)->nullable()->index());
            }

            return self::active($tid);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---- endpoints ----------------------------------------------------------

    public function index(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id;
            $active = self::active($tid);
            $canManage = $request->user()->hasAnyRole(['admin', 'hr_manager', 'super_admin']);

            // Per-FY summary of key money records (by created_at, the reliable date
            // present on every table). Count + amount total where an amount exists.
            $summary = [];
            foreach (self::options() as $fy) {
                [$from, $to] = self::range($fy);
                $to .= ' 23:59:59';
                $count = 0;
                $amount = 0.0;
                foreach (self::MONEY_TABLES as $tbl) {
                    if (! Schema::hasTable($tbl)) {
                        continue;
                    }
                    try {
                        $q = DB::table($tbl)
                            ->when($tid && Schema::hasColumn($tbl, 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid))
                            ->whereBetween('created_at', [$from, $to]);
                        $count += (clone $q)->count();
                        if (Schema::hasColumn($tbl, 'amount')) {
                            $amount += (float) (clone $q)->sum('amount');
                        }
                    } catch (\Throwable $e) {
                    }
                }
                $summary[] = ['fy' => $fy, 'records' => $count, 'amount' => round($amount, 2), 'active' => $fy === $active];
            }

            return response()->json([
                'active' => $active,
                'current' => self::current(),
                'options' => self::options(),
                'summary' => $summary,
                'canManage' => $canManage,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['active' => self::current(), 'options' => self::options(), 'summary' => [], 'error' => $e->getMessage()]);
        }
    }

    public function setActive(Request $request)
    {
        try {
            abort_unless($request->user()->hasAnyRole(['admin', 'hr_manager', 'super_admin']), 403);
            $v = $request->validate(['fy' => ['required', 'string', 'max:9', 'regex:/^[0-9]{4}-[0-9]{2}$/']]);
            $tid = (int) ($request->user()->tenant_id ?? 0);
            DB::table('settings')->updateOrInsert(
                ['tenant_id' => $tid, 'key' => 'fy_active'],
                ['value' => $v['fy'], 'updated_at' => now(), 'created_at' => now()]
            );

            return response()->json(['ok' => true, 'active' => $v['fy'], 'message' => 'Active financial year set to FY '.$v['fy'].'.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
