<?php

namespace Database\Seeders;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Comprehensive demo data for the database-backed parts of SmartPRS:
 *   • 3 group companies under the demo tenant (so per-company branding shows)
 *   • branches, departments, designations, teams (with managers + leaders)
 *   • ~36 employees spread across the companies, each with a full org hierarchy
 *     (branch / team / department / designation / reporting manager / team leader)
 *   • per-company branding (distinct colour + display name + tagline)
 *   • sample per-company SMTP entries + a tenant default (no passwords)
 *   • client tenants for the SaaS Platform → Tenants screen
 *
 * Payroll runs, payslips, TDS and attendance punches are generated on demand by
 * AppDataController / AttendanceReportController when the app is opened, so they
 * are NOT seeded here (avoids duplicating that logic).
 *
 * WIPE-AND-RESEED: clears the demo tenant's people/org/branding/SMTP first so a
 * re-run gives a clean, consistent set. Login users, plans and companies are
 * preserved (companies are reused / topped up to 3). Safe to run repeatedly:
 *   php artisan db:seed --class=DummyDataSeeder
 */
class DummyDataSeeder extends Seeder
{
    /**
     * rev 76: `php artisan demo:reset` sets this true so the DEMO tenant can be
     * (re)built on a LIVE platform without polluting the real SaaS panel with
     * fake client tenants (Exon/Storm/Numero Uno/Vimal).
     */
    public static bool $skipClientTenants = false;

