<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge Base — database-backed help topics, grouped by category/module and
 * filtered by the viewer's role. Admins and Super Admins can add / edit / delete
 * topics from within the app. Defaults are lazy-seeded on first use.
 */
class KbController extends Controller
{
    private const ROLE_OPTIONS = ['all', 'super_admin', 'admin', 'hr_manager', 'field_agent', 'employee'];

    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['super_admin', 'admin']);
    }

    /** Create the kb_topics table on the fly if migrations were not run. */
    private function ensureTable(): void
    {
        if (Schema::hasTable('kb_topics')) {
            return;
        }
        Schema::create('kb_topics', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('category')->default('General');
            $t->string('icon')->default('fa-book-open');
            $t->string('title');
            $t->text('body')->nullable();
            $t->json('roles')->nullable();
            $t->integer('sort')->default(0);
            $t->timestamps();
        });
    }

    public function index(Request $request)
    {
        $this->ensureSeeded();
        $role = $request->user()->getRoleNames()->first() ?? 'employee';
        $manage = $this->canManage($request);

        $rows = DB::table('kb_topics')->orderBy('sort')->orderBy('id')->get();
        $categories = [];
        foreach ($rows as $r) {
            $roles = json_decode($r->roles ?: '[]', true) ?: ['all'];
            // Show articles relevant to the viewer's OWN role (+ 'all'), for
            // everyone — including admins/super-admins. (Previously managers saw
            // EVERY article, which cluttered a Super Admin's KB with tenant-only
            // how-tos.) Managers can still author/target other roles via the
            // role dropdown; visibility here follows the viewer's role.
            if (! (in_array('all', $roles, true) || in_array($role, $roles, true))) {
                continue; // not relevant to this role
            }
            $categories[$r->category] ??= ['name' => $r->category, 'icon' => $r->icon, 'topics' => []];
            $categories[$r->category]['topics'][] = [
                'id' => $r->id,
                'title' => $r->title,
                'roles' => $roles,
                'body' => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $r->body)), fn ($p) => $p !== '')),
            ];
        }

        // Only Super Admins may target the Super Admin tier; Admins see roles
        // at or below their own level when authoring articles.
        $roleOptions = self::ROLE_OPTIONS;
        if ($role !== 'super_admin') {
            $roleOptions = array_values(array_filter($roleOptions, fn ($r) => $r !== 'super_admin'));
        }

        return response()->json([
            'role' => $role,
            'canManage' => $manage,
            'roleOptions' => $roleOptions,
            'categories' => array_values($categories),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage($request), 403);
        $this->ensureTable();
        $data = $this->validateTopic($request);
        $data['created_at'] = now();
        $data['updated_at'] = now();
        $id = DB::table('kb_topics')->insertGetId($data);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->canManage($request), 403);
        $this->ensureTable();
        $data = $this->validateTopic($request);
        $data['updated_at'] = now();
        DB::table('kb_topics')->where('id', $id)->update($data);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $id)
    {
        abort_unless($this->canManage($request), 403);
        $this->ensureTable();
        DB::table('kb_topics')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Persist a new display order for articles (drag-free up/down reordering). */
    public function reorder(Request $request)
    {
        abort_unless($this->canManage($request), 403);
        $this->ensureTable();
        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            return response()->json(['ok' => false], 422);
        }
        $i = 0;
        foreach ($ids as $id) {
            DB::table('kb_topics')->where('id', (int) $id)->update(['sort' => $i++, 'updated_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    private function validateTopic(Request $request): array
    {
        $v = $request->validate([
            'category' => ['required', 'string', 'max:80'],
            'icon' => ['nullable', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string'],
            'roles' => ['array'],
            'roles.*' => ['string'],
            'sort' => ['nullable', 'integer'],
        ]);
        $roles = array_values(array_intersect($v['roles'] ?? ['all'], self::ROLE_OPTIONS)) ?: ['all'];

        return [
            'category' => $v['category'],
            'icon' => $v['icon'] ?: 'fa-book-open',
            'title' => $v['title'],
            'body' => $v['body'] ?? '',
            'roles' => json_encode($roles),
            'sort' => $v['sort'] ?? 0,
        ];
    }

    private function ensureSeeded(): void
    {
        $this->ensureTable();
        if (DB::table('kb_topics')->count() > 0) {
            return;
        }
        $sort = 0;
        foreach ($this->defaults() as $cat) {
            foreach ($cat['topics'] as $t) {
                DB::table('kb_topics')->insert([
                    'tenant_id' => null, 'category' => $cat['name'], 'icon' => $cat['icon'],
                    'title' => $t['title'], 'body' => implode("\n", $t['body']),
                    'roles' => json_encode($t['roles']), 'sort' => $sort++,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function defaults(): array
    {
        $ALL = ['all'];
        $MGMT = ['super_admin', 'admin', 'hr_manager'];
        $SELF = ['field_agent', 'employee'];

        return [
            ['name' => 'Getting Started', 'icon' => 'fa-circle-play', 'topics' => [
                ['title' => 'Welcome to SmartPRS', 'roles' => $ALL, 'body' => ['SmartPRS is your HRM, Payroll and workforce-compliance platform. What you can see and do depends on your role — Super Admin, Admin, HR Manager, Field Agent or Employee.', 'Use the left sidebar to navigate. Sections are grouped (Main, Employees, Attendance, Leave, Payroll, Statutory and more); some groups collapse — tap the header to open them.']],
                ['title' => 'Signing in & signing out', 'roles' => $ALL, 'body' => ['Open your SmartPRS web address, click Sign in, and enter your email and password. Tick “Keep me signed in” only on your personal device.', 'To leave, use the exit icon in the top bar. Always sign out on shared machines.']],
                ['title' => 'Finding your way around', 'roles' => $ALL, 'body' => ['The top bar shows the page title, a search box, the Company switcher (for multi-company groups) and your avatar.', 'On most list screens the orange button adds a record, the pencil edits a row, and the trash deletes it. Many lists also have Sample, Import and Export for bulk CSV work.']],
                ['title' => 'Using SmartPRS on a phone', 'roles' => $ALL, 'body' => ['On a phone the menu collapses. Tap the menu button at the top-left to open it, and tap the dark overlay or a menu item to close it. Cards stack and tables scroll sideways so everything stays usable in the field.']],
            ]],
            ['name' => 'Employees', 'icon' => 'fa-users', 'topics' => [
                ['title' => 'The employee Directory', 'roles' => $MGMT, 'body' => ['Employees → Directory is the master list for the selected company. Use search and the Type filter to find people. Each row has a payslip PDF, an edit pencil and a delete action.']],
                ['title' => 'Adding & editing an employee', 'roles' => $MGMT, 'body' => ['Click Add Employee and fill the sections: Identity (code, name, PAN, Device User ID), Employment & Pay (department, type, CTC, PF number/UAN), Bank, and References for field staff.', 'For field agents add at least two references and verify each contact. Click Save Employee — the record is stored and appears in the Directory. To edit, click the pencil on any row.']],
                ['title' => 'Bulk-importing employees (CSV)', 'roles' => $MGMT, 'body' => ['On the Directory click Sample to download the CSV template (emp_code, name, type, ctc, salary_type, mobile, email, pan, uan, bank_acc, ifsc).', 'Fill one row per employee, then Import the file. Matching is by employee code — existing codes update, new ones are created.']],
                ['title' => 'Your profile & payslip', 'roles' => $SELF, 'body' => ['You can view your own profile and download your monthly payslips. If something is wrong (bank, PAN, address), contact your HR team to update it.']],
            ]],
            ['name' => 'Attendance', 'icon' => 'fa-fingerprint', 'topics' => [
                ['title' => 'Marking daily attendance', 'roles' => $MGMT, 'body' => ['Open Attendance, pick the date, then “Mark all present” or set each person Present / Absent / Leave. Use Manual Entry to correct a missed punch.', 'Absences become loss-of-pay in payroll automatically, so keep attendance accurate before running salaries.']],
                ['title' => 'Biometric (ZKTeco) sync', 'roles' => $MGMT, 'body' => ['Register machines under Settings → Biometric Devices (name, IP, port 4370, location), and match each employee’s Device User ID to their enrolment on the device.', 'Click Sync to pull punches over the LAN. If you see “unreachable”, the device is off or on another network.']],
                ['title' => 'Geo-fence for field staff', 'roles' => ['super_admin', 'admin', 'hr_manager', 'field_agent'], 'body' => ['Field agents’ app attendance is location-verified. Each agent has a start point, an allowed radius and a strictness setting. Punches outside the fence are rejected.']],
                ['title' => 'Your attendance', 'roles' => $SELF, 'body' => ['Mark your attendance from the app within your allowed location. Your monthly attendance feeds your salary, so flag any errors to HR quickly.']],
            ]],
            ['name' => 'Leave', 'icon' => 'fa-plane-departure', 'topics' => [
                ['title' => 'Applying for leave', 'roles' => $ALL, 'body' => ['Leave → Apply Leave: choose the type (Casual/Sick/Earned/Unpaid), dates and a reason. Days are counted automatically. Your request shows as Pending until a manager acts on it.']],
                ['title' => 'Approving or rejecting leave', 'roles' => $MGMT, 'body' => ['From the Leave list, approve or reject pending requests. Approved Unpaid leave inside a payroll month becomes loss-of-pay in that run automatically.']],
            ]],
            ['name' => 'Payroll', 'icon' => 'fa-money-check-dollar', 'topics' => [
                ['title' => 'Generating payroll', 'roles' => $MGMT, 'body' => ['Payroll → Generate Payroll, pick the month, click Generate. SmartPRS computes each active employee’s gross (CTC ÷ 12), statutory deductions and net, and creates the run.', 'Review the salary sheet, then Finalize to lock it. The Salary Runs list keeps 12 months of history.']],
                ['title' => 'Payslips & history', 'roles' => $ALL, 'body' => ['Payroll → Payslips keeps a month-wise register. Use the Month dropdown to switch periods and download a branded PDF payslip for any month.']],
                ['title' => 'Loans, advances & deductions', 'roles' => $MGMT, 'body' => ['Record loans (with EMI and tenure) and salary advances; the EMI is auto-deducted in each payroll run until cleared. The Deductions Ledger shows every deduction by type.']],
            ]],
            ['name' => 'Indian Statutory', 'icon' => 'fa-landmark', 'topics' => [
                ['title' => 'PF & ESIC', 'roles' => $MGMT, 'body' => ['The PF/ESIC register shows each employee’s UAN and both shares: PF 12% + 12% on basic (capped at ₹15,000), ESI 0.75% + 3.25% when gross ≤ ₹21,000. Download PF and ESIC challans as PDFs.']],
                ['title' => 'TDS — salary & commission', 'roles' => $MGMT, 'body' => ['Salary TDS (Sec 192) uses the new regime (₹50k standard deduction, 87A rebate up to ₹7L, 4% cess). Commission TDS (Sec 194H) applies to the commission portion for agents.', 'The Commission TDS Deductee Register lists PAN, commission earned, rate, TDS deducted and net paid; no-PAN deductees are flagged.']],
                ['title' => 'TDS Returns (24Q) & filing status', 'roles' => $MGMT, 'body' => ['Indian Statutory → TDS Returns (24Q) is the quarter-wise filing register. Click the pencil to set a quarter’s status — Filed, Pending, On Hold or Not Filed — and record the deposited amount. Due dates: Q1 31 Jul, Q2 31 Oct, Q3 31 Jan, Q4 31 May.']],
            ]],
            ['name' => 'Field Force & Compliance', 'icon' => 'fa-shield-halved', 'topics' => [
                ['title' => 'DRA, PCC & authorizations', 'roles' => ['super_admin', 'admin', 'field_agent'], 'body' => ['Field staff must keep DRA certification and PCC current and hold valid bank/portfolio authorizations. Compliance Alerts flag anything expiring so you can renew in time.']],
                ['title' => 'Escalations', 'roles' => ['super_admin', 'admin', 'hr_manager', 'field_agent'], 'body' => ['Log bank/client escalations on the Escalation Desk with severity and priority, record the action taken, and close them when resolved. Review Emergency items daily.']],
            ]],
            ['name' => 'SaaS Platform', 'icon' => 'fa-building-user', 'topics' => [
                ['title' => 'Managing tenants', 'roles' => ['super_admin'], 'body' => ['SaaS Platform → Tenants lists every client company with plan, seats, MRR, deployment and status. Add, edit or suspend tenants here.']],
                ['title' => 'Plans, subscriptions & billing', 'roles' => ['super_admin'], 'body' => ['Define Plans (base + per-user price, features), manage Subscriptions, Invoices and Payments, and configure Payment Gateways in test or live mode.']],
                ['title' => 'Platform staff & landing page', 'roles' => ['super_admin'], 'body' => ['Add platform staff (separate from client users) under Platform Staff. Edit the public marketing website live from Landing Page (CMS).']],
            ]],
        ];
    }
}
