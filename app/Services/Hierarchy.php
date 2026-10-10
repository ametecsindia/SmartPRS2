<?php

namespace App\Services;

use App\Http\Controllers\AppDataController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 7 Oct 2026 (Ejaz) — ONE reporting hierarchy for the whole application.
 *
 *   U1, U2 → report to U3 → reports to U4
 *   U3 sees + approves U1, U2.   U4 sees + approves U3, U1, U2.
 *
 * The chain comes from employees.reporting_manager (the NAME the Directory
 * form, the sample file and Self-Onboarding write) with
 * employees.reporting_manager_id as the fallback. Before this, every module
 * walked only the _id column, which the form never filled — so the "downline"
 * was empty for almost everyone and managers saw nobody.
 *
 * Who sees what (visibleIds):
 *   Super Admin / Admin                         → everyone (null)
 *   Roles & Permissions row with scope Self      → own record + whole downline
 *   built-in Employee / Field Agent              → own record + whole downline
 *   a custom role with no row (typo / deleted)   → own record + whole downline
 *   everyone else (HR, Accountant, Company/Group scope roles) → everyone
 *
 * Who approves (canApprove): Admin / HR always; anyone ABOVE the requester in
 * the chain (any level, first decision closes it); the named approver; never
 * one's own request. A role with a matrix row must also have Approve ticked
 * for that module.
 */
class Hierarchy
{
    /** @var array<int,array<int,int>> tenant => [empId => managerEmpId] */
    private static array $maps = [];

    /** @var array<int,array> tenant => [empId => ['code'=>..., 'name'=>...]] */
    private static array $people = [];

    public const BUILT_IN = ['super_admin', 'admin', 'hr_manager', 'field_agent', 'employee', 'accountant'];

    /** empId => managerEmpId for the tenant (name on the record wins; the FK when the name does not resolve). */
    public static function managerMap(?int $tid): array
    {
        $k = (int) $tid;
        if (isset(self::$maps[$k])) {
            return self::$maps[$k];
        }
        $map = [];
        $people = [];
        try {
            $hasName = Schema::hasColumn('employees', 'reporting_manager');
            $hasId = Schema::hasColumn('employees', 'reporting_manager_id');
            $cols = array_values(array_filter(['id', 'name', 'emp_code', $hasName ? 'reporting_manager' : null, $hasId ? 'reporting_manager_id' : null]));
            $rows = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->get($cols);
            $byName = [];
            $byCode = [];
            foreach ($rows as $r) {
                $people[(int) $r->id] = ['code' => (string) $r->emp_code, 'name' => (string) $r->name];
                $n = strtolower(trim((string) $r->name));
                if ($n !== '') {
                    $byName[$n] = isset($byName[$n]) ? false : (int) $r->id;   // false = ambiguous name
                }
                $c = strtolower(trim((string) $r->emp_code));
                if ($c !== '') {
                    $byCode[$c] = (int) $r->id;
                }
            }
            foreach ($rows as $r) {
                $m = $hasName ? self::resolve((string) ($r->reporting_manager ?? ''), $byName, $byCode) : null;
                if ($m === null && $hasId && ! empty($r->reporting_manager_id)) {
                    $m = (int) $r->reporting_manager_id;
                }
                if ($m && $m !== (int) $r->id) {
                    $map[(int) $r->id] = $m;
                }
            }
        } catch (\Throwable $e) {
            // hierarchy is best-effort; an empty map just means "no reportees"
        }
        self::$people[$k] = $people;

        return self::$maps[$k] = $map;
    }

