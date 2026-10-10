<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 30 Sep 2026 (Ejaz) — ASSET STOCK + ASSET ISSUE & RETURNS for SmartPRS 2.
 *
 * Two screens under Administration (boot JS lives in public/js/smartprs-assets.js):
 *
 *   Asset Stock           — every physical unit on the shelf (one row per unit, own
 *                           Asset ID + serial). Add in bulk: category, make/model,
 *                           qty, cost, serial numbers one per line → N units with
 *                           auto IDs (KBD-0001, CPU-0015 …).
 *   Asset Issue & Returns — hand out what is available, take it back when it returns.
 *                           ONE issue can carry SEVERAL items for the same employee
 *                           ("+ Add another item"); they share an issue reference.
 *                           Returning puts the unit back into stock (or Under repair /
 *                           Lost, by the condition it came back in).
 *
 * Tables are self-created here (ensure()) AND by migration
 * 2026_09_30_000100_create_asset_mgmt_tables.php — both create only what is missing.
 * The older "Asset Register" master (table `assets`) is untouched.
 *
 * Routes: routes/assets.php (mounted from bootstrap/app.php — web.php is never rewritten).
 */
class AssetController extends Controller
{
    private const STATUSES = ['available', 'issued', 'repair', 'lost', 'retired'];

    private const CONDITIONS = ['new', 'good', 'fair', 'damaged', 'lost'];

    private const PREFIX = [
        'laptop' => 'LAP', 'desktop' => 'DSK', 'cpu' => 'CPU', 'keyboard' => 'KBD', 'mouse' => 'MSE',
        'monitor' => 'MON', 'mobile' => 'MOB', 'phone' => 'MOB', 'sim' => 'SIM', 'sim card' => 'SIM',
        'headset' => 'HDS', 'headphone' => 'HDS', 'tablet' => 'TAB', 'printer' => 'PRN', 'id card' => 'IDC',
        'access card' => 'ACC', 'charger' => 'CHG', 'router' => 'RTR', 'dongle' => 'DGL', 'webcam' => 'CAM',
        'ups' => 'UPS', 'chair' => 'CHR', 'bag' => 'BAG', 'vehicle' => 'VEH', 'bike' => 'VEH',
    ];

    // ------------------------------------------------------------------ schema

