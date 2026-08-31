<?php

/**
 * 28 Aug 2026 (Ejaz) — EMPLOYEE FIELD PARITY.
 *
 * The Employee form, the sample import file and the Self-Onboarding portal had
 * each been built against a different field list. Three of those differences
 * were not cosmetic: the same real-world value was being written into two
 * different columns depending on which path captured it, so the value was
 * present in the row but invisible to every screen that looked for it.
 *
 *   employees.aadhaar          <- Self-Onboarding "Aadhaar / National ID"
 *   employees.national_id      <- Employee form + import "NATIONAL ID / SSN"
 *
 *   employees.employment_type  <- HR console "Employment type"
 *   employees.employment_stage <- Employee form + import "EMPLOYMENT STAGE"
 *
 * This migration (a) guarantees every parity column exists, (b) copies the
 * orphaned values into the canonical column so existing records stop reading
 * blank, and (c) adds plain lookup indexes for the new duplicate checks in
 * EmployeeFieldRules (which run in PHP, NOT as unique indexes — an install that
 * already holds duplicates must still migrate).
 *
 * Written to be RE-RUNNABLE: MySQL DDL is not transactional, so a migration
 * that stops half-way is never recorded and is retried from the top. Every
 * step below is guarded by hasColumn / a WHERE that matches nothing the second
 * time, so a partial run repairs itself. See the same note on
 * 2026_01_01_000100_create_employees_table.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every column the three capture paths share. */
    private const PARITY_COLUMNS = [
        'national_id', 'category', 'esic_no', 'nationality', 'id_marks',
        'permanent_address', 'emergency_name', 'emergency_phone',
        'account_holder', 'bank_branch', 'team_leader', 'also_works_for',
        'employment_stage', 'dra_expiry', 'pcc_expiry',
    ];

    /** Columns worth an index now that they are checked for duplicates on every save. */
    private const LOOKUP_INDEXES = [
        'employees_pan_idx' => 'pan',
        'employees_national_id_idx' => 'national_id',
        'employees_uan_idx' => 'uan',
        'employees_bank_acc_idx' => 'bank_acc',
        'employees_esic_no_idx' => 'esic_no',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        // ---- (a) columns -------------------------------------------------
        // dra_expiry / pcc_expiry are real DATE columns on a fresh schema; only
        // add them as strings when this install predates them entirely.
        $missing = array_values(array_filter(
            self::PARITY_COLUMNS,
            fn ($c) => ! Schema::hasColumn('employees', $c)
        ));
        if ($missing) {
            Schema::table('employees', function (Blueprint $t) use ($missing) {
                foreach ($missing as $c) {
                    if ($c === 'dra_expiry' || $c === 'pcc_expiry') {
                        $t->date($c)->nullable();
                    } elseif ($c === 'permanent_address') {
                        $t->string($c, 500)->nullable();
                    } else {
                        $t->string($c)->nullable();
                    }
                }
            });
        }

        // ---- (b) rescue the orphaned values ------------------------------
        // Self-onboarded hires: the National ID they typed went to `aadhaar`,
        // which nothing outside the portal ever reads. Copy it across without
        // ever overwriting a value the form or the importer already set.
        if (Schema::hasColumn('employees', 'aadhaar') && Schema::hasColumn('employees', 'national_id')) {
            try {
                DB::table('employees')
                    ->whereNotNull('aadhaar')->where('aadhaar', '<>', '')
                    ->where(fn ($q) => $q->whereNull('national_id')->orWhere('national_id', ''))
                    ->update(['national_id' => DB::raw('aadhaar')]);
            } catch (\Throwable $e) {
                // best-effort: a failed backfill must not block the schema change
            }
        }

        // HR console: "Employment type" wrote employment_type, which no screen,
        // export or importer reads. Its value set was Permanent / Contract /
        // Probation / Intern; the canonical stage set is '' | probation |
        // internship (see EmployeeFieldRules::EMPLOYMENT_STAGE). Contract has no
        // stage equivalent and is deliberately left for HR to re-set rather than
        // guessed at.
        if (Schema::hasColumn('employees', 'employment_type') && Schema::hasColumn('employees', 'employment_stage')) {
            foreach (['Probation' => 'probation', 'Intern' => 'internship', 'Internship' => 'internship', 'Permanent' => ''] as $was => $now) {
                try {
                    DB::table('employees')
                        ->whereRaw('LOWER(employment_type) = ?', [strtolower($was)])
                        ->where(fn ($q) => $q->whereNull('employment_stage')->orWhere('employment_stage', ''))
                        ->update(['employment_stage' => $now]);
                } catch (\Throwable $e) {
                    // best-effort
                }
            }
        }

        // The portal has always asked for the account holder's name; until
        // 27 Aug it was never written. Where it is still blank and the employee
        // never supplied one, the account holder is the employee — but that is
        // an assumption, so it is NOT backfilled here. Left blank on purpose.

        // ---- (c) lookup indexes -----------------------------------------
        // Plain indexes, never UNIQUE: EmployeeFieldRules enforces uniqueness in
        // PHP so an install that already contains duplicates can still migrate
        // and then be cleaned up from the Directory.
        foreach (self::LOOKUP_INDEXES as $idx => $col) {
            if (! Schema::hasColumn('employees', $col)) {
                continue;
            }
            try {
                if (! $this->indexExists('employees', $idx)) {
                    Schema::table('employees', function (Blueprint $t) use ($col, $idx) {
                        $t->index($col, $idx);
                    });
                }
            } catch (\Throwable $e) {
                // an index is an optimisation, never a correctness requirement
            }
        }
    }

    /** True when $index already exists on $table (driver-tolerant). */
    private function indexExists(string $table, string $index): bool
    {
        try {
            $conn = Schema::getConnection();
            $driver = $conn->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $db = $conn->getDatabaseName();

                return DB::table('information_schema.statistics')
                    ->where('table_schema', $db)->where('table_name', $table)
                    ->where('index_name', $index)->exists();
            }
            if ($driver === 'sqlite') {
                return collect(DB::select("PRAGMA index_list('".$table."')"))
                    ->contains(fn ($r) => ($r->name ?? '') === $index);
            }
        } catch (\Throwable $e) {
            // fall through — assume absent and let the guarded create try
        }

        return false;
    }

    public function down(): void
    {
        // The backfill is not reversed: the values now in national_id /
        // employment_stage are the correct home for that data, and dropping the
        // columns would destroy records captured through the Employee form too.
        foreach (self::LOOKUP_INDEXES as $idx => $col) {
            try {
                if ($this->indexExists('employees', $idx)) {
                    Schema::table('employees', function (Blueprint $t) use ($idx) {
                        $t->dropIndex($idx);
                    });
                }
            } catch (\Throwable $e) {
            }
        }
    }
};
