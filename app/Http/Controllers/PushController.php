<?php

namespace App\Http\Controllers;

use App\Services\ETimeOfficeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * eSSL / ZKTeco ADMS "push" receiver (iclock protocol).
 *
 * Devices that have SmartPRS set as their Cloud Server (ADMS) dial OUT to these
 * endpoints and upload punches themselves — ONE endpoint, ONE common format,
 * works behind NAT, near real-time, no per-device URL or credentials stored in
 * SmartPRS. The device identifies itself by its Serial Number (SN). Most
 * ZKTeco-based units (a large share of eSSL + Indian OEM brands) speak this.
 *
 * Endpoints — the PATHS are fixed inside the firmware (only host:port is set on
 * the device), so they MUST live at the web root:
 *   GET  /iclock/cdata?SN=..&options=all   handshake → return the option block
 *   POST /iclock/cdata?SN=..&table=ATTLOG  punch upload (tab-delimited) → import
 *   GET  /iclock/getrequest?SN=..          command poll → "OK" (we issue none)
 *   POST /iclock/devicecmd | /iclock/fdata command/file ack → "OK"
 *
 * Punches land in attendance_logs through ETimeOfficeService::import
 * (source = 'push'), matched to the device's biometric_configs row
 * (provider = 'push', serial_number = SN). An unknown SN auto-registers an
 * enabled push row, so it works plug-and-play and appears under Biometric
 * Device Setup for the admin to map to a branch. No auth/CSRF (a device cannot
 * send a token); scoped by SN + rate-limited at the route.
 */
class PushController extends Controller
{
    /** GET = handshake/options; POST = data upload (ATTLOG etc.). */
    public function cdata(Request $request)
    {
        $sn = self::sn($request);
        if ($request->isMethod('get')) {
            return self::text(self::optionBlock($sn));
        }
        self::touch($sn);
        $table = strtoupper((string) ($request->query('table') ?? $request->input('table') ?? ''));
        if ($table === 'ATTLOG') {
            $n = self::importAttlog($sn, $request->getContent());

            return self::text('OK: '.$n);
        }

        // OPERLOG / OPTIONS / BIODATA / other tables — accept and ignore.
        return self::text('OK');
    }

    /** Device polls for commands; we have none. */
    public function getrequest(Request $request)
    {
        self::touch(self::sn($request));

        return self::text('OK');
    }

    /** Command result ack, file uploads, etc. — accept and ignore. */
    public function ok(Request $request)
    {
        return self::text('OK');
    }

    // ---- internals -------------------------------------------------------

    private static function sn(Request $request): string
    {
        return trim((string) ($request->query('SN') ?? $request->query('sn') ?? $request->input('SN') ?? ''));
    }

    private static function text(string $s)
    {
        return response($s, 200)->header('Content-Type', 'text/plain');
    }

