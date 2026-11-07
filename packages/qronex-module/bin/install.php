<?php
/**
 * Hooks the QR attendance module into an app that already has its new files (packages/qronex-module, app/Qronex, config/qronex.php …).
 * Only ADDS lines; each spot is found by its content, so edits made on the server are kept.
 *
 *   php packages/qronex-module/bin/install.php prs2|ept|central [app folder]            → dry run: shows what it would add
 *   php packages/qronex-module/bin/install.php prs2|ept|central [app folder] --apply    → adds them; copies each file to <file>.qx-bak first
 *
 * Safe to run twice (skips what is already there). Stops without writing anything if a spot can't be found.
 */
[$host, $dir] = [$argv[1] ?? '', rtrim($argv[2] ?? getcwd(), '/')];
$apply = in_array('--apply', $argv, true);
if (! in_array($host, ['prs2', 'ept', 'central'], true) || ! is_dir($dir)) {
    fwrite(STDERR, "Usage: php install.php prs2|ept|central [app folder] [--apply]\n");
    exit(2);
}

$autoload = ['composer.json', '"Ametecs\\\\Qronex\\\\"', '~("App\\\\\\\\"\s*:\s*"app/",)~', "$1\n            \"Ametecs\\\\\\\\Qronex\\\\\\\\\": \"packages/qronex-module/src/\","];
$provider = ['bootstrap/providers.php', 'QronexServiceProvider', '~(App\\\\Providers\\\\AppServiceProvider::class,)~', "$1\n    Ametecs\\\\Qronex\\\\QronexServiceProvider::class, // QR attendance module (packages/qronex-module)"];

