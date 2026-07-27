<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev173 — Working Shifts module.
 *
 * 1) shifts table — named shift timings (General / Morning / Night…). The
 *    MasterController 'shifts' def self-creates this at runtime too; this
 *    migration guarantees it at INSTALL time (same rationale as rev154:
 *    on-demand creation can silently fail on client on-prem installs).
 *    Schema mirrors the master def types exactly.
 * 2) employees.shift — the employee's DEFAULT shift (name string, same
 *    pattern as the department/branch/team name columns).
 * 3) Seeds one 'General Shift' (09:30–18:30) PER TENANT if that tenant has
 *    no shifts yet — mirrors the previous hardcoded default, so behaviour
 *    is unchanged until an admin edits it.
 *
 * Every block is guarded (hasTable / hasColumn / exists) — safe no-op on
 * re-run and on installs where the table already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shifts')) {
            Schema::create('shifts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->string('name');
                $t->string('code')->nullable();
                $t->string('company_name')->nullable();
                $t->string('start_time')->nullable();     // 'HH:MM'
                $t->string('end_time')->nullable();       // 'HH:MM' — earlier than start = night shift
                $t->integer('grace_min')->nullable();     // overrides Late Policy grace when set
                $t->decimal('full_day_hours', 12, 2)->nullable();
                $t->decimal('half_day_hours', 12, 2)->nullable();
                $t->integer('break_budget')->nullable();  // minutes
                $t->decimal('night_allowance', 12, 2)->nullable();  // Rs per night worked
                $t->string('status')->nullable();
                $t->timestamps();
            });
        }

        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'shift')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->string('shift')->nullable();
            });
        }

        // Seed one General Shift per tenant (or a single tenant-less row on a
        // fresh install with no tenants yet) so dropdowns aren't empty and the
        // previous hardcoded 09:30–18:30 default stays visible/editable.
        try {
            $tenantIds = Schema::hasTable('tenants')
                ? DB::table('tenants')->pluck('id')->all()
                : [];
            if (! $tenantIds) {
                $tenantIds = [null];
            }
            foreach ($tenantIds as $tid) {
                $q = DB::table('shifts');
                $tid === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tid);
                if ($q->exists()) {
                    continue;
                }
                DB::table('shifts')->insert([
                    'tenant_id' => $tid,
                    'name' => 'General Shift',
                    'code' => 'GEN',
                    'start_time' => '09:30',
                    'end_time' => '18:30',
                    'grace_min' => null,
                    'full_day_hours' => null,
                    'half_day_hours' => null,
                    'break_budget' => null,
                    'night_allowance' => null,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // seeding is best-effort; the screen works empty
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive (project convention): shift data and the
        // employees.shift column are kept on rollback.
    }
};
