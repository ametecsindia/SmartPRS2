<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * rev161 — Employee Document Tracker with real file uploads.
 * Upload / list / download / delete employee documents, grouped by category
 * (Educational, Personal, Experience: leaving letter / payslips / certificate,
 * Other). Files are stored on the server ('public' disk) and served through a
 * controller route (no storage:link needed). Admin/HR only for manage; employees
 * may download their own via the app. A top search filters by employee name/ID.
 */
class DocumentController extends Controller
{
    public const CATEGORIES = [
        'Educational Certificate',
        'Personal Document / Certificate',
        'Experience - Relieving / Leaving Letter',
        'Experience - Payslip',
        'Experience - Experience Certificate',
        'Other',
    ];

    // rev172 — upload rules are explicit and returned to the UI. Storage is
    // limited PER EMPLOYEE (scales naturally with client size): each employee's
    // documents may total up to the tenant's per-employee allowance
    // (tenants.doc_storage_emp_mb, default 100 MB — Super Admin sets it per
    // tenant in the SaaS tenant edit). On-prem installs (no tenant) are unlimited.
    public const ALLOWED_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

    public const MAX_FILE_MB = 10;

    public const DEFAULT_EMP_QUOTA_MB = 100; // per employee

    /** Per-employee document-storage allowance in MB (null = unlimited, e.g. on-prem). */
    private static function empQuotaMb(?int $tid): ?int
    {
        if (! $tid) {
            return null;
        }
        $v = null;
        if (Schema::hasColumn('tenants', 'doc_storage_emp_mb')) {
            $v = DB::table('tenants')->where('id', $tid)->value('doc_storage_emp_mb');
        }

        return $v !== null ? (int) $v : self::DEFAULT_EMP_QUOTA_MB;
    }

    /** Bytes used by a tenant's documents — all, or one employee's (lazily backfills file_size for old rows). */
    private static function usedBytes(?int $tid, ?int $empId = null): int
    {
        $q = fn () => DB::table('documents')
            ->when($tid, fn ($x) => $x->where('tenant_id', $tid))
            ->when($empId, fn ($x) => $x->where('employee_id', $empId));
        // Backfill sizes for rows uploaded before file_size existed.
        foreach ($q()->whereNull('file_size')->whereNotNull('file_path')->limit(200)->get(['id', 'file_path']) as $d) {
            try {
                $full = Storage::disk('public')->path($d->file_path);
                DB::table('documents')->where('id', $d->id)->update(['file_size' => is_file($full) ? filesize($full) : 0]);
            } catch (\Throwable $e) {
                DB::table('documents')->where('id', $d->id)->update(['file_size' => 0]);
            }
        }

        return (int) $q()->sum('file_size');
    }