    public static function ensure(): void
    {
        try {
            if (! Schema::hasTable('asset_items')) {
                Schema::create('asset_items', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->unsignedBigInteger('company_id')->nullable()->index();
                    $t->string('asset_code', 40)->index();          // KBD-0001
                    $t->string('category', 80)->index();            // Keyboard
                    $t->string('make_model', 160)->nullable();      // Logitech K120
                    $t->string('serial_no', 120)->nullable()->index();
                    $t->decimal('cost', 12, 2)->default(0);
                    $t->date('purchase_date')->nullable();
                    $t->string('vendor', 160)->nullable();
                    $t->string('invoice_no', 80)->nullable();
                    $t->string('status', 16)->default('available')->index(); // available|issued|repair|lost|retired
                    $t->string('condition', 16)->default('new');
                    $t->string('notes', 255)->nullable();
                    $t->string('created_by', 120)->nullable();
                    $t->timestamps();
                    $t->unsignedBigInteger('legacy_asset_id')->nullable()->index(); // old Asset Register row (table `assets`)
                    $t->softDeletes();
                });
            }
            if (! Schema::hasColumn('asset_items', 'legacy_asset_id')) {
                Schema::table('asset_items', function (Blueprint $t) {
                    $t->unsignedBigInteger('legacy_asset_id')->nullable()->index();
                });
            }
            if (! Schema::hasTable('asset_issues')) {
                Schema::create('asset_issues', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->string('issue_ref', 30)->index();           // ISS-000012 — shared by items issued together
                    $t->unsignedBigInteger('asset_item_id')->index();
                    $t->unsignedBigInteger('employee_id')->nullable()->index();
                    $t->string('emp_code', 60)->index();
                    $t->string('emp_name', 160)->nullable();
                    $t->date('issued_on');
                    $t->string('issue_condition', 16)->nullable();
                    $t->string('purpose', 255)->nullable();
                    $t->string('issued_by', 120)->nullable();
                    $t->date('returned_on')->nullable();
                    $t->string('return_condition', 16)->nullable();
                    $t->string('return_remarks', 255)->nullable();
                    $t->string('returned_by', 120)->nullable();
                    $t->string('status', 12)->default('issued')->index(); // issued|returned
                    $t->timestamps();
                });
            }
        } catch (\Throwable $e) {
            Log::warning('asset tables ensure: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------ helpers

    private function guard(Request $request): void
    {
        abort_unless($request->user() && $request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']), 403);
        self::ensure();
        self::importLegacy();
    }

    /**
     * 30 Sep 2026 (Ejaz) — the old "Asset Register" menu is gone. Its rows (table
     * `assets`, MasterController) are brought into Asset Stock ONCE each, keyed by
     * asset_items.legacy_asset_id, so nothing recorded there disappears:
     *   issued  → unit status Issued + an open issue for that employee (ref LEGACY)
     *   returned/blank → Available · damaged → Under repair · lost → Lost
     * The old table itself is never modified. Safe to run on every request: it
     * only looks at rows not yet imported.
     */
    public static function importLegacy(): void
    {
        try {
            if (! Schema::hasTable('assets') || ! Schema::hasTable('asset_items')) {
                return;
            }
            $done = DB::table('asset_items')->whereNotNull('legacy_asset_id')->pluck('legacy_asset_id')->all();
            $q = DB::table('assets');
            if ($done) {
                $q->whereNotIn('id', $done);
            }
            if (Schema::hasColumn('assets', 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            $rows = $q->orderBy('id')->limit(2000)->get();
            if ($rows->isEmpty()) {
                return;
            }
            $empCols = array_values(array_filter(['id', 'emp_code', 'name', 'tenant_id'], fn ($c) => Schema::hasColumn('employees', $c)));
            foreach ($rows as $a) {
                $v = fn ($k) => property_exists($a, $k) ? $a->{$k} : null;
                $tid = $v('tenant_id');
                $code = trim((string) ($v('asset_id') ?? ''));
                $cat = trim((string) ($v('asset_type') ?? '')) ?: 'Other';
                if ($code === '') {
                    $code = self::prefixFor($cat).'-L'.$a->id;
                }
                if (DB::table('asset_items')->where('asset_code', $code)->when($tid, fn ($x) => $x->where('tenant_id', $tid))->exists()) {
                    $code .= '-L'.$a->id;
                }
                $st = strtolower(trim((string) ($v('status') ?? '')));
                $emp = null;
                if ($st === 'issued' && Schema::hasTable('employees')) {
                    if ($v('issued_to_employee_id')) {
                        $emp = DB::table('employees')->where('id', $v('issued_to_employee_id'))->first($empCols);
                    }
                    if (! $emp && trim((string) $v('employee')) !== '') {
                        $name = trim((string) $v('employee'));
                        $emp = DB::table('employees')->when($tid, fn ($x) => $x->where('tenant_id', $tid))->whereNull('deleted_at')
                            ->where(fn ($x) => $x->where('emp_code', $name)->orWhere('name', $name))->first($empCols);
                    }
                }
                $status = $st === 'issued' ? ($emp ? 'issued' : 'available')
                    : ($st === 'damaged' ? 'repair' : ($st === 'lost' ? 'lost' : 'available'));
                DB::transaction(function () use ($a, $v, $tid, $code, $cat, $status, $emp, $st) {
                    $itemId = DB::table('asset_items')->insertGetId([
                        'tenant_id' => $tid,
                        'company_id' => $v('company_id'),
                        'asset_code' => mb_substr($code, 0, 40),
                        'category' => mb_substr($cat, 0, 80),
                        'cost' => 0,
                        'status' => $status,
                        'condition' => $st === 'damaged' ? 'damaged' : ($st === 'lost' ? 'lost' : 'good'),
                        'notes' => ($st === 'issued' && ! $emp) ? mb_substr('From old Asset Register — was issued to "'.$v('employee').'" (employee not found)', 0, 255) : 'From old Asset Register',
                        'created_by' => 'Asset Register import',
                        'legacy_asset_id' => $a->id,
                        'created_at' => $v('created_at') ?? now(),
                        'updated_at' => now(),
                    ]);
                    if ($status === 'issued' && $emp) {
                        DB::table('asset_issues')->insert([
                            'tenant_id' => $tid,
                            'issue_ref' => 'LEGACY',
                            'asset_item_id' => $itemId,
                            'employee_id' => $emp->id,
                            'emp_code' => $emp->emp_code ?? '',
                            'emp_name' => $emp->name ?? '',
                            'issued_on' => $v('issue_date') ?: (($v('created_at') ? substr((string) $v('created_at'), 0, 10) : null) ?: now()->toDateString()),
                            'issue_condition' => 'good',
                            'purpose' => 'From old Asset Register',
                            'issued_by' => 'Asset Register import',
                            'status' => 'issued',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
            }
        } catch (\Throwable $e) {
            Log::warning('asset legacy import: '.$e->getMessage());
        }
    }

    /** Tenant for reads (null = platform super admin → unscoped). */
    private function tid(Request $request): ?int
    {
        $t = $request->user()->tenant_id;

        return $t ? (int) $t : null;
    }

    /** Tenant for writes — on-prem logins may carry no tenant_id. */
    private function wtid(Request $request): ?int
    {
        $t = $request->user()->tenant_id ?? (Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null);

        return $t ? (int) $t : null;
    }

    private function items(Request $request)
    {
        $tid = $this->tid($request);

        return DB::table('asset_items')->whereNull('deleted_at')->when($tid, fn ($q) => $q->where('tenant_id', $tid));
    }

    private function issues(Request $request)
    {
        $tid = $this->tid($request);

        return DB::table('asset_issues')->when($tid, fn ($q) => $q->where('tenant_id', $tid));
    }

    private function who(Request $request): string
    {
        return (string) ($request->user()->name ?? $request->user()->email ?? 'admin');
    }

    private static function prefixFor(string $category): string
    {
        $k = strtolower(trim($category));
        if (isset(self::PREFIX[$k])) {
            return self::PREFIX[$k];
        }
        $k = rtrim($k, 's');
        if (isset(self::PREFIX[$k])) {
            return self::PREFIX[$k];
        }
        $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $category));

        return substr($p !== '' ? $p : 'AST', 0, 3);
    }

    private function nextNumber(?int $tid, string $prefix): int
    {
        $codes = DB::table('asset_items')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->where('asset_code', 'like', $prefix.'-%')->pluck('asset_code');
        $max = 0;
        foreach ($codes as $c) {
            if (preg_match('/-(\d+)$/', (string) $c, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }

    /**
     * Active employees with their org details, keyed by emp_code. Tolerant of
     * missing hierarchy columns (same approach as AttendanceReportController).
     */
    private function employeeDirectory(?int $tid, bool $activeOnly = true): array
    {
        if (! Schema::hasTable('employees')) {
            return [];
        }
        $want = ['id', 'emp_code', 'name', 'company_id', 'branch_id', 'department_id', 'designation_id',
            'reporting_manager_id', 'designation', 'status', 'archived_at'];
        $cols = array_values(array_filter($want, fn ($c) => Schema::hasColumn('employees', $c)));
        $has = fn ($c) => in_array($c, $cols, true);
        $names = function (string $table) {
            return Schema::hasTable($table) ? DB::table($table)->pluck('name', 'id') : collect();
        };
        $companies = $names('companies');
        $branches = $names('branches');
        $depts = $names('departments');
        $desigs = $names('designations');

        $q = DB::table('employees')->when($tid, fn ($x) => $x->where('tenant_id', $tid))->whereNull('deleted_at');
        $all = $q->get($cols);
        $idName = [];
        $idCode = [];
        foreach ($all as $e) {
            $idName[$e->id] = $e->name ?? '';
            $idCode[$e->id] = $e->emp_code ?? '';
        }
        $out = [];
        foreach ($all as $e) {
            if (empty($e->emp_code)) {
                continue;
            }
            $st = strtolower((string) ($has('status') ? ($e->status ?? '') : ''));
            $active = ! in_array($st, ['inactive', 'exited'], true) && ! ($has('archived_at') && ! empty($e->archived_at));
            if ($activeOnly && ! $active) {
                continue;
            }
            $rm = ($has('reporting_manager_id') && $e->reporting_manager_id) ? $e->reporting_manager_id : null;
            $out[$e->emp_code] = [
                'id' => $e->id,
                'code' => $e->emp_code,
                'name' => $e->name ?? '',
                'company' => $has('company_id') ? ($companies[$e->company_id] ?? '') : '',
                'company_id' => $has('company_id') ? $e->company_id : null,
                'branch' => ($has('branch_id') && $e->branch_id) ? ($branches[$e->branch_id] ?? '') : '',
                'department' => ($has('department_id') && $e->department_id) ? ($depts[$e->department_id] ?? '') : '',
                'designation' => (($has('designation_id') && $e->designation_id) ? ($desigs[$e->designation_id] ?? '') : '')
                    ?: ($has('designation') ? (string) ($e->designation ?? '') : ''),
                'manager' => $rm ? trim(($idCode[$rm] ?? '').' '.($idName[$rm] ?? '')) : '',
                'active' => $active,
            ];
        }

        return $out;
    }

    private function itemRow(object $i, array $holder = []): array
    {
        return [
            'id' => (int) $i->id,
            'code' => $i->asset_code,
            'category' => $i->category,
            'make_model' => $i->make_model,
            'serial' => $i->serial_no,
            'cost' => (float) $i->cost,
            'purchase_date' => $i->purchase_date,
            'vendor' => $i->vendor,
            'invoice_no' => $i->invoice_no,
            'status' => $i->status,
            'condition' => $i->condition,
            'notes' => $i->notes,
            'holder' => $holder ?: null,
        ];
    }

    // ------------------------------------------------------------------ stock

    /** GET /app/asset-mgmt/stock — all units + summary. */
    public function stock(Request $request)
    {
        $this->guard($request);
        $items = $this->items($request)->orderBy('category')->orderBy('asset_code')->get();
        $open = $this->issues($request)->where('status', 'issued')->get()->keyBy('asset_item_id');
        $rows = [];
        $sum = ['total' => 0, 'value' => 0.0];
        foreach (self::STATUSES as $s) {
            $sum[$s] = 0;
        }
        $cats = [];
        foreach ($items as $i) {
            $iss = $open[$i->id] ?? null;
            $rows[] = $this->itemRow($i, $iss ? ['issue_id' => (int) $iss->id, 'code' => $iss->emp_code, 'name' => $iss->emp_name, 'since' => $iss->issued_on, 'ref' => $iss->issue_ref] : []);
            $sum['total']++;
            $sum['value'] += (float) $i->cost;
            $sum[$i->status] = ($sum[$i->status] ?? 0) + 1;
            $c = $i->category;
            if (! isset($cats[$c])) {
                $cats[$c] = ['category' => $c, 'total' => 0, 'available' => 0, 'issued' => 0, 'other' => 0, 'value' => 0.0];
            }
            $cats[$c]['total']++;
            $cats[$c]['value'] += (float) $i->cost;
            if ($i->status === 'available' || $i->status === 'issued') {
                $cats[$c][$i->status]++;
            } else {
                $cats[$c]['other']++;
            }
        }

        return response()->json(['ok' => true, 'rows' => $rows, 'summary' => $sum, 'categories' => array_values($cats)]);
    }

    /**
     * POST /app/asset-mgmt/stock
     *   add:  {category, make_model, qty, cost, purchase_date, vendor, invoice_no, serials: "a\nb", prefix?, notes}
     *   edit: {id, category, make_model, serial, cost, purchase_date, vendor, invoice_no, status, condition, notes}
     */
    public function saveStock(Request $request)
    {
        $this->guard($request);
        $tid = $this->wtid($request);
        $id = (int) $request->input('id', 0);
        $category = trim((string) $request->input('category', ''));
        if ($category === '') {
            return response()->json(['ok' => false, 'error' => 'Category is required.'], 422);
        }
        $cost = round((float) $request->input('cost', 0), 2);
        $date = $request->input('purchase_date') ?: null;
        $base = [
            'category' => mb_substr($category, 0, 80),
            'make_model' => mb_substr(trim((string) $request->input('make_model', '')), 0, 160) ?: null,
            'cost' => max(0, $cost),
            'purchase_date' => $date,
            'vendor' => mb_substr(trim((string) $request->input('vendor', '')), 0, 160) ?: null,
            'invoice_no' => mb_substr(trim((string) $request->input('invoice_no', '')), 0, 80) ?: null,
            'notes' => mb_substr(trim((string) $request->input('notes', '')), 0, 255) ?: null,
            'updated_at' => now(),
        ];

        if ($id) {
            $item = $this->items($request)->where('id', $id)->first();
            if (! $item) {
                return response()->json(['ok' => false, 'error' => 'Item not found.'], 404);
            }
            $status = (string) $request->input('status', $item->status);
            if (! in_array($status, self::STATUSES, true)) {
                $status = $item->status;
            }
            // "Issued" only ever comes from an issue; an issued unit changes state only by being returned.
            if ($item->status === 'issued' && $status !== 'issued') {
                return response()->json(['ok' => false, 'error' => 'This item is with an employee. Return it first.'], 422);
            }
            if ($item->status !== 'issued' && $status === 'issued') {
                return response()->json(['ok' => false, 'error' => 'Use Asset Issue & Returns to hand an item out.'], 422);
            }
            $cond = (string) $request->input('condition', $item->condition);
            DB::table('asset_items')->where('id', $id)->update($base + [
                'serial_no' => mb_substr(trim((string) $request->input('serial', '')), 0, 120) ?: null,
                'status' => $status,
                'condition' => in_array($cond, self::CONDITIONS, true) ? $cond : $item->condition,
            ]);

            return response()->json(['ok' => true, 'message' => $item->asset_code.' updated']);
        }

        $serials = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $request->input('serials', '')))));
        $qty = max((int) $request->input('qty', 1), count($serials), 1);
        if ($qty > 500) {
            return response()->json(['ok' => false, 'error' => 'Add at most 500 units at a time.'], 422);
        }
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('prefix', '')));
        $prefix = $prefix !== '' ? substr($prefix, 0, 8) : self::prefixFor($category);
        $companyId = $request->input('company_id') ?: (Schema::hasTable('companies')
            ? DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')
                ->when(Schema::hasColumn('companies', 'is_master'), fn ($q) => $q->orderByDesc('is_master'))->orderBy('id')->value('id')
            : null);

        $codes = [];
        DB::transaction(function () use ($tid, $prefix, $qty, $serials, $base, $companyId, $request, &$codes) {
            $n = $this->nextNumber($tid, $prefix);
            for ($k = 0; $k < $qty; $k++) {
                $code = $prefix.'-'.str_pad((string) ($n + $k), 4, '0', STR_PAD_LEFT);
                DB::table('asset_items')->insert($base + [
                    'tenant_id' => $tid,
                    'company_id' => $companyId,
                    'asset_code' => $code,
                    'serial_no' => isset($serials[$k]) ? mb_substr($serials[$k], 0, 120) : null,
                    'status' => 'available',
                    'condition' => 'new',
                    'created_by' => $this->who($request),
                    'created_at' => now(),
                ]);
                $codes[] = $code;
            }
        });

        return response()->json(['ok' => true, 'codes' => $codes,
            'message' => count($codes) === 1 ? ($codes[0].' added to stock') : (count($codes).' units added ('.$codes[0].' … '.end($codes).')')]);
    }

    /** POST /app/asset-mgmt/stock/{id}/delete — only a unit that was never issued. */
    public function deleteStock(Request $request, int $id)
    {
        $this->guard($request);
        $item = $this->items($request)->where('id', $id)->first();
        if (! $item) {
            return response()->json(['ok' => false, 'error' => 'Item not found.'], 404);
        }
        if ($this->issues($request)->where('asset_item_id', $id)->exists()) {
            return response()->json(['ok' => false, 'error' => $item->asset_code.' has issue history — mark it Retired instead of deleting.'], 422);
        }
        DB::table('asset_items')->where('id', $id)->update(['deleted_at' => now()]);

        return response()->json(['ok' => true, 'message' => $item->asset_code.' deleted']);
    }

    // ------------------------------------------------------------------ issue / return

    /** GET /app/asset-mgmt/employees — active employees for the picker. */
    public function employees(Request $request)
    {
        $this->guard($request);

        return response()->json(['ok' => true, 'rows' => array_values($this->employeeDirectory($this->tid($request)))]);
    }

    /** GET /app/asset-mgmt/issues?scope=open|returned|all&emp= */
    public function issueList(Request $request)
    {
        $this->guard($request);

        return response()->json(['ok' => true, 'rows' => $this->movementRows($request)]);
    }

    private function movementRows(Request $request): array
    {
        $scope = (string) $request->query('scope', 'open');
        $emp = trim((string) $request->query('emp', ''));
        $q = $this->issues($request)
            ->when($scope === 'open', fn ($x) => $x->where('status', 'issued'))
            ->when($scope === 'returned', fn ($x) => $x->where('status', 'returned'))
            ->when($emp !== '', fn ($x) => $x->where('emp_code', $emp))
            ->orderByDesc('issued_on')->orderByDesc('id');
        $iss = $q->get();
        $items = DB::table('asset_items')->whereIn('id', $iss->pluck('asset_item_id')->unique()->all())->get()->keyBy('id');
        $dir = $this->employeeDirectory($this->tid($request), false);
        $out = [];
        foreach ($iss as $r) {
            $i = $items[$r->asset_item_id] ?? null;
            $e = $dir[$r->emp_code] ?? [];
            $out[] = [
                'id' => (int) $r->id,
                'ref' => $r->issue_ref,
                'item_id' => (int) $r->asset_item_id,
                'code' => $i->asset_code ?? '—',
                'category' => $i->category ?? '',
                'make_model' => $i->make_model ?? '',
                'serial' => $i->serial_no ?? '',
                'cost' => $i ? (float) $i->cost : 0,
                'issued_on' => $r->issued_on,
                'emp_code' => $r->emp_code,
                'emp_name' => ($e['name'] ?? '') ?: (string) $r->emp_name,
                'designation' => $e['designation'] ?? '',
                'department' => $e['department'] ?? '',
                'manager' => $e['manager'] ?? '',
                'company' => $e['company'] ?? '',
                'branch' => $e['branch'] ?? '',
                'condition' => $r->issue_condition,
                'purpose' => $r->purpose,
                'issued_by' => $r->issued_by,
                'status' => $r->status,
                'returned_on' => $r->returned_on,
                'return_condition' => $r->return_condition,
                'return_remarks' => $r->return_remarks,
                'returned_by' => $r->returned_by,
            ];
        }

        return $out;
    }

    /**
     * POST /app/asset-mgmt/issue
     *   {emp_code, issued_on, purpose, items: [{id, condition, purpose?}, …]}
     * Several items for one employee in one go; all-or-nothing.
     */
    public function issue(Request $request)
    {
        $this->guard($request);
        $tid = $this->wtid($request);
        $empCode = trim((string) $request->input('emp_code', ''));
        $emp = $this->employeeDirectory($this->tid($request))[$empCode] ?? null;
        if (! $emp) {
            return response()->json(['ok' => false, 'error' => 'Pick an active employee.'], 422);
        }
        $on = (string) $request->input('issued_on', '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) {
            $on = now()->toDateString();
        }
        $purpose = mb_substr(trim((string) $request->input('purpose', '')), 0, 255) ?: null;
        $lines = [];
        foreach ((array) $request->input('items', []) as $ln) {
            $iid = (int) ($ln['id'] ?? 0);
            if ($iid && ! isset($lines[$iid])) {
                $c = (string) ($ln['condition'] ?? 'good');
                $lines[$iid] = [
                    'condition' => in_array($c, self::CONDITIONS, true) ? $c : 'good',
                    'purpose' => mb_substr(trim((string) ($ln['purpose'] ?? '')), 0, 255) ?: $purpose,
                ];
            }
        }
        if (! $lines) {
            return response()->json(['ok' => false, 'error' => 'Add at least one item.'], 422);
        }
        if (count($lines) > 50) {
            return response()->json(['ok' => false, 'error' => 'Issue at most 50 items at a time.'], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $tid, $lines, $emp, $on, $purpose) {
                $locked = $this->items($request)->whereIn('id', array_keys($lines))->lockForUpdate()->get()->keyBy('id');
                $bad = [];
                foreach ($lines as $iid => $_) {
                    $it = $locked[$iid] ?? null;
                    if (! $it || $it->status !== 'available') {
                        $bad[] = $it ? $it->asset_code.' ('.$it->status.')' : ('#'.$iid.' (not found)');
                    }
                }
                if ($bad) {
                    throw new \RuntimeException('No longer available: '.implode(', ', $bad).'. Refresh and pick again.');
                }
                $seq = (int) DB::table('asset_issues')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->max('id') + 1;
                $ref = 'ISS-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
                while (DB::table('asset_issues')->where('issue_ref', $ref)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->exists()) {
                    $seq++;
                    $ref = 'ISS-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
                }
                $codes = [];
                foreach ($lines as $iid => $ln) {
                    DB::table('asset_issues')->insert([
                        'tenant_id' => $tid,
                        'issue_ref' => $ref,
                        'asset_item_id' => $iid,
                        'employee_id' => $emp['id'],
                        'emp_code' => $emp['code'],
                        'emp_name' => $emp['name'],
                        'issued_on' => $on,
                        'issue_condition' => $ln['condition'],
                        'purpose' => $ln['purpose'],
                        'issued_by' => $this->who($request),
                        'status' => 'issued',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('asset_items')->where('id', $iid)->update(['status' => 'issued', 'condition' => $ln['condition'], 'updated_at' => now()]);
                    $codes[] = $locked[$iid]->asset_code;
                }

                return ['ref' => $ref, 'codes' => $codes];
            });
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 409);
        }

        $n = count($result['codes']);

        return response()->json(['ok' => true, 'ref' => $result['ref'],
            'message' => ($n === 1 ? $result['codes'][0] : $n.' items').' issued to '.$emp['name'].' · '.$result['ref']]);
    }

    /**
     * POST /app/asset-mgmt/return  {ids: [issueId, …], returned_on, condition, remarks}
     * good/new/fair → back to Available; damaged → Under repair; lost → Lost.
     */
    public function returnItems(Request $request)
    {
        $this->guard($request);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        if (! $ids) {
            return response()->json(['ok' => false, 'error' => 'Pick at least one item to return.'], 422);
        }
        $on = (string) $request->input('returned_on', '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) {
            $on = now()->toDateString();
        }
        $cond = (string) $request->input('condition', 'good');
        $cond = in_array($cond, self::CONDITIONS, true) ? $cond : 'good';
        $remarks = mb_substr(trim((string) $request->input('remarks', '')), 0, 255) ?: null;
        $stockStatus = $cond === 'lost' ? 'lost' : ($cond === 'damaged' ? 'repair' : 'available');

        try {
            $done = DB::transaction(function () use ($request, $ids, $on, $cond, $remarks, $stockStatus) {
            $rows = $this->issues($request)->whereIn('id', $ids)->where('status', 'issued')->lockForUpdate()->get();
            foreach ($rows as $r) {
                if ($on < $r->issued_on) {
                    throw new \RuntimeException('Return date cannot be before the issue date ('.$r->issued_on.').');
                }
            }
            foreach ($rows as $r) {
                DB::table('asset_issues')->where('id', $r->id)->update([
                    'status' => 'returned', 'returned_on' => $on, 'return_condition' => $cond,
                    'return_remarks' => $remarks, 'returned_by' => $this->who($request), 'updated_at' => now(),
                ]);
                DB::table('asset_items')->where('id', $r->asset_item_id)->update(['status' => $stockStatus, 'condition' => $cond, 'updated_at' => now()]);
            }

            return $rows->count();
            });
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => $done > 0, 'error' => $done ? null : 'Nothing to return (already returned?).',
            'message' => $done.' item'.($done === 1 ? '' : 's').' returned'.($stockStatus === 'available' ? ' to stock' : ($stockStatus === 'repair' ? ' — sent to Under repair' : ' — marked Lost'))]);
    }

    // ------------------------------------------------------------------ my assets (staff)

    /**
     * GET /app/asset-mgmt/mine — the logged-in person's OWN assets (with them now
     * + returned history). Any logged-in role; the employee is resolved from the
     * login on the server, never from the request, so nobody can see another
     * person's items. Same resolution as MasterController::selfEmployee().
     */
    public function mine(Request $request)
    {
        self::ensure();
        self::importLegacy();
        $user = $request->user();
        $emp = null;
        if (! empty($user->employee_id)) {
            $emp = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
        }
        $base = fn () => DB::table('employees')->whereNull('deleted_at')
            ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id));
        if (! $emp && $user->email && Schema::hasColumn('employees', 'email')) {
            $emp = $base()->where('email', $user->email)->first();
        }
        if (! $emp && trim((string) $user->name) !== '') {
            // Name is only trusted when it is unambiguous — never show a namesake's items.
            $same = $base()->where('name', $user->name)->limit(2)->get();
            $emp = $same->count() === 1 ? $same->first() : null;
        }
        if (! $emp || empty($emp->emp_code)) {
            return response()->json(['ok' => true, 'employee' => null, 'rows' => []]);
        }
        $iss = DB::table('asset_issues')->where('emp_code', $emp->emp_code)
            ->when($emp->tenant_id ?? null, fn ($q) => $q->where('tenant_id', $emp->tenant_id))
            ->orderByRaw("status = 'issued' desc")->orderByDesc('issued_on')->orderByDesc('id')->get();
        $items = DB::table('asset_items')->whereIn('id', $iss->pluck('asset_item_id')->unique()->all())->get()->keyBy('id');
        $rows = $iss->map(function ($r) use ($items) {
            $i = $items[$r->asset_item_id] ?? null;

            return [
                'ref' => $r->issue_ref, 'code' => $i->asset_code ?? '—', 'category' => $i->category ?? '',
                'make_model' => $i->make_model ?? '', 'serial' => $i->serial_no ?? '',
                'issued_on' => $r->issued_on, 'condition' => $r->issue_condition, 'purpose' => $r->purpose,
                'status' => $r->status, 'returned_on' => $r->returned_on, 'return_condition' => $r->return_condition,
            ];
        })->values();

        return response()->json(['ok' => true, 'employee' => ['code' => $emp->emp_code, 'name' => $emp->name ?? ''], 'rows' => $rows]);
    }

    // ------------------------------------------------------------------ export

    /** GET /app/asset-mgmt/export?scope=open|returned|all|stock&format=csv|pdf&emp= */
    public function export(Request $request)
    {
        $this->guard($request);
        $scope = (string) $request->query('scope', 'open');
        $fmt = $request->query('format') === 'pdf' ? 'pdf' : 'csv';
        $titles = ['open' => 'Issued — to be returned', 'returned' => 'Returned items', 'all' => 'All asset movements', 'stock' => 'Asset Stock'];
        $title = $titles[$scope] ?? $titles['open'];

        if ($scope === 'stock') {
            $data = json_decode($this->stock($request)->getContent(), true);
            $head = ['Asset ID', 'Category', 'Make / Model', 'Serial Number', 'Cost', 'Purchased', 'Vendor', 'Invoice', 'Status', 'Condition', 'With'];
            $rows = array_map(fn ($r) => [$r['code'], $r['category'], $r['make_model'], $r['serial'], number_format($r['cost'], 2, '.', ''),
                $r['purchase_date'], $r['vendor'], $r['invoice_no'], ucfirst($r['status'] === 'repair' ? 'under repair' : $r['status']), ucfirst((string) $r['condition']),
                $r['holder'] ? ($r['holder']['code'].' '.$r['holder']['name']) : ''], $data['rows'] ?? []);
            $money = [4];
        } else {
            $request->query->set('scope', $scope);
            $mv = $this->movementRows($request);
            $head = ['Issue Ref', 'Asset ID', 'Category', 'Make / Model', 'Serial Number', 'Cost', 'Issued On', 'Employee ID', 'Employee Name',
                'Designation', 'Department', 'Reporting Manager', 'Company', 'Branch', 'Condition', 'Purpose'];
            if ($scope !== 'open') {
                $head = array_merge($head, ['Status', 'Returned On', 'Return Condition', 'Remarks']);
            }
            $rows = array_map(function ($r) use ($scope) {
                $a = [$r['ref'], $r['code'], $r['category'], $r['make_model'], $r['serial'], number_format($r['cost'], 2, '.', ''), $r['issued_on'],
                    $r['emp_code'], $r['emp_name'], $r['designation'], $r['department'], $r['manager'], $r['company'], $r['branch'],
                    ucfirst((string) $r['condition']), $r['purpose']];
                if ($scope !== 'open') {
                    $a = array_merge($a, [ucfirst($r['status']), $r['returned_on'], ucfirst((string) $r['return_condition']), $r['return_remarks']]);
                }

                return $a;
            }, $mv);
            $money = [5];
        }

        $slug = 'assets-'.$scope.'-'.now()->format('Y-m-d');
        if ($fmt === 'csv') {
            $fh = fopen('php://temp', 'w+');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $head);
            foreach ($rows as $r) {
                fputcsv($fh, \App\Support\DateFmt::row(array_map(fn ($v) => $v === null ? '' : $v, $r)));   // 7 Oct 2026 — DD-MM-YYYY
            }
            rewind($fh);
            $csv = stream_get_contents($fh);
            fclose($fh);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$slug.'.csv"',
            ]);
        }

        $tid = $this->tid($request);
        $company = Schema::hasTable('companies') ? DB::table('companies')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first() : null;
        $brand = [];
        try {
            $brand = ConfigController::brandFor($tid, $company->id ?? null);
        } catch (\Throwable $e) {
        }
        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) $r[$money[0]];
        }
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('asset-register-pdf', [
            'title' => $title, 'head' => $head, 'rows' => $rows, 'money' => $money, 'total' => $total,
            'company' => $company, 'brand' => $brand, 'generated' => now()->format('d-m-Y h:i A'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($slug.'.pdf');
    }
}