// [file, "already there" marker, regex anchor, replacement]
$edits = [
    'prs2' => [$autoload, $provider,
        ['app/Http/Controllers/AppController.php', "'smartprs-qronex.js'", '~(\'[a-z0-9-]+\.js\')(\s*\]\s*as\s*\$extraJs\))~', "$1, 'smartprs-qronex.js'$2"],
    ],
    'ept' => [$autoload, $provider,
        ['routes/api.php', 'qronex/sso-link', "~(Route::middleware\(\['auth:sanctum', 'company\.active', 'licensed'\]\)->group\(function \(\) \{)~",
            "$1\n    // QR attendance module (packages/qronex-module): one-time sign-in link from the console into /qronex. The module checks the role itself.\n"
            ."    Route::get('qronex/sso-link', fn (\\Illuminate\\Http\\Request \$r) => \\Ametecs\\Qronex\\Qronex::can(\$r->user(), 'view')\n"
            ."        ? ['url' => \\Ametecs\\Qronex\\Http\\Controllers\\Admin\\SsoController::link(\$r->user()->id)]\n"
            ."        : response()->json(['message' => 'You do not have access to QR Attendance.'], 403));"],
        ['resources/views/admin.blade.php', 'id="nav-qronex"', '~(\n[ \t]*<div class="nav" data-view="attendance">.*?</div>)~',
            "$1\n        <div class=\"nav\" id=\"nav-qronex\" style=\"display:none\" onclick=\"openQronex()\" title=\"QR attendance from approved phones\"><span class=\"ic\"><svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.75\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"3\" width=\"7\" height=\"7\" rx=\"1\"/><rect x=\"14\" y=\"3\" width=\"7\" height=\"7\" rx=\"1\"/><rect x=\"3\" y=\"14\" width=\"7\" height=\"7\" rx=\"1\"/><path d=\"M14 14h3v3h-3zM20 14v.01M14 20h.01M17 17h4v4\"/></svg></span> QR Attendance</div>"],
        ['resources/views/admin.blade.php', 'async function openQronex', '~(\nfunction applyPlanNav\(\) \{)~',
            "\nasync function openQronex() { // 07-Nov-2026: QR Attendance module (QRONEX)\n"
            ."  try { const r = await api('/qronex/sso-link'); window.location.href = r.url; }\n"
            ."  catch (e) { alert((e && e.message) || 'Could not open QR Attendance.'); }\n}$1\n"
            ."  // 07-Nov-2026: QR Attendance nav — plan feature qr_attendance + company-level roles; the module re-checks both.\n"
            ."  { const qx = document.getElementById('nav-qronex');\n"
            ."    if (qx) qx.style.display = hasPlanFeature('qr_attendance') && ME && ['SUPER_ADMIN','COMPANY_ADMIN','HR_ADMIN','AUDITOR','COMPLIANCE_OFFICER'].includes(ME.base_role || ME.role) ? '' : 'none'; }"],
        // The console sets every .nav's onclick to show(view) at load, replacing ours — re-attach it whenever the nav is gated.
        ['resources/views/admin.blade.php', 'qx.onclick = openQronex', "~(\\{ const qx = document\\.getElementById\\('nav-qronex'\\);)~",
            "$1\n    if (qx) qx.onclick = openQronex; // the console re-binds every .nav click to show(); keep ours"],
    ],
    'central' => [
        ['app/Http/Controllers/Admin/LicenceApiController.php', "'qr_attendance' => ['nullable', 'boolean']", "~(\n([ \t]*)'recording_max_users' => \['nullable', 'integer', 'min:1', 'max:100000'\],)~",
            "$1\n$2// 07-Nov-2026: QR Attendance add-on (QRONEX module in SmartEPT) — bundle['features']['qr_attendance'].\n$2'qr_attendance' => ['nullable', 'boolean'],"],
        ['app/Http/Controllers/Admin/LicenceApiController.php', "has('qr_attendance')) {\n", "~(\n([ \t]*)// 15-Oct-2026: Screen Recording users — same handling as LiveView users above\.)~",
            "\n$2// 07-Nov-2026: QR Attendance add-on, any plan. Kept across plan changes (it is not a plan gate key).\n"
            ."$2if (\$request->has('qr_attendance')) {\n$2    \$features = \$update['features'] ?? (\$licence->features ?? []);\n"
            ."$2    \$features['qr_attendance'] = (bool) \$data['qr_attendance'];\n$2    \$update['features'] = \$features;\n$2}$1"],
        ['app/Http/Controllers/Admin/LicenceApiController.php', "has('qr_attendance')\n", "~(\n([ \t]*)if \(\(array_key_exists\('plan_id', \\\$update\) && \\\$update\['plan_id'\] !== \\\$previousPlan\?->id\) \|\| \\\$lvChanged\) \{)~",
            "\n$2\$lvChanged = \$lvChanged || (\$request->has('qr_attendance')\n$2    && (bool) \$data['qr_attendance'] !== (bool) (\$previousFeatures['qr_attendance'] ?? false));$1"],
        ['resources/views/admin/console.blade.php', "sel('ed_qx'", '~(\n([ \t]*)<input id="ed_recmc"[^\n]*)~',
            "$1\n$2<label>QR Attendance <span class=\"mini\">(add-on, any plan — phone QR punches into attendance)</span></label>\n$2\${sel('ed_qx', (l.features&&l.features.qr_attendance) ? 'yes' : 'no', [['no','No'],['yes','Yes']])}"],
        ['resources/views/admin/console.blade.php', "qr_attendance:(document", '~(\n([ \t]*)recording_max_users:recOn \?[^\n]*)~',
            "$1\n$2qr_attendance:(document.getElementById('ed_qx')||{}).value === 'yes',"],
    ],
][$host];

$files = [];
foreach ($edits as [$file, $marker, $anchor, $replace]) {
    $path = "$dir/$file";
    $files[$path] ??= is_file($path) ? file_get_contents($path) : null;
    if ($files[$path] === null) {
        fwrite(STDERR, "STOP: $file not found in $dir. Nothing was written.\n");
        exit(1);
    }
    if (str_contains($files[$path], $marker)) {
        echo "already there   $file\n";
        continue;
    }
    $new = preg_replace($anchor, $replace, $files[$path], 1, $n);
    if ($n !== 1) {
        fwrite(STDERR, "STOP: could not find the spot in $file (pattern $anchor). Nothing was written — send this output to Claude.\n");
        exit(1);
    }
    echo ($apply ? 'adding          ' : 'would add       ')."$file\n";
    $files[$path] = $new;
}
if (! $apply) {
    echo "\nDry run only. Run again with --apply to write these changes.\n";
    exit(0);
}
foreach ($files as $path => $content) {
    if ($content !== file_get_contents($path)) {
        copy($path, "$path.qx-bak") || exit("STOP: could not back up $path\n");
        file_put_contents($path, $content);
        echo "saved           $path   (previous version: $path.qx-bak)\n";
    }
}
echo "\nDone.\n";
