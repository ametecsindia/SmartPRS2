<?php

use App\Support\SchemaHelper;
use Illuminate\Database\Migrations\Migration;

/**
 * F7/F6 — probation tracking columns on employees + a history table.
 *
 * Idempotent (SchemaHelper) and mirrors ProbationService::ensureSchema(), so the
 * app works whether or not this migration has run. down() removes the additive
 * columns and drops the history table.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaHelper::ensureColumns('employees', [
            'probation_months' => fn ($t) => $t->unsignedSmallInteger('probation_months')->nullable(),
            'probation_end' => fn ($t) => $t->date('probation_end')->nullable(),
            'probation_confirmed_on' => fn ($t) => $t->date('probation_confirmed_on')->nullable(),
            'probation_confirmed_by' => fn ($t) => $t->string('probation_confirmed_by')->nullable(),
        ]);

        SchemaHelper::ensureTable('probation_events', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('action', 30);            // confirmed|extended|reminded|config
            $t->string('details', 1000)->nullable();
            $t->date('effective')->nullable();
            $t->string('by')->nullable();
            $t->timestamp('at')->useCurrent();
        });
    }

    public function down(): void
    {
        SchemaHelper::dropTableIfExists('probation_events');
        SchemaHelper::dropColumnsIfExist('employees', [
            'probation_months', 'probation_end', 'probation_confirmed_on', 'probation_confirmed_by',
        ]);
    }
};
