<?php

use App\Support\SchemaHelper;
use Illuminate\Database\Migrations\Migration;

/**
 * F5 / rev182 — storage for the payroll period.
 *
 *  pay_cycles.period_end_day  NULL = calendar month, 20 = the 21st→20th window.
 *                             The ENGINE never hardcodes 20; a future 25→24
 *                             client is one dropdown entry and no code change.
 *  pay_cycles.effective_from  so a mid-year switch never retro-changes a closed
 *                             month (read via EffectiveDated).
 *  payroll_runs / payslips    period_start + period_end, so a run regenerates
 *                             from its STORED window and payslips can print
 *                             "Salary Period: 21 Jun – 20 Jul 2026".
 *
 * Purely additive and idempotent — existing rows keep NULL, which means
 * calendar month, i.e. exactly today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaHelper::ensureColumns('pay_cycles', [
            'period_end_day' => fn ($t) => $t->unsignedTinyInteger('period_end_day')->nullable(),
            'effective_from' => fn ($t) => $t->date('effective_from')->nullable(),
        ]);
        foreach (['payroll_runs', 'payslips'] as $tbl) {
            SchemaHelper::ensureColumns($tbl, [
                'period_start' => fn ($t) => $t->date('period_start')->nullable(),
                'period_end' => fn ($t) => $t->date('period_end')->nullable(),
            ]);
        }
    }

    public function down(): void
    {
        SchemaHelper::dropColumnsIfExist('pay_cycles', ['period_end_day', 'effective_from']);
        foreach (['payroll_runs', 'payslips'] as $tbl) {
            SchemaHelper::dropColumnsIfExist($tbl, ['period_start', 'period_end']);
        }
    }
};
