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
            // Zips already sitting in storage/app/releases, so a package too big
            // for an HTTP upload can be dropped there over SFTP and attached by name.
            'available' => collect(glob(storage_path('app/releases/*.zip')) ?: [])
                ->map(fn ($f) => basename($f))->values()->all(),
        ]);
    }

    /**
     * Upload + register a release zip, OR attach one already sitting in
     * storage/app/releases.
     *
     * The second path exists because of a trap that costs an afternoon every
     * time: a web server's body-size limit (nginx client_max_body_size, IIS
     * maxAllowedContentLength — often 1-30 MB) rejects a large upload with its
     * own HTML error BEFORE PHP runs, so this controller's "file too large"
     * message never executes and the screen just says "save failed". For a
     * 200 MB package, dropping the file into storage/app/releases over SFTP and
     * attaching it by name is the reliable path.
     *
     * The checksum is ALWAYS computed here, from the bytes on disk. It is never
     * accepted from the form: the hash is what the client verifies the download
     * against, so it has to come from the file that will actually be served.
     */
    public function upload(Request $request)
    {
        $this->guard($request);
        LicenseService::ensureTables();

        $v = $request->validate([
            'version' => ['required', 'string', 'max:30', 'regex:/^[0-9][0-9.]*$/'],
            'notes' => ['required', 'string', 'max:8000'],
            'package' => ['nullable', 'file', 'mimes:zip', 'max:512000'],       // up to 500 MB
            'existing' => ['nullable', 'string', 'max:255'],
        ]);
        if (DB::table('releases')->where('version', $v['version'])->exists()) {
            return back()->with('success', 'Version '.$v['version'].' already exists — bump the version number.');
        }

        // --- get the file into storage/app/releases, whichever way it arrived.
        if ($request->hasFile('package')) {
            $path = $request->file('package')->storeAs('releases', 'SmartPRS-Update-'.$v['version'].'.zip');
        } elseif (! empty($v['existing'])) {
            // basename() only — never let a form field walk out of the folder.
            $name = basename(str_replace('\\', '/', $v['existing']));
            $src = storage_path('app/releases/'.$name);
            if (! is_file($src)) {
                return back()->with('success', 'ATTACH FAILED: storage/app/releases/'.$name.' was not found on this server. '
                    .'Upload the zip there (SFTP) first, then attach it by name.');
            }
            $path = 'releases/'.$name;
        } else {
            return back()->with('success', 'Choose a package to upload, or type the name of a zip already in storage/app/releases.');
        }

        $full = storage_path('app/'.$path);

        // --- refuse a package the client updater could not install (blueprint trap 1).
        $shape = $this->inspectPackage($full);
        if (! $shape['ok']) {
            if ($request->hasFile('package')) {
                @unlink($full);                       // do not keep a package we will not serve
            }

            return back()->with('success', 'UPLOAD REJECTED: '.$shape['why']);
        }

        DB::table('releases')->insert([
            'version' => $v['version'],
            'notes' => $v['notes'],
            'file_path' => $path,
            'checksum' => hash_file('sha256', $full),
            'size' => filesize($full),
            'published_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Release '.$v['version'].' registered ('
            .number_format(filesize($full) / 1048576, 1).' MB). It will replace: '.implode(', ', array_slice($shape['top'], 0, 8))
            .($shape['wrapper'] ? ' — note: the application sits inside '.$shape['wrapper'].'/, which the client updater unwraps automatically.' : '.')
            .' Next: apply to the platform, then publish to clients.');
    }

    /**
     * Look inside the zip and decide whether an updater could install it.
     *
     * An updater copies the archive's TOP-LEVEL entries over the application
     * root. A package that wraps everything in one folder therefore copies that
     * FOLDER into the app root, replaces nothing, and still reports success —
     * the version number moves and every later check says "up to date", so the
     * bug becomes invisible. The client updater unwraps single-folder archives
     * for exactly this reason; this check is the second guard, here, where the
     * package can still be rejected before any client ever sees it.
     */
    private function inspectPackage(string $zipPath): array
    {
        if (! class_exists('\ZipArchive')) {
            return ['ok' => true, 'top' => ['(not inspected — ext-zip is missing on this server)'], 'wrapper' => null, 'why' => ''];
        }
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'top' => [], 'wrapper' => null, 'why' => 'the file is not a readable zip archive.'];
        }

        $top = [];
        $prefixCounts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            // `tar -a -c -f x.zip -C stage .` prefixes every entry with "./";
            // strip it or the whole archive looks like it is wrapped in a folder
            // called ".".
            $name = preg_replace('#^\./#', '', ltrim(str_replace('\\', '/', (string) $zip->getNameIndex($i)), '/'));
            if ($name === '' || $name === '.') {
                continue;
            }
            $first = explode('/', $name)[0];
            $top[$first] = true;
            $prefixCounts[$first] = ($prefixCounts[$first] ?? 0) + 1;
        }
        $zip->close();

        $entries = array_keys($top);
        $wrapper = null;

        // One top-level folder = a wrapped package. Report the wrapper and look
        // one level deeper for the real application root.
        if (count($entries) === 1) {
            $wrapper = $entries[0];
            $zip = new \ZipArchive;
            if ($zip->open($zipPath) === true) {
                $inner = [];
                foreach (range(0, $zip->numFiles - 1) as $i) {
                    $name = preg_replace('#^\./#', '', ltrim(str_replace('\\', '/', (string) $zip->getNameIndex($i)), '/'));
                    $parts = explode('/', $name);
                    if (count($parts) > 1 && $parts[1] !== '') {
                        $inner[$parts[1]] = true;
                    }
                }
                $zip->close();
                $entries = array_keys($inner);
            }
        }

        if (! in_array('app', $entries, true) || ! in_array('config', $entries, true)) {
            return ['ok' => false, 'top' => $entries, 'wrapper' => $wrapper,
                'why' => 'this zip has no app/ and config/ folders at its root'
                    .($wrapper ? ' (even inside '.$wrapper.'/)' : '')
                    .'. It looks like an installer/Setup zip, not an update package — a client updater would '
                    .'report success and change nothing. Found: '.implode(', ', array_slice($entries, 0, 10)).'.'];
        }

        return ['ok' => true, 'top' => $entries, 'wrapper' => $wrapper, 'why' => ''];
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
