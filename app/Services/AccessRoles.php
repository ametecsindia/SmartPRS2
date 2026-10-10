<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 7 Oct 2026 (Ejaz) — connects Settings → Roles & Permissions to people.
 *
 * Each employee carries a ROLE (employees.access_role: the role's label, e.g.
 * "Branch Manager"). It is set on the Directory form, in the sample file /
 * import, or on User Access, and it is the role of that employee's login.
 *
 * A login's role decides (a) its menu and server access — the ticks on that
 * role's Roles & Permissions row — and (b) its data scope: Self = own record
 * plus everyone reporting to them (Hierarchy), Company / Group = everyone.
 *
 * Built-in roles keep their system names (admin, hr_manager…); a role added
 * on Roles & Permissions becomes a login role of the SAME label, so the
 * matrix row and the login always match by name.
 */
class AccessRoles
{
    public const BUILT_IN = [
        'admin' => 'Admin',
        'hr_manager' => 'HR Manager',
        'accountant' => 'Accountant',
        'field_agent' => 'Field Agent',
        'employee' => 'Employee',
    ];

    /** Role labels defined on Roles & Permissions for this tenant. */
    public static function matrixLabels(?int $tid): array
    {
        try {
            $state = json_decode((string) DB::table('settings')->where('tenant_id', (int) $tid)->where('key', 'app_state')->value('value'), true);
            $out = [];
            foreach ((array) ($state['roles'] ?? []) as $r) {
                $l = is_array($r) ? trim((string) ($r['role'] ?? '')) : '';
                if ($l !== '' && strcasecmp($l, 'Super Admin') !== 0) {
                    $out[] = $l;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Every assignable role label: built-ins first, then the tenant's own. */
    public static function labels(?int $tid): array
    {
        $out = array_values(self::BUILT_IN);
        foreach (self::matrixLabels($tid) as $l) {
            if (! self::inList($l, $out)) {
                $out[] = $l;
            }
        }

        return $out;
    }

    private static function inList(string $l, array $list): bool
    {
        foreach ($list as $x) {
            if (strcasecmp(trim($x), trim($l)) === 0) {
                return true;
            }
        }

        return false;
    }

    /** "branch manager" / "hr_manager" / "HR Manager" → the canonical label, or null when unknown. */
    public static function canonical($v, ?int $tid): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (isset(self::BUILT_IN[strtolower($v)])) {
            return self::BUILT_IN[strtolower($v)];
        }
        foreach (self::labels($tid) as $l) {
            if (strcasecmp($l, $v) === 0) {
                return $l;
            }
        }

        return null;
    }

    /** Label → login (Spatie) role name; creates a custom role on first use. */
    public static function spatieName(string $label): string
    {
        $slug = array_search(strtolower($label), array_map('strtolower', self::BUILT_IN), true);
        if ($slug !== false) {
            return $slug;
        }
        try {
            \Spatie\Permission\Models\Role::findOrCreate($label);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable $e) {
        }

        return $label;
    }

    /** Current role label of a login. */
    public static function labelOf($user): string
    {
        return AppController::roleLabel($user->getRoleNames()->first());
    }

    /**
     * Give a login the role $label, with the guards that stop a lock-out:
     * never touch a Super Admin, never change your own role, never remove the
     * tenant's last Admin. Returns an error string, or null when applied/unchanged.
     */
    public static function assign($user, string $label, $actor = null): ?string
    {
        try {
            if (! $user) {
                return 'Login not found.';
            }
            if ($user->hasRole('super_admin')) {
                return 'A Super Admin login is not changed from here.';
            }
            if (strcasecmp(self::labelOf($user), $label) === 0) {
                return null;
            }
            if ($actor && (int) $actor->id === (int) $user->id) {
                return 'You cannot change your own role.';
            }
            if ($user->hasRole('admin') && strcasecmp($label, 'Admin') !== 0) {
                $admins = DB::table('model_has_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')
                    ->join('users as u', 'u.id', '=', 'mr.model_id')
                    ->where('r.name', 'admin')->where('mr.model_type', \App\Models\User::class)
                    ->when($user->tenant_id, fn ($q) => $q->where('u.tenant_id', $user->tenant_id))
                    ->count();
                if ($admins <= 1) {
                    return 'This is the only Admin login — make someone else Admin first.';
                }
            }
            $user->syncRoles([self::spatieName($label)]);

            return null;
        } catch (\Throwable $e) {
            return 'Role could not be applied: '.$e->getMessage();
        }
    }

    /**
     * Push employees.access_role onto their logins (linked by users.employee_id,
     * else the same email in the tenant). Blank roles are left alone.
     * Returns messages for rows that could not be applied.
     */
    public static function syncEmployees(?int $tid, ?array $empIds = null, $actor = null): array
    {
        $msgs = [];
        try {
            if (! Schema::hasColumn('employees', 'access_role') || ! Schema::hasTable('users')) {
                return [];
            }
            $emps = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->whereNotNull('access_role')->where('access_role', '!=', '')
                ->when($empIds !== null, fn ($q) => $q->whereIn('id', $empIds ?: [0]))
                ->get(['id', 'name', 'email', 'access_role']);
            foreach ($emps as $e) {
                $label = self::canonical($e->access_role, $tid);
                if (! $label) {
                    continue;
                }
                $u = \App\Models\User::query()->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->where(function ($q) use ($e) {
                        $q->where('employee_id', $e->id);
                        if (! empty($e->email)) {
                            $q->orWhere(fn ($w) => $w->whereNull('employee_id')->whereRaw('LOWER(email) = ?', [strtolower(trim($e->email))]));
                        }
                    })->first();
                if (! $u) {
                    continue;   // no login yet — the role is used when one is created
                }
                if ($err = self::assign($u, $label, $actor)) {
                    if (stripos($err, 'own role') === false) {
                        $msgs[] = $e->name.': '.$err;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return $msgs;
    }

    /** label => number of logins holding it (for the Roles & Permissions list). */
    public static function userCounts(?int $tid): array
    {
        $out = [];
        try {
            $rows = DB::table('model_has_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')
                ->join('users as u', 'u.id', '=', 'mr.model_id')
                ->where('mr.model_type', \App\Models\User::class)
                ->when($tid, fn ($q) => $q->where('u.tenant_id', $tid))
                ->groupBy('r.name')->selectRaw('r.name, COUNT(*) n')->get();
            foreach ($rows as $r) {
                $out[AppController::roleLabel($r->name)] = (int) $r->n;
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }
}