    /** "Ravi Teja", "EMP012", "Ravi Teja (EMP012)" → employee id, or null. */
    private static function resolve(string $s, array $byName, array $byCode): ?int
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(.*)\(([^()]+)\)\s*$/', $s, $m)) {
            $c = strtolower(trim($m[2]));
            if (isset($byCode[$c])) {
                return $byCode[$c];
            }
            $s = trim($m[1]);
        }
        $l = strtolower($s);
        if (! empty($byName[$l])) {
            return $byName[$l];
        }

        return $byCode[$l] ?? null;
    }

    /** Everyone below $empId, any depth (excludes $empId). Cycle-safe. */
    public static function downline(?int $empId, ?int $tid): array
    {
        if (! $empId) {
            return [];
        }
        $kids = [];
        foreach (self::managerMap($tid) as $e => $m) {
            $kids[$m][] = $e;
        }
        $out = [];
        $stack = $kids[$empId] ?? [];
        while ($stack) {
            $e = array_pop($stack);
            if ($e === $empId || isset($out[$e])) {
                continue;
            }
            $out[$e] = true;
            foreach ($kids[$e] ?? [] as $c) {
                $stack[] = $c;
            }
        }

        return array_keys($out);
    }

    /** Managers above $empId, nearest first. Cycle-safe. */
    public static function upline(?int $empId, ?int $tid): array
    {
        $map = self::managerMap($tid);
        $out = [];
        $seen = [(int) $empId => true];
        $cur = (int) $empId;
        while (isset($map[$cur]) && ! isset($seen[$map[$cur]])) {
            $cur = $map[$cur];
            $seen[$cur] = true;
            $out[] = $cur;
        }

        return $out;
    }

    public static function isAbove(?int $managerId, ?int $empId, ?int $tid): bool
    {
        return $managerId && $empId && in_array((int) $managerId, self::upline((int) $empId, $tid), true);
    }

    /** emp codes for a set of ids (same tenant). */
    public static function codes(array $ids, ?int $tid): array
    {
        self::managerMap($tid);
        $p = self::$people[(int) $tid] ?? [];

        return array_values(array_filter(array_map(fn ($i) => $p[(int) $i]['code'] ?? null, $ids), fn ($c) => $c !== null && $c !== ''));
    }

    /** The logged-in user's employee id (linked record, else email, else name). */
    public static function meId($user): ?int
    {
        if (! $user) {
            return null;
        }
        static $memo = [];
        if (array_key_exists($user->id, $memo)) {
            return $memo[$user->id];
        }
        $id = null;
        try {
            if (! empty($user->employee_id)) {
                $id = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->value('id');
            }
            if (! $id) {
                $tid = $user->tenant_id;
                $id = DB::table('employees')->whereNull('deleted_at')
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
                    ->value('id');
            }
        } catch (\Throwable $e) {
        }

        return $memo[$user->id] = $id ? (int) $id : null;
    }

    /** True when this login is limited to its own record + downline. */
    public static function teamScoped($user): bool
    {
        if (! $user || $user->hasAnyRole(['super_admin', 'admin'])) {
            return false;
        }
        $row = AppDataController::roleMatrixRow($user);
        if ($row && strcasecmp(trim((string) ($row['scope'] ?? '')), 'Self') === 0) {
            return true;
        }
        if ($user->hasAnyRole(['employee', 'field_agent'])) {
            return true;
        }
        $builtIn = $user->getRoleNames()->intersect(self::BUILT_IN)->isNotEmpty();

        return ! $builtIn && ! $row;   // custom role nobody configured → least privilege
    }

    /** Employee ids this login may see; null = everyone in the tenant. */
    public static function visibleIds($user): ?array
    {
        if (! self::teamScoped($user)) {
            return null;
        }
        $me = self::meId($user);
        if (! $me) {
            return [-1];
        }

        return array_merge([$me], self::downline($me, $user->tenant_id ? (int) $user->tenant_id : null));
    }

    /** Admin / Super Admin / HR Manager decide anything (unchanged rule). */
    public static function isApproverRole($user): bool
    {
        return $user && $user->hasAnyRole(['super_admin', 'admin', 'hr_manager']);
    }

    /**
     * May this login approve/reject a request raised by $targetEmpId?
     * $module = Roles & Permissions module key (leave, attendance, expenses, loans, commissions, employees, payroll…).
     */
    public static function canApprove($user, ?int $targetEmpId, ?int $approverId = null, ?string $module = null): bool
    {
        if (! $user) {
            return false;
        }
        if (self::isApproverRole($user)) {
            return true;
        }
        if ($module && ! self::matrixAllows($user, $module, 'approve')) {
            return false;
        }
        $me = self::meId($user);
        if ($me && $targetEmpId && (int) $targetEmpId === $me) {
            return false;   // never your own request
        }
        // A Company / Group scope role with Approve ticked approves the whole module.
        if ($module && ! self::teamScoped($user) && AppDataController::roleMatrixRow($user)) {
            return true;
        }
        if (! $me || ! $targetEmpId) {
            return false;
        }
        if ($approverId && (int) $approverId === $me) {
            return true;
        }

        return self::isAbove($me, (int) $targetEmpId, $user->tenant_id ? (int) $user->tenant_id : null);
    }

    /** Roles & Permissions tick for this login; no row = no restriction. */
    public static function matrixAllows($user, string $module, string $action): bool
    {
        if (! $user || $user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }
        $row = AppDataController::roleMatrixRow($user);
        if (! $row) {
            return true;
        }
        $p = (array) (($row['perms'] ?? [])[$module] ?? []);

        return ! empty($p[$action]);
    }

    /**
     * Write employees.reporting_manager_id from the reporting-manager name so
     * code that reads the FK (approver resolution, notifications) agrees with
     * the Directory. Called after employee saves and imports.
     */
    public static function syncManagerIds(?int $tid): void
    {
        try {
            if (! Schema::hasColumn('employees', 'reporting_manager_id') || ! Schema::hasColumn('employees', 'reporting_manager')) {
                return;
            }
            unset(self::$maps[(int) $tid]);
            $map = self::managerMap($tid);
            $rows = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->get(['id', 'reporting_manager', 'reporting_manager_id']);
            foreach ($rows as $r) {
                $want = $map[(int) $r->id] ?? null;
                $has = $r->reporting_manager_id ? (int) $r->reporting_manager_id : null;
                if (trim((string) $r->reporting_manager) === '') {
                    continue;   // only the name drives the FK; a blank name leaves it alone
                }
                if ($want !== $has) {
                    DB::table('employees')->where('id', $r->id)->update(['reporting_manager_id' => $want]);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /** Forget cached maps (tests / after writes in the same request). */
    public static function flush(): void
    {
        self::$maps = [];
        self::$people = [];
    }

    public static function selfTest(): void
    {
        // U1,U2 → U3 → U4 ; cycle 7↔8 must not hang
        self::$maps[999] = [1 => 3, 2 => 3, 3 => 4, 7 => 8, 8 => 7];
        self::$people[999] = [];
        $d = self::downline(4, 999);
        sort($d);
        assert($d === [1, 2, 3]);
        $d3 = self::downline(3, 999);
        sort($d3);
        assert($d3 === [1, 2]);
        assert(self::downline(1, 999) === []);
        assert(self::upline(1, 999) === [3, 4]);
        assert(self::isAbove(4, 1, 999) && self::isAbove(3, 2, 999) && ! self::isAbove(1, 3, 999) && ! self::isAbove(2, 1, 999));
        assert(self::upline(7, 999) === [8]);
        unset(self::$maps[999], self::$people[999]);
        $r = new \ReflectionMethod(self::class, 'resolve');
        assert($r->invoke(null, 'Ravi Teja (E12)', ['ravi teja' => false], ['e12' => 5]) === 5);
        assert($r->invoke(null, 'ravi teja', ['ravi teja' => false], []) === null);   // ambiguous name
        assert($r->invoke(null, 'Asha', ['asha' => 9], []) === 9);
    }
}
