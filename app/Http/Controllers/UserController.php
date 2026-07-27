<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Company user-login management (the in-app "Users" screen).
 *
 * Distinct from StaffController, which manages PLATFORM staff (tenant_id NULL).
 * This manages the login accounts INSIDE a tenant company: create/edit/activate,
 * assign a role (admin / hr_manager / field_agent / employee), optionally link
 * to an employee record, and invite by email (the new user sets their own
 * password via a link — admins never see it).
 *
 * Authority: Admin + HR Manager (and Super Admin). Tenant-scoped. Fail-soft.
 */
class UserController extends Controller
{
    /** Roles a company admin/HR may assign (super_admin is platform-only). */
    private const ASSIGNABLE = [
        'admin' => 'Admin',
        'hr_manager' => 'HR Manager',
        'field_agent' => 'Field Agent',
        'employee' => 'Employee',
        'accountant' => 'Accountant',   // rev 116: confirms collections before commission approval
    ];

    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']);
    }

    /**
     * Link unlinked users to employees by matching email (case-insensitive),
     * within the tenant. Cheap, idempotent, fail-soft — runs when the Users
     * screen loads so existing accounts get connected without a manual step.
     */
    private function backfillLinks($tid): void
    {
        try {
            $unlinked = DB::table('users')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('employee_id')->whereNotNull('email')
                ->get(['id', 'email']);
            if ($unlinked->isEmpty()) {
                return;
            }
            $empByEmail = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->whereNotNull('email')
                ->get(['id', 'email'])
                ->keyBy(fn ($e) => strtolower(trim($e->email)));
            foreach ($unlinked as $u) {
                $key = strtolower(trim((string) $u->email));
                if (isset($empByEmail[$key])) {
                    DB::table('users')->where('id', $u->id)->update([
                        'employee_id' => $empByEmail[$key]->id,
                        'updated_at' => now(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // best-effort; never block the screen
        }
    }

    public function list(Request $request)
    {
        try {
            abort_unless($this->canManage($request), 403);
            $tid = $request->user()->tenant_id;

            // One-time self-healing backfill: link any users that have no
            // employee_id yet to an employee in this tenant with the same email.
            $this->backfillLinks($tid);

            $users = DB::table('users')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->orderBy('name')->get(['id', 'name', 'email', 'employee_id', 'status', 'created_at']);

            // Role per user via Spatie pivot (guarded for schema differences).
            $roleByUser = [];
            if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
                $roleName = DB::table('roles')->pluck('name', 'id');
                $pivot = DB::table('model_has_roles')
                    ->where('model_type', 'App\\Models\\User')
                    ->whereIn('model_id', $users->pluck('id'))
                    ->get(['model_id', 'role_id']);
                foreach ($pivot as $p) {
                    $roleByUser[$p->model_id] = $roleName[$p->role_id] ?? '';
                }
            }

            // Employee name lookup for the "linked employee" column.
            $empNames = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->pluck('name', 'id');

            $self = $request->user()->id;
            $rows = $users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $roleByUser[$u->id] ?? '',
                'roleLabel' => self::ASSIGNABLE[$roleByUser[$u->id] ?? ''] ?? ucfirst(str_replace('_', ' ', $roleByUser[$u->id] ?? '')),
                'status' => $u->status ?? 'active',
                'employeeId' => $u->employee_id ? (int) $u->employee_id : null,
                'employee' => $u->employee_id ? ($empNames[$u->employee_id] ?? '') : '',
                'isSelf' => $u->id === $self,
            ])->values();

            // Employee options for the link dropdown (id + code + name).
            $employees = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->orderBy('name')
                ->get(['id', 'emp_code', 'name', 'email'])
                ->map(fn ($e) => ['id' => (int) $e->id, 'code' => $e->emp_code, 'name' => $e->name, 'email' => $e->email])
                ->values();

            return response()->json([
                'rows' => $rows,
                'roles' => self::ASSIGNABLE,
                'employees' => $employees,
                'canManage' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    public function save(Request $request)
    {
        try {
            abort_unless($this->canManage($request), 403);
            $tid = $request->user()->tenant_id;
            $v = $request->validate([
                'id' => ['nullable', 'integer'],
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:191'],
                'role' => ['required', 'string'],
                'status' => ['nullable', 'in:active,disabled'],
                'employee_id' => ['nullable', 'integer'],
            ]);
            if (! array_key_exists($v['role'], self::ASSIGNABLE)) {
                return response()->json(['ok' => false, 'error' => 'Invalid role.'], 422);
            }

            $email = strtolower(trim($v['email']));
            $id = $v['id'] ?? null;

            // Resolve the employee link: explicit choice wins; else auto-match an
            // employee in this tenant by email (so the link is set from day one).
            $employeeId = ! empty($v['employee_id']) ? (int) $v['employee_id'] : null;
            if ($employeeId) {
                $ok = DB::table('employees')->where('id', $employeeId)
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->exists();
                if (! $ok) {
                    $employeeId = null;   // ignore an employee from another tenant
                }
            }
            if (! $employeeId) {
                $match = DB::table('employees')->whereRaw('LOWER(email) = ?', [$email])
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->first();
                $employeeId = $match->id ?? null;
            }

            // Uniqueness across users (email is the login).
            $dupe = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])
                ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
            if ($dupe) {
                return response()->json(['ok' => false, 'error' => 'That email is already in use.'], 422);
            }

            if ($id) {
                $user = DB::table('users')->where('id', $id)
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
                if (! $user) {
                    return response()->json(['ok' => false, 'error' => 'User not found.'], 404);
                }
                DB::table('users')->where('id', $id)->update([
                    'name' => $v['name'],
                    'email' => $email,
                    'status' => $v['status'] ?? ($user->status ?? 'active'),
                    'employee_id' => $employeeId,
                    'updated_at' => now(),
                ]);
                $this->assignRole($id, $v['role']);

                return response()->json(['ok' => true, 'id' => $id, 'invited' => false]);
            }

            // New user: create WITHOUT a usable password, then email an invite to
            // set one (admins never see/choose the password).
            $newId = DB::table('users')->insertGetId([
                'tenant_id' => $tid,
                'name' => $v['name'],
                'email' => $email,
                'password' => bcrypt(Str::random(40)),   // unusable until they set it
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assignRole($newId, $v['role']);
            $this->sendInvite($request, $newId, $v['name'], $email, $tid);

            return response()->json(['ok' => true, 'id' => $newId, 'invited' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Resend the set-password invite for an existing user. */
    public function invite(Request $request, int $id)
    {
        try {
            abort_unless($this->canManage($request), 403);
            $tid = $request->user()->tenant_id;
            $user = DB::table('users')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $user) {
                return response()->json(['ok' => false, 'error' => 'User not found.'], 404);
            }
            $this->sendInvite($request, $user->id, $user->name, $user->email, $tid);

            return response()->json(['ok' => true, 'message' => 'Invite sent to '.$user->email]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Activate / deactivate a login (no hard delete — preserves audit trail). */
    public function setStatus(Request $request, int $id)
    {
        try {
            abort_unless($this->canManage($request), 403);
            $v = $request->validate(['status' => ['required', 'in:active,disabled']]);
            $tid = $request->user()->tenant_id;
            if ($id === $request->user()->id) {
                return response()->json(['ok' => false, 'error' => 'You cannot change your own account status.'], 422);
            }
            $n = DB::table('users')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->update(['status' => $v['status'], 'updated_at' => now()]);

            return response()->json(['ok' => (bool) $n, 'status' => $v['status']]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Manually set a user's password (Admin/HR). An alternative to the emailed
     * invite link — useful when email is down or for quick onboarding. The user
     * can still change it later from Account Settings.
     */
    public function setPassword(Request $request, int $id)
    {
        try {
            abort_unless($this->canManage($request), 403);
            $v = $request->validate([
                'password' => ['required', 'string', 'min:8'],
            ]);
            $tid = $request->user()->tenant_id;
            $user = DB::table('users')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $user) {
                return response()->json(['ok' => false, 'error' => 'User not found.'], 404);
            }
            DB::table('users')->where('id', $id)->update([
                'password' => \Illuminate\Support\Facades\Hash::make($v['password']),
                'updated_at' => now(),
            ]);
            // Invalidate any outstanding reset/invite tokens for this email.
            try {
                if (Schema::hasTable('user_password_resets')) {
                    DB::table('user_password_resets')->where('email', strtolower(trim($user->email)))
                        ->whereNull('used_at')->update(['used_at' => now()]);
                }
            } catch (\Throwable $e) {
                // non-fatal
            }

            return response()->json(['ok' => true, 'message' => 'Password set for '.$user->email]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Assign exactly one role to a user via Spatie (schema-guarded). */
    private function assignRole(int $userId, string $role): void
    {
        try {
            $user = \App\Models\User::find($userId);
            if ($user) {
                $user->syncRoles([$role]);
            }
        } catch (\Throwable $e) {
            // role pivot may be unavailable on some installs — non-fatal
        }
    }

    /**
     * Issue a set-password token (reuses AuthController's user_password_resets
     * table, 48-hour window for invites) and email the link.
     */
    private function sendInvite(Request $request, int $userId, string $name, string $email, $tid): void
    {
        try {
            if (! Schema::hasTable('user_password_resets')) {
                Schema::create('user_password_resets', function (Blueprint $t) {
                    $t->id();
                    $t->string('email')->index();
                    $t->string('token', 64)->index();
                    $t->timestamp('expires_at')->nullable();
                    $t->timestamp('used_at')->nullable();
                    $t->timestamps();
                });
            }
            $token = Str::random(48);
            DB::table('user_password_resets')->insert([
                'email' => $email,
                'token' => hash('sha256', $token),
                'expires_at' => now()->addHours(48),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $link = url('/reset-password/'.$token.'?email='.urlencode($email));
            $inviter = $request->user()->name ?? 'Your administrator';

            MailService::queue([
                'tenant_id' => $tid,
                'to' => $email,
                'to_name' => $name,
                'subject' => 'You have been invited to SmartPRS',
                'heading' => 'Welcome to SmartPRS',
                'intro' => $inviter.' has created a SmartPRS account for you. Set your password to get started — this link is valid for 48 hours.',
                'lines' => ['Login email' => $email],
                'body' => 'After setting your password you can sign in at the SmartPRS login page.',
                'cta_label' => 'Set your password',
                'cta_url' => $link,
                'kind' => 'user.invite',
            ]);
        } catch (\Throwable $e) {
            // invite email is best-effort
        }
    }
}
