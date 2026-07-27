<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Feature tests get a fresh isolated DB each run; Unit tests are pure.
uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/* ----------------------------------------------------------------------------
 | Shared test helpers
 |--------------------------------------------------------------------------- */

/** Create a tenant + its first company; returns [tenantId, companyId]. */
function makeTenantCompany(): array
{
    $tid = DB::table('tenants')->insertGetId([
        'uuid' => (string) Str::uuid(), 'name' => 'Test Tenant '.Str::random(4),
        'status' => 'active', 'seats_used' => 0, 'seats_licensed' => 25, 'mrr' => 0,
        'deployment' => 'saas', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cid = DB::table('companies')->insertGetId([
        'tenant_id' => $tid, 'name' => 'Test Co', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$tid, $cid];
}

/** Create a user with a Spatie role (defaults to active). */
function makeUser(string $role, ?int $tenantId = null, string $status = 'active'): User
{
    Role::findOrCreate($role, 'web');
    $u = User::create([
        'name' => ucfirst($role).' User',
        'email' => $role.'_'.Str::random(6).'@test.local',
        'password' => bcrypt('password'),
        'tenant_id' => $tenantId,
    ]);
    if (Schema::hasColumn('users', 'status')) {
        DB::table('users')->where('id', $u->id)->update(['status' => $status]);
    }
    $u->assignRole($role);

    return $u->fresh();
}

/** Insert an active employee with a CTC; returns the employee id. */
function makeEmployee(int $tid, int $cid, string $code, float $ctc = 600000): int
{
    return DB::table('employees')->insertGetId([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $tid, 'company_id' => $cid,
        'emp_code' => $code, 'name' => 'Emp '.$code, 'ctc' => $ctc,
        'salary_type' => 'only_salary', 'type' => 'office', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Insert a billing plan; returns the plan id.
 *
 * Jun-2026 pricing model: $base ₹/month INCLUDES up to $seatMax employees;
 * employees beyond that are billed $perUser each per month. Defaults mirror
 * the published Growth plan (₹2,500 incl. 75, ₹50/extra).
 */
function makePlan(string $name = 'Growth', float $base = 2500, float $perUser = 50, ?int $seatMax = 75): int
{
    if (! Schema::hasColumn('plans', 'seat_max')) {
        Schema::table('plans', function ($t) {
            $t->integer('seat_max')->nullable();
        });
    }

    return DB::table('plans')->insertGetId([
        'name' => $name, 'base_price' => $base, 'per_user_price' => $perUser, 'seat_max' => $seatMax,
        'billing_cycle' => 'quarterly', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