    /** The option/registry block a device expects on first contact. */
    private static function optionBlock(string $sn): string
    {
        $row = $sn !== '' ? self::rowForSn($sn, true) : null;
        $interval = (int) ($row->sync_interval_min ?? 0);
        // interval 0 = realtime push (best); >0 = batch every N minutes.
        $realtime = $interval > 0 ? 0 : 1;
        $lines = [
            'GET OPTION FROM: '.$sn,
            'Stamp=9999',
            'OpStamp=9999',
            'ErrorDelay=30',
            'Delay=30',
            'TransTimes=00:00;23:59',
            'TransInterval='.max(1, $interval ?: 1),
            'TransFlag=1111000000',
            'Realtime='.$realtime,
            'Encrypt=0',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    /** Parse a tab-delimited ATTLOG body and import the punches. Returns imported count. */
    private static function importAttlog(string $sn, string $body): int
    {
        $cfg = self::cfgForSn($sn);
        $punches = [];
        foreach (preg_split('~\r\n|\r|\n~', trim((string) $body)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $f = explode("\t", $line);
            $pin = trim((string) ($f[0] ?? ''));
            $dt = trim((string) ($f[1] ?? ''));
            if ($pin === '' || $dt === '') {
                continue;
            }
            $when = self::parseDate($dt);
            if (! $when) {
                continue;
            }
            $punches[] = [
                'emp_code' => $pin,
                'name' => null,
                'punch_at' => $when,
                'direction' => self::dir(trim((string) ($f[2] ?? '')), $cfg, $sn),
                'machine' => $sn,
            ];
        }
        if (! $punches) {
            return 0;
        }
        $cfg['source'] = 'push';
        $r = ETimeOfficeService::import($punches, $cfg);

        return $r['imported'] ?? count($punches);
    }

    /**
     * Direction. In/Out Machine ID wins if the admin mapped this SN to one; else
     * the ZKTeco status code (0 in, 1 out, 2 break-out, 3 break-in, 4 OT-in,
     * 5 OT-out). Unknown/blank → 'in' (rev158 pairing is direction-independent
     * for worked/break, so this only affects the stored label).
     */
    private static function dir(string $status, array $cfg, string $sn): string
    {
        $in = trim((string) ($cfg['in_machine_id'] ?? ''));
        $out = trim((string) ($cfg['out_machine_id'] ?? ''));
        if ($in !== '' && strcasecmp($sn, $in) === 0) {
            return 'in';
        }
        if ($out !== '' && strcasecmp($sn, $out) === 0) {
            return 'out';
        }

        return in_array($status, ['1', '2', '5'], true) ? 'out' : 'in';
    }

    private static function parseDate(string $v): ?Carbon
    {
        $v = trim($v);
        if ($v === '' || ! preg_match('~\d~', $v)) {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y/m/d H:i:s', 'Y/m/d H:i'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $v);
                if ($d !== false) {
                    return $d;
                }
            } catch (\Throwable $e) {
            }
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Config array for import() from the SN's row. */
    private static function cfgForSn(string $sn): array
    {
        $row = self::rowForSn($sn, true);

        return [
            'emp_prefix' => $row->emp_prefix ?? '',
            'in_machine_id' => $row->in_machine_id ?? '',
            'out_machine_id' => $row->out_machine_id ?? '',
            'tenant_id' => $row->tenant_id ?? null,
        ];
    }

    /** Find (or auto-create) the push device row for this SN. */
    private static function rowForSn(string $sn, bool $create = false)
    {
        self::ensure();
        if ($sn === '') {
            return null;
        }
        $row = DB::table('biometric_configs')->where('provider', 'push')->where('serial_number', $sn)->orderByDesc('id')->first();
        if (! $row && $create) {
            try {
                $id = DB::table('biometric_configs')->insertGetId([
                    'provider' => 'push',
                    'serial_number' => $sn,
                    'label' => 'Push device '.$sn,
                    'enabled' => true,
                    'empcode' => 'ALL',
                    'tenant_id' => null,
                    'last_status' => 'auto-registered',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('biometric_configs')->where('id', $id)->first();
            } catch (\Throwable $e) {
                $row = null;
            }
        }

        return $row;
    }

    private static function touch(string $sn): void
    {
        if ($sn === '') {
            return;
        }
        $row = self::rowForSn($sn, true);
        if ($row) {
            try {
                DB::table('biometric_configs')->where('id', $row->id)->update(['last_sync_at' => now(), 'updated_at' => now()]);
            } catch (\Throwable $e) {
            }
        }
    }

    /** Create biometric_configs / add the columns push needs, if missing. */
    private static function ensure(): void
    {
        if (! Schema::hasTable('biometric_configs')) {
            Schema::create('biometric_configs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('provider', 40)->default('etimeoffice');
                $t->boolean('enabled')->default(false);
                $t->string('base_url')->nullable();
                $t->string('endpoint')->nullable();
                $t->string('corp_id')->nullable();
                $t->string('username')->nullable();
                $t->text('password')->nullable();
                $t->string('empcode')->default('ALL');
                $t->string('emp_prefix', 20)->nullable();
                $t->timestamp('last_sync_at')->nullable();
                $t->string('last_status')->nullable();
                $t->integer('last_count')->default(0);
                $t->timestamps();
            });
        }
        $add = [
            'serial_number' => fn ($t) => $t->string('serial_number', 80)->nullable(),
            'in_machine_id' => fn ($t) => $t->string('in_machine_id', 40)->nullable(),
            'out_machine_id' => fn ($t) => $t->string('out_machine_id', 40)->nullable(),
            'label' => fn ($t) => $t->string('label', 120)->nullable(),
            'branch' => fn ($t) => $t->string('branch', 120)->nullable(),
            'sync_interval_min' => fn ($t) => $t->integer('sync_interval_min')->nullable(),
        ];
        foreach ($add as $c => $fn) {
            if (! Schema::hasColumn('biometric_configs', $c)) {
                try {
                    Schema::table('biometric_configs', function ($t) use ($fn) {
                        $fn($t);
                    });
                } catch (\Throwable $e) {
                }
            }
        }
    }
}