    public function run(): void
    {
        $now = Carbon::now();

        // Prefer the dedicated demo tenant (subdomain 'demo'); fall back to the
        // first tenant only on a pure dev box seeded by DemoCompanySeeder.
        $tenantId = DB::table('tenants')->where('subdomain', 'demo')->value('id')
            ?: DB::table('tenants')->orderBy('id')->value('id');
        if (! $tenantId) {
            $this->command?->warn('Run the base DatabaseSeeder first (no demo tenant found).');

            return;
        }

        $this->ensureEmployeeColumns();

        // ---- Group companies (ensure 3 under the demo tenant) -----------------
        $companyDefs = [
            ['name' => 'Apex Collections Pvt. Ltd.', 'color' => '#f97316', 'tag' => 'Recovery, done right.'],
            ['name' => 'Sentinel Recovery Services', 'color' => '#2563eb', 'tag' => 'Trusted field collections.'],
            ['name' => 'Meridian Financial Solutions', 'color' => '#059669', 'tag' => 'Compliance-first recovery.'],
        ];
        $existing = DB::table('companies')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'name'])->values();
        $companyIds = [];
        foreach ($companyDefs as $i => $cd) {
            if (isset($existing[$i])) {
                $cid = $existing[$i]->id;
                DB::table('companies')->where('id', $cid)->update([
                    'name' => $cd['name'], 'color' => $cd['color'], 'status' => 'active', 'updated_at' => $now,
                ]);
            } else {
                $cid = DB::table('companies')->insertGetId([
                    'tenant_id' => $tenantId, 'name' => $cd['name'], 'type' => 'collections',
                    'color' => $cd['color'], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $companyIds[] = $cid;
        }

        // ---- Wipe the demo tenant's people / org / settings -------------------
        $empIds = DB::table('employees')->where('tenant_id', $tenantId)->pluck('id');
        if ($empIds->isNotEmpty()) {
            DB::table('employee_references')->whereIn('employee_id', $empIds)->delete();
        }
        foreach (['employees', 'teams', 'branches', 'designations', 'departments'] as $tbl) {
            DB::table($tbl)->where('tenant_id', $tenantId)->delete();
        }
        // rev 85b: commissions + their ledger/history wiped too — employees get
        // NEW ids on reseed, so leaving these behind creates "#115" orphans.
        foreach (['payroll_runs', 'payslips', 'attendance_logs', 'attendance_ratings', 'transfers', 'onboarding', 'leaves',
            'commissions', 'commission_payments', 'commission_logs', 'expenses', 'advances', 'loans', 'clawbacks', 'increments', 'exits', 'bonus_encashment'] as $tbl) {
            if (Schema::hasTable($tbl)) {
                DB::table($tbl)->where('tenant_id', $tenantId)->delete();
            }
        }

        // ---- Departments + designations (shared across the group) -------------
        $deptNames = ['Collections', 'Recovery', 'Legal', 'Tele-calling', 'Field Ops', 'HR & Admin', 'Accounts'];
        $deptIds = [];
        foreach ($deptNames as $d) {
            $deptIds[$d] = DB::table('departments')->insertGetId([
                'tenant_id' => $tenantId, 'company_id' => $companyIds[0], 'name' => $d,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $designations = ['Collections Executive', 'Senior Recovery Officer', 'Field Agent', 'Team Leader',
            'Branch Manager', 'Legal Counsel', 'Tele-caller', 'HR Executive', 'Accountant'];
        $desigIds = [];
        foreach ($designations as $dg) {
            $desigIds[$dg] = DB::table('designations')->insertGetId([
                'tenant_id' => $tenantId, 'name' => $dg, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // ---- Branches per company --------------------------------------------
        $branchCities = [
            ['Hyderabad HQ', 'Hyderabad', '500081'],
            ['Bengaluru Branch', 'Bengaluru', '560001'],
            ['Mumbai Branch', 'Mumbai', '400001'],
            ['Chennai Branch', 'Chennai', '600001'],
            ['Pune Branch', 'Pune', '411001'],
            ['Delhi Branch', 'New Delhi', '110001'],
        ];
        $branchByCompany = [];   // companyId => [ ['id'=>, 'name'=>], ... ]
        $bx = 0;
        foreach ($companyIds as $cid) {
            $branchByCompany[$cid] = [];
            for ($j = 0; $j < 2; $j++) {
                $b = $branchCities[$bx % count($branchCities)];
                $bx++;
                $bid = DB::table('branches')->insertGetId([
                    'tenant_id' => $tenantId, 'company_id' => $cid, 'name' => $b[0], 'city' => $b[1],
                    'pincode' => $b[2], 'created_at' => $now, 'updated_at' => $now,
                ]);
                $branchByCompany[$cid][] = ['id' => $bid, 'name' => $b[0]];
            }
        }

        // ---- Employees (names + ids) -----------------------------------------
        $first = ['Asha', 'Vikram', 'Priya', 'Rahul', 'Sneha', 'Arjun', 'Kavya', 'Rohit', 'Divya', 'Karthik',
            'Meena', 'Suresh', 'Pooja', 'Ananya', 'Vivek', 'Lakshmi', 'Manoj', 'Sridevi', 'Imran', 'Fatima',
            'Naveen', 'Deepa', 'Ravi', 'Swathi', 'Aditya', 'Nisha', 'Gopal', 'Rekha', 'Sanjay', 'Harini',
            'Tarun', 'Bhavana', 'Yusuf', 'Anjali', 'Prakash', 'Sangeeta'];
        $last = ['Rao', 'Singh', 'Sharma', 'Reddy', 'Patel', 'Verma', 'Nair', 'Iyer', 'Khan', 'Gupta',
            'Pillai', 'Menon', 'Das', 'Joshi', 'Mehta', 'Kapoor', 'Bose', 'Naidu', 'Chowdary', 'Shaikh'];
        $types = ['office', 'field', 'field', 'office', 'field'];
        $salaryTypes = ['only_salary', 'salary_commission', 'only_commission', 'only_salary'];
        // Mostly active; a couple on notice; no "exited" so they all show in attendance.
        $statuses = ['active', 'active', 'active', 'active', 'active', 'active', 'active', 'notice'];
        $teamNames = ['Alpha Squad', 'Bravo Squad', 'Charlie Squad', 'Delta Squad', 'Echo Squad', 'Foxtrot Squad'];

        $count = 36;
        $emps = [];   // index => ['id','code','name','companyId','branch','type','deptName','desigName']
        for ($i = 0; $i < $count; $i++) {
            $code = 'EMP'.str_pad((string) (100 + $i), 3, '0', STR_PAD_LEFT);
            $name = $first[$i % count($first)].' '.$last[($i * 3) % count($last)];
            $type = $types[$i % count($types)];
            $cid = $companyIds[$i % count($companyIds)];
            $branch = $branchByCompany[$cid][$i % count($branchByCompany[$cid])];
            $deptName = $deptNames[$i % count($deptNames)];
            $desigName = $designations[$i % count($designations)];
            $ctc = (240000 + ($i % 10) * 60000) + ($type === 'office' ? 120000 : 0);

            $id = DB::table('employees')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'company_id' => $cid,
                'emp_code' => $code,
                'name' => $name,
                'type' => $type,
                'ctc' => $ctc,
                'salary_type' => $salaryTypes[$i % count($salaryTypes)],
                'department_id' => $deptIds[$deptName],
                'designation_id' => $desigIds[$desigName],
                'branch_id' => $branch['id'],
                // Editable name-columns (used by the new hierarchy UI/reports).
                'department' => $deptName,
                'designation' => $desigName,
                'branch' => $branch['name'],
                'mobile' => '+9198'.str_pad((string) (10000000 + $i * 137), 8, '0', STR_PAD_LEFT),
                'email' => strtolower(str_replace(' ', '.', $name)).$i.'@'.['apex', 'sentinel', 'meridian'][$i % 3].'.in',
                'pan' => 'ABCDE'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT).'F',
                'uan' => '1002003'.str_pad((string) (10000 + $i), 5, '0', STR_PAD_LEFT),
                'doj' => $now->copy()->subDays(60 + $i * 17)->toDateString(),
                'status' => $statuses[$i % count($statuses)],
                'bank_name' => 'HDFC Bank',
                'bank_acc' => (string) (50100000000 + $i * 7919),
                'ifsc' => 'HDFC000'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $emps[$i] = [
                'id' => $id, 'code' => $code, 'name' => $name, 'companyId' => $cid, 'branch' => $branch,
            ];
        }

        // ---- Teams (one per branch) with a manager + leader from that branch --
        // Group employees by company+branch, then build a team for each.
        $byBranch = [];
        foreach ($emps as $idx => $e) {
            $byBranch[$e['companyId'].'#'.$e['branch']['id']][] = $idx;
        }
        $teamIdx = 0;
        foreach ($byBranch as $key => $members) {
            [$cid, $bid] = explode('#', $key);
            $managerIdx = $members[0];
            $leaderIdx = $members[count($members) > 1 ? 1 : 0];
            $teamName = $teamNames[$teamIdx % count($teamNames)];
            $teamIdx++;

            $teamId = DB::table('teams')->insertGetId([
                'tenant_id' => $tenantId, 'company_id' => (int) $cid, 'name' => $teamName, 'function' => 'Collections',
                'manager_id' => $emps[$managerIdx]['id'], 'leader_id' => $emps[$leaderIdx]['id'],
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);

            $mgrName = $emps[$managerIdx]['name'];
            $ldrName = $emps[$leaderIdx]['name'];
            foreach ($members as $mIdx) {
                DB::table('employees')->where('id', $emps[$mIdx]['id'])->update([
                    'team_id' => $teamId,
                    'team' => $teamName,
                    'reporting_manager_id' => $emps[$managerIdx]['id'],
                    'reporting_manager' => $mgrName,
                    'team_leader' => $ldrName,
                    'updated_at' => $now,
                ]);
            }
        }

        // ---- A couple of references per field employee ------------------------
        foreach ($emps as $idx => $e) {
            if ($idx % 3 !== 1) {   // ~1/3 of staff get references (field-ish)
                continue;
            }
            for ($r = 0; $r < 2; $r++) {
                DB::table('employee_references')->insert([
                    'employee_id' => $e['id'],
                    'name' => $first[($idx + $r * 5) % count($first)].' '.$last[($idx + $r) % count($last)],
                    'relation' => ['Father', 'Friend', 'Neighbour', 'Former Employer'][($idx + $r) % 4],
                    'mobile' => '+9197'.str_pad((string) (20000000 + $idx * 311 + $r), 8, '0', STR_PAD_LEFT),
                    'pan' => 'FGHIJ'.str_pad((string) (2000 + $idx + $r), 4, '0', STR_PAD_LEFT).'K',
                    'verify_email' => true, 'verify_sms' => true, 'verify_call' => $r === 0,
                    'verify_whatsapp' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // ---- Per-company branding (app_settings ckey='branding') -------------
        $brandMap = [];
        foreach ($companyDefs as $i => $cd) {
            $brandMap[(string) $companyIds[$i]] = [
                'display_name' => $cd['name'],
                'color' => $cd['color'],
                'logo' => '',                 // URL left blank — set your own in Company Branding
                'tagline' => $cd['tag'],
            ];
        }
        $this->putSetting($tenantId, 'branding', $brandMap);
        $this->putSetting(0, 'branding', $brandMap);   // also visible to super admin (null tenant → 0)

        // ---- Sample per-company SMTP + tenant default (no passwords) ----------
        $mailMap = [
            '0' => [
                'host' => 'smtp.zoho.in', 'port' => 587, 'username' => 'group@ametecs.in', 'password' => '',
                'encryption' => 'tls', 'from_address' => 'noreply@ametecs.in', 'from_name' => 'Ametecs Group',
            ],
        ];
        $domains = ['apex.in', 'sentinel.in', 'meridian.in'];
        foreach ($companyIds as $i => $cid) {
            $mailMap[(string) $cid] = [
                'host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'hr@'.$domains[$i], 'password' => '',
                'encryption' => 'tls', 'from_address' => 'payroll@'.$domains[$i],
                'from_name' => $companyDefs[$i]['name'],
            ];
        }
        $this->putSetting($tenantId, 'mail', $mailMap);

        // ---- Client tenants for the SaaS Platform → Tenants demo -------------
        // Skipped by demo:reset on a live platform (fake clients are dev-only).
        if (self::$skipClientTenants) {
            $this->command?->info('Seeded demo tenant data ('.count($emps).' employees) — client-tenant fakes skipped (live mode).');

            return;
        }
        $planId = DB::table('plans')->orderBy('id')->value('id');
        $clients = [
            ['Exon Recovery Services', 'exon', 'active', 320, 350, 28500, 'saas'],
            ['Storm Collections', 'storm', 'active', 280, 300, 24900, 'onprem'],
            ['Numero Uno Financial', 'numerouno', 'active', 410, 450, 36800, 'saas'],
            ['Vimal Enterprises', 'vimal', 'trial', 90, 100, 0, 'onprem'],
        ];
        foreach ($clients as [$cn, $sub, $status, $used, $lic, $mrr, $dep]) {
            if (DB::table('tenants')->where('subdomain', $sub)->exists()) {
                continue;
            }
            DB::table('tenants')->insert([
                'uuid' => (string) Str::uuid(), 'name' => $cn, 'plan_id' => $planId, 'status' => $status,
                'seats_used' => $used, 'seats_licensed' => $lic, 'mrr' => $mrr, 'deployment' => $dep,
                'owner_email' => 'owner@'.$sub.'.in', 'subdomain' => $sub,
                'created_at' => $now->copy()->subDays(rand(30, 400)), 'updated_at' => $now,
            ]);
        }

        $this->command?->info('Seeded '.count($emps).' employees across '.count($companyIds)
            .' group companies, with branches, teams, branding and sample SMTP.');
    }

    /** Mirror of AppDataController::ensureEmployeeColumns so the seeder can run standalone. */
    private function ensureEmployeeColumns(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }
        $cols = ['department', 'designation', 'branch', 'team', 'reporting_manager', 'team_leader'];
        $missing = array_values(array_filter($cols, fn ($c) => ! Schema::hasColumn('employees', $c)));
        if (! $missing) {
            return;
        }
        Schema::table('employees', function (Blueprint $t) use ($missing) {
            foreach ($missing as $c) {
                $t->string($c)->nullable();
            }
        });
    }

    /** Self-creating app_settings writer (matches ConfigController's table shape). */
    private function putSetting(int $tenantId, string $key, array $value): void
    {
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('ckey')->index();
                $t->longText('value')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'ckey']);
            });
        }
        DB::table('app_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'ckey' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
