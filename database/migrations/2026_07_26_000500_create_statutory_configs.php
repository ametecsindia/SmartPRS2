<?php

use App\Support\SchemaHelper;
use Illuminate\Database\Migrations\Migration;

/**
 * F1 — effective-dated, scoped statutory rate overrides.
 *
 * Purely additive: the existing tenant-wide `statutory_settings` blob keeps
 * working exactly as it does today, and a tenant with no rows here sees no
 * behaviour change at all. Idempotent, with a clean down().
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaHelper::ensureTable('statutory_configs', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('scope', 20)->default('tenant');   // company|state|branch|tenant
            $t->string('scope_value')->nullable();        // state or branch name
            $t->string('kind', 30);                       // pf|esi|pt|tds_salary|tds_incentive|tds_commission|lwf
            $t->text('payload')->nullable();              // JSON: rate key => value
            $t->date('effective_from')->nullable()->index();
            $t->string('note', 500)->nullable();
            $t->string('created_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'kind', 'effective_from']);
        });
    }

    public function down(): void
    {
        SchemaHelper::dropTableIfExists('statutory_configs');
    }
};
