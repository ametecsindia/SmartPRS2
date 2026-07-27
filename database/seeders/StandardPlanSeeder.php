<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three published SmartPRS plans (Jun 2026 pricing). Runs on EVERY deploy
 * (demo and production) and is idempotent: it creates the plans if missing and
 * refreshes their pricing if they exist, never touching other plans.
 *
 * Model: base price per month INCLUDES up to seat_max employees; employees
 * beyond that are billed per_user_price each per month. All 16 modules are in
 * every plan (features lists every module group → plan gating, though parked,
 * can never hide anything). Minimum billing is quarterly; 6-month advance is
 * 10% off, annual is 25% off (applied in BillingController::computeAmount).
 */
class StandardPlanSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        $allModules = json_encode([
            'hiring', 'people', 'attendance', 'leave', 'payroll', 'compensation',
            'statutory', 'performance', 'learning', 'letters', 'field',
            'communication', 'reports', 'administration',
        ]);

        $plans = [
            ['name' => 'Starter', 'base_price' => 1000, 'per_user_price' => 60, 'seat_max' => 25],
            ['name' => 'Growth', 'base_price' => 2500, 'per_user_price' => 50, 'seat_max' => 75],
            ['name' => 'Professional', 'base_price' => 5000, 'per_user_price' => 40, 'seat_max' => 150],
        ];

        foreach ($plans as $p) {
            $row = [
                'base_price' => $p['base_price'],
                'per_user_price' => $p['per_user_price'],
                'billing_cycle' => 'quarterly',
                'status' => 'active',
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('plans', 'seat_max')) {
                $row['seat_max'] = $p['seat_max'];
            }
            if (Schema::hasColumn('plans', 'features')) {
                $row['features'] = $allModules;
            }

            $existing = DB::table('plans')->where('name', $p['name'])->first();
            if ($existing) {
                DB::table('plans')->where('id', $existing->id)->update($row);
            } else {
                $row['name'] = $p['name'];
                $row['created_at'] = now();
                DB::table('plans')->insert($row);
            }
        }

        $this->command?->info('StandardPlanSeeder: Starter / Growth / Professional plans up to date.');
    }
}