    private static function ensure(): void
    {
        if (! Schema::hasTable('documents')) {
            Schema::create('documents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('employee_id')->index();
                $t->string('kind')->nullable();
                $t->string('status')->default('pending');
                $t->date('expiry')->nullable();
                $t->string('file_path')->nullable();
                $t->timestamps();
            });
        }
        foreach (['category', 'doc_name', 'file_name'] as $c) {
            if (! Schema::hasColumn('documents', $c)) {
                Schema::table('documents', fn (Blueprint $t) => $t->string($c)->nullable());
            }
        }
        if (! Schema::hasColumn('documents', 'file_size')) {
            Schema::table('documents', fn (Blueprint $t) => $t->unsignedBigInteger('file_size')->nullable());
        }
        if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'doc_storage_emp_mb')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->unsignedInteger('doc_storage_emp_mb')->nullable());
        }
    }

    private static function tid(Request $r): ?int
    {
        return $r->user()->tenant_id ? (int) $r->user()->tenant_id : null;
    }

    /** GET /app/documents-mgr?q= — employees (for the picker) + documents (filtered by q). */
    public function index(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        self::ensure();
        $tid = self::tid($request);
        $q = trim((string) $request->query('q', ''));

        $emps = DB::table('employees')
            ->when($tid, fn ($x) => $x->where('tenant_id', $tid))
            ->when(Schema::hasColumn('employees', 'deleted_at'), fn ($x) => $x->whereNull('deleted_at'))
            ->orderBy('name')->get(['id', 'emp_code', 'name']);

        $docsQ = DB::table('documents as d')
            ->leftJoin('employees as e', 'e.id', '=', 'd.employee_id')
            ->when($tid, fn ($x) => $x->where('d.tenant_id', $tid));
        if ($q !== '') {
            $docsQ->where(function ($w) use ($q) {
                $w->where('e.name', 'like', '%'.$q.'%')->orWhere('e.emp_code', 'like', '%'.$q.'%');
            });
        }
        $docs = $docsQ->orderByDesc('d.id')->get([
            'd.id', 'd.employee_id', 'd.category', 'd.doc_name', 'd.kind', 'd.status', 'd.expiry', 'd.file_name', 'd.file_path', 'd.created_at',
            'e.name as emp_name', 'e.emp_code',
        ])->map(fn ($d) => [
            'id' => $d->id,
            'employee_id' => $d->employee_id,
            'emp_name' => $d->emp_name ?: '—',
            'emp_code' => $d->emp_code ?: '',
            'category' => $d->category ?: ($d->kind ?: 'Other'),
            'doc_name' => $d->doc_name ?: '',
            'status' => $d->status ?: 'pending',
            'expiry' => $d->expiry,
            'file_name' => $d->file_name ?: '',
            'has_file' => ! empty($d->file_path),
            'uploaded' => $d->created_at ? substr((string) $d->created_at, 0, 10) : '',
        ]);

        $empLimitMb = self::empQuotaMb($tid);
        $usedMb = round(self::usedBytes($tid) / 1048576, 1);

        return response()->json([
            'ok' => true,
            'q' => $q,
            'categories' => self::CATEGORIES,
            'employees' => $emps,
            'documents' => $docs,
            'rules' => [
                'formats' => strtoupper(implode(', ', self::ALLOWED_EXTS)),
                'max_file_mb' => self::MAX_FILE_MB,
                'used_mb' => $usedMb,            // tenant total (info only)
                'emp_limit_mb' => $empLimitMb,   // per-employee allowance; null = unlimited
            ],
        ]);
    }

    /** POST /app/documents-mgr/upload */
    public function upload(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        self::ensure();
        $request->validate(['employee_id' => ['required']]);
        $tid = self::tid($request);
        $emp = DB::table('employees')->where('id', (int) $request->input('employee_id'))
            ->when($tid, fn ($x) => $x->where('tenant_id', $tid))->first();
        if (! $emp) {
            return response()->json(['ok' => false, 'error' => 'Employee not found'], 422);
        }

        // rev163 — accept MANY documents for one employee in a single request:
        // files[] with parallel categories[]/docNames[]/expiries[]. Falls back to
        // the single-file form (file + category + doc_name + expiry).
        $files = $request->file('files');
        if (! $files) {
            $single = $request->file('file');
            $files = $single ? [$single] : [];
        }
        $files = array_values(is_array($files) ? $files : [$files]);
        if (! $files) {
            return response()->json(['ok' => false, 'error' => 'Choose at least one file'], 422);
        }
        $cats = (array) $request->input('categories', []);
        $names = (array) $request->input('docNames', []);
        $exps = (array) $request->input('expiries', []);
        $defCat = trim((string) $request->input('category', 'Other')) ?: 'Other';
        $defName = trim((string) $request->input('doc_name', ''));
        $defExp = $request->input('expiry');

        // Per-employee storage quota (Super Admin sets the rate per tenant; default 100 MB/employee).
        $limitMb = self::empQuotaMb($tid);
        $limitBytes = $limitMb !== null ? $limitMb * 1048576 : null;
        $used = self::usedBytes($tid, (int) $emp->id);

        $count = 0;
        $skipped = 0;
        $skipReasons = [];
        $quotaHit = false;
        foreach ($files as $i => $file) {
            if (! $file || ! $file->isValid()) {
                $skipped++;
                $skipReasons[] = 'a file failed to upload';

                continue;
            }
            $ext = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($ext, self::ALLOWED_EXTS, true)) {
                $skipped++;
                $skipReasons[] = $file->getClientOriginalName().' — format .'.$ext.' not allowed (allowed: '.strtoupper(implode(', ', self::ALLOWED_EXTS)).')';

                continue;
            }
            if ($file->getSize() > self::MAX_FILE_MB * 1048576) {
                $skipped++;
                $skipReasons[] = $file->getClientOriginalName().' — larger than '.self::MAX_FILE_MB.' MB';

                continue;
            }
            if ($limitBytes !== null && $used + $file->getSize() > $limitBytes) {
                $skipped++;
                $quotaHit = true;

                continue;
            }
            $cat = trim((string) ($cats[$i] ?? $defCat)) ?: 'Other';
            $path = $file->store('employee-docs', 'public');
            DB::table('documents')->insert([
                'tenant_id' => $tid,
                'employee_id' => $emp->id,
                'category' => $cat,
                'doc_name' => (trim((string) ($names[$i] ?? $defName)) ?: null),
                'kind' => $cat,
                'status' => 'pending',
                'expiry' => (($exps[$i] ?? $defExp) ?: null),
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => (int) $file->getSize(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $used += (int) $file->getSize();
            $count++;
        }
        if ($quotaHit) {
            $skipReasons[] = 'storage limit for '.$emp->name.' reached ('.round($used / 1048576).' of '.$limitMb.' MB per employee used) — delete old documents of this employee or ask SmartPRS support to increase the limit';
        }
        if (! $count) {
            return response()->json(['ok' => false, 'error' => 'No files uploaded. '.implode('; ', array_unique($skipReasons))], 422);
        }

        return response()->json(['ok' => true, 'count' => $count, 'skipped' => $skipped, 'skip_reasons' => array_values(array_unique($skipReasons))]);
    }

    /** GET /app/documents-mgr/{id}/download */
    public function download(Request $request, int $id)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager', 'employee'])) {
            return $deny;
        }
        self::ensure();
        $tid = self::tid($request);
        $d = DB::table('documents')->where('id', $id)
            ->when($tid, fn ($x) => $x->where('tenant_id', $tid))->first();
        if (! $d || ! $d->file_path) {
            abort(404);
        }
        $full = Storage::disk('public')->path($d->file_path);
        if (! is_file($full)) {
            abort(404);
        }

        return response()->download($full, $d->file_name ?: basename($full));
    }

    /** POST /app/documents-mgr/{id}/delete */
    public function destroy(Request $request, int $id)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        self::ensure();
        $tid = self::tid($request);
        $d = DB::table('documents')->where('id', $id)
            ->when($tid, fn ($x) => $x->where('tenant_id', $tid))->first();
        if (! $d) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }
        try {
            if ($d->file_path) {
                Storage::disk('public')->delete($d->file_path);
            }
        } catch (\Throwable $e) {
        }
        DB::table('documents')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /app/documents-mgr/employee/{empId}/delete-all — remove all of one employee's documents. */
    public function destroyForEmployee(Request $request, int $empId)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        self::ensure();
        $tid = self::tid($request);
        $docs = DB::table('documents')->where('employee_id', $empId)
            ->when($tid, fn ($x) => $x->where('tenant_id', $tid))->get(['id', 'file_path']);
        foreach ($docs as $d) {
            try {
                if ($d->file_path) {
                    Storage::disk('public')->delete($d->file_path);
                }
            } catch (\Throwable $e) {
            }
        }
        DB::table('documents')->where('employee_id', $empId)
            ->when($tid, fn ($x) => $x->where('tenant_id', $tid))->delete();

        return response()->json(['ok' => true, 'deleted' => $docs->count()]);
    }
}
