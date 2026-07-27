<?php

namespace App\Http\Controllers;

use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * rev 107 — RELEASES & PLATFORM UPDATES (SRS FR-3/FR-4/FR-10, SaaS panel).
 *
 * Upload a release zip (from BUILD-RELEASE.bat) → it is registered with its
 * checksum. From the same screen: APPLY TO THIS PLATFORM (maintenance mode →
 * backup → extract → migrate → up; all tenants + demo + teamdemo are updated
 * in that moment) and PUBLISH/GRANT to on-prem clients with active AMC —
 * each granted client admin gets the update email (FR-10.2).
 */
class ReleaseController extends Controller
{
    private const CODE_DIRS = ['app', 'config', 'database', 'resources', 'routes'];

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
    }

    public function index(Request $request)
    {
        $this->guard($request);
        LicenseService::ensureTables();

        return view('admin.releases', [
            'current' => config('smartprs.version'),
            'releases' => DB::table('releases')->orderByDesc('id')->limit(50)->get(),
            'grantCounts' => DB::table('release_grants')->selectRaw('release_id, count(*) n')->groupBy('release_id')->pluck('n', 'release_id'),
            'clientCount' => DB::table('onprem_clients')->count(),
            'log' => DB::table('client_updates')->orderByDesc('id')->limit(30)->get(),
        ]);
    }

    /** Upload + register a release zip. */
    public function upload(Request $request)
    {
        $this->guard($request);
        $v = $request->validate([
            'version' => ['required', 'string', 'max:30', 'regex:/^[0-9][0-9.]*$/'],
            'notes' => ['required', 'string', 'max:8000'],
            'package' => ['required', 'file', 'mimes:zip', 'max:204800'],   // up to 200 MB
        ]);
        if (DB::table('releases')->where('version', $v['version'])->exists()) {
            return back()->with('success', 'Version '.$v['version'].' already exists — bump the version number.');
        }
        $path = $request->file('package')->storeAs('releases', 'SmartPRS-Update-'.$v['version'].'.zip');
        DB::table('releases')->insert([
            'version' => $v['version'], 'notes' => $v['notes'],
            'file_path' => $path,
            'checksum' => hash_file('sha256', storage_path('app/'.$path)),
            'size' => filesize(storage_path('app/'.$path)),
            'published_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Release '.$v['version'].' uploaded and registered. Next: apply to the platform, then publish to clients.');
    }

    /**
     * APPLY TO THIS PLATFORM (FR-4.2/4.3): maintenance → backup → extract →
     * migrate → up. Failure = automatic code rollback + up.
     */
    public function applyPlatform(Request $request, int $id)
    {
        $this->guard($request);
        @set_time_limit(900);
        $rel = DB::table('releases')->where('id', $id)->first();
        abort_unless($rel && $rel->file_path, 404);
        $zipPath = storage_path('app/'.$rel->file_path);
        if (! is_file($zipPath) || ! hash_equals($rel->checksum, hash_file('sha256', $zipPath))) {
            return back()->with('success', 'APPLY ABORTED: the stored package failed its integrity check. Re-upload the release.');
        }

        $bk = storage_path('app/backups/platform-'.$rel->version.'-'.date('YmdHis'));
        try {
            Artisan::call('down', ['--render' => 'errors::503']);
        } catch (\Throwable $e) {
            try {
                Artisan::call('down');
            } catch (\Throwable $e2) {
            }
        }
        try {
            // Backup, then extract (never .env / storage / vendor).
            foreach (self::CODE_DIRS as $d) {
                $this->copyDir(base_path($d), $bk.'/'.$d);
            }
            $zip = new \ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('Bad zip');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $clean = ltrim(str_replace('\\', '/', $name), '/');
                if ($clean === '' || str_contains($clean, '..')) {
                    continue;
                }
                $top = explode('/', $clean)[0];
                if (in_array($top, ['.env', 'storage', 'vendor', 'node_modules'], true)) {
                    continue;
                }
                $dest = base_path($clean);
                if (str_ends_with($clean, '/')) {
                    @mkdir($dest, 0775, true);
                    continue;
                }
                @mkdir(dirname($dest), 0775, true);
                copy('zip://'.$zipPath.'#'.$name, $dest);
            }
            $zip->close();
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('optimize:clear');
            DB::table('releases')->where('id', $id)->update(['applied_platform_at' => now(), 'updated_at' => now()]);
            Artisan::call('up');

            return back()->with('success', 'Platform updated to '.$rel->version.' — every tenant, the demo and teamdemo are on it now. Backup kept at storage/backups.');
        } catch (\Throwable $e) {
            foreach (self::CODE_DIRS as $d) {
                if (is_dir($bk.'/'.$d)) {
                    $this->copyDir($bk.'/'.$d, base_path($d));
                }
            }
            try {
                Artisan::call('optimize:clear');
                Artisan::call('up');
            } catch (\Throwable $e2) {
            }

            return back()->with('success', 'APPLY FAILED and was ROLLED BACK automatically ('.$e->getMessage().'). The platform is running on the previous version.');
        }
    }

    /** Publish + grant to ALL clients with active AMC (or one client) + emails. */
    public function publish(Request $request, int $id)
    {
        $this->guard($request);
        $rel = DB::table('releases')->where('id', $id)->first();
        abort_unless($rel, 404);
        if (! $rel->published_at) {
            DB::table('releases')->where('id', $id)->update(['published_at' => now(), 'updated_at' => now()]);
        }
        $only = (int) $request->input('client_id', 0);
        $clients = DB::table('onprem_clients')
            ->when($only, fn ($q) => $q->where('id', $only))
            ->get();
        $granted = 0;
        $emailed = 0;
        foreach ($clients as $c) {
            $lic = DB::table('licences')->where('client_id', $c->id)->where('status', 'active')->orderByDesc('id')->first();
            if (! $lic || ! LicenseService::amcActive($lic)) {
                continue;                                    // FR-10.3: AMC gate
            }
            if (DB::table('release_grants')->where('release_id', $id)->where('client_id', $c->id)->exists()) {
                continue;
            }
            DB::table('release_grants')->insert([
                'release_id' => $id, 'client_id' => $c->id,
                'granted_by' => $request->user()->name,
                'emailed_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $granted++;
            try {
                if ($c->email) {
                    \App\Services\MailService::queue([
                        'tenant_id' => null,
                        'to' => $c->email,
                        'subject' => 'SmartPRS update '.$rel->version.' is ready for you',
                        'heading' => 'A new SmartPRS update is ready, '.($c->contact_name ?: $c->company),
                        'intro' => 'Your AMC entitles you to this update. Applying it takes about two minutes and backs itself up automatically.',
                        'lines' => array_merge(
                            ['What is new in '.$rel->version.':'],
                            array_slice(array_filter(array_map('trim', explode("\n", (string) $rel->notes))), 0, 8),
                            ['How to update: sign in as admin -> Administration -> Updates & Licence -> Check for updates -> Apply.']
                        ),
                        'kind' => 'update_grant',
                    ]);
                    DB::table('release_grants')->where('release_id', $id)->where('client_id', $c->id)->update(['emailed_at' => now()]);
                    $emailed++;
                }
            } catch (\Throwable $e) {
            }
        }

        return back()->with('success', 'Release '.$rel->version.' published. Granted to '.$granted.' AMC-active client(s), '.$emailed.' email(s) sent.');
    }

    private function copyDir(string $src, string $dst): void
    {
        if (! is_dir($src)) {
            return;
        }
        @mkdir($dst, 0775, true);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $target = $dst.'/'.$it->getSubPathname();
            if ($item->isDir()) {
                @mkdir($target, 0775, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}
