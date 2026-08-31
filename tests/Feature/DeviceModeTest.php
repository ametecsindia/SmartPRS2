<?php

/**
 * 28 Aug 2026 — Biometric device setup: 1 Device vs 2 Devices (IN + OUT).
 *
 * Two promises are pinned here, and they pull in opposite directions:
 *
 *   NEW      a location with ONE reader gets alternating IN/OUT, computed per
 *            employee per attendance day, idempotently.
 *   UNCHANGED a device configured before this feature existed (device_mode
 *            NULL) behaves exactly as it did — machine-ID match, else the
 *            feed's own flag. Three self-hosted installs depend on that.
 */

use App\Services\ETimeOfficeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* ---------------------------------------------------------------------------
 | helpers
 |-------------------------------------------------------------------------- */

/** A parsed punch, in the shape every provider's parse() emits. */
function dmPunch(string $code, string $at, string $dir = 'in', string $machine = ''): array
{
    return [
        'emp_code' => $code,
        'name' => null,
        'punch_at' => Carbon::createFromFormat('Y-m-d H:i:s', $at),
        'direction' => $dir,
        'machine' => $machine,
    ];
}

/** The cfg array rowToCfg()/cfgForSn() produce, with the device setup applied. */
function dmCfg(?int $tenantId, ?string $mode, string $in = '', string $out = ''): array
{
    return [
        'emp_prefix' => '',
        'in_machine_id' => $in,
        'out_machine_id' => $out,
        'device_mode' => $mode,
        'tenant_id' => $tenantId,
        'source' => 'push',
    ];
}

function dmEmployee(int $tid, int $cid, string $code): int
{
    return DB::table('employees')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $tid, 'company_id' => $cid,
        'emp_code' => $code, 'name' => 'Emp '.$code,
        'type' => 'office', 'status' => 'active',
        'ctc' => 0, 'salary_type' => 'only_salary',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Stored directions for one employee/day, in punch order. */
function dmDirections(string $code): array
{
    return DB::table('attendance_logs')
        ->where('emp_code', $code)
        ->orderBy('punch_at')
        ->pluck('direction')
        ->all();
}

/* ---------------------------------------------------------------------------
 | 1 Device — alternating
 |-------------------------------------------------------------------------- */

it('alternates IN and OUT for a one-device location', function () {
    [$tid, $cid] = makeTenantCompany();
    dmEmployee($tid, $cid, 'E1');

    // The device reports 'in' on every punch — a very common single-reader
    // default, and exactly the wrongness this feature exists to correct.
    ETimeOfficeService::import([
        dmPunch('E1', '2026-08-28 09:00:00'),
        dmPunch('E1', '2026-08-28 13:00:00'),
        dmPunch('E1', '2026-08-28 14:00:00'),
        dmPunch('E1', '2026-08-28 18:00:00'),
        dmPunch('E1', '2026-08-28 20:00:00'),
    ], dmCfg($tid, 'single', '101'));

    expect(dmDirections('E1'))->toBe(['in', 'out', 'in', 'out', 'in']);
});

it('counts the sequence per employee and per attendance day, not per batch', function () {
    [$tid, $cid] = makeTenantCompany();
    dmEmployee($tid, $cid, 'E1');
    dmEmployee($tid, $cid, 'E2');

    ETimeOfficeService::import([
        dmPunch('E1', '2026-08-28 09:00:00'),
        dmPunch('E2', '2026-08-28 09:05:00'),   // a different person restarts at IN
        dmPunch('E1', '2026-08-28 18:00:00'),
        dmPunch('E1', '2026-08-29 09:00:00'),   // a new day restarts at IN
        dmPunch('E2', '2026-08-28 18:05:00'),
    ], dmCfg($tid, 'single'));

    expect(dmDirections('E1'))->toBe(['in', 'out', 'in']);
    expect(dmDirections('E2'))->toBe(['in', 'out']);
});

it('continues the day sequence across separate syncs', function () {
    [$tid, $cid] = makeTenantCompany();
    dmEmployee($tid, $cid, 'E1');
    $cfg = dmCfg($tid, 'single');

    // Real-time push: one punch per request, hours apart.
    ETimeOfficeService::import([dmPunch('E1', '2026-08-28 09:00:00')], $cfg);
    ETimeOfficeService::import([dmPunch('E1', '2026-08-28 13:00:00')], $cfg);
    ETimeOfficeService::import([dmPunch('E1', '2026-08-28 14:00:00')], $cfg);

    expect(dmDirections('E1'))->toBe(['in', 'out', 'in']);
});

it('is idempotent — re-syncing the same window changes nothing', function () {
    [$tid, $cid] = makeTenantCompany();
    dmEmployee($tid, $cid, 'E1');
    $cfg = dmCfg($tid, 'single');
    $batch = [
        dmPunch('E1', '2026-08-28 09:00:00'),
        dmPunch('E1', '2026-08-28 13:00:00'),
        dmPunch('E1', '2026-08-28 18:00:00'),
    ];

    ETimeOfficeService::import($batch, $cfg);
    ETimeOfficeService::import($batch, $cfg);
    ETimeOfficeService::import($batch, $cfg);

    expect(DB::table('attendance_logs')->where('emp_code', 'E1')->count())->toBe(3);
    expect(dmDirections('E1'))->toBe(['in', 'out', 'in']);
});

it('re-stamps the rest of the day when an earlier punch arrives late', function () {
    [$tid, $cid] = makeTenantCompany();
    dmEmployee($tid, $cid, 'E1');
    $cfg = dmCfg($tid, 'single');

    ETimeOfficeService::import([
        dmPunch('E1', '2026-08-28 09:00:00'),
        dmPunch('E1', '2026-08-28 18:00:00'),
    ], $cfg);
    expect(dmDirections('E1'))->toBe(['in', 'out']);

    // A buffered device uploads the real first punch of the day afterwards.
    ETimeOfficeService::import([dmPunch('E1', '2026-08-28 08:00:00')], $cfg);

    // 08:00 is now the IN and everything after it shifts, rather than the day
    // ending up with two INs and no OUT.
    expect(dmDirections('E1'))->toBe(['in', 'out', 'in']);
});

it('treats a re-read within a minute as one punch, not the next leg', function () {
    [$tid, $cid] = makeTenantCompany();
    dmEmployee($tid, $cid, 'E1');

    ETimeOfficeService::import([
        dmPunch('E1', '2026-08-28 09:00:00'),
        dmPunch('E1', '2026-08-28 09:00:20'),   // finger read twice
        dmPunch('E1', '2026-08-28 18:00:00'),
    ], dmCfg($tid, 'single'));

    // Without the repeat window the double-tap would make 18:00 an IN and the
    // day would compute as zero hours worked.
    expect(dmDirections('E1'))->toBe(['in', 'in', 'out']);
});

/* ---------------------------------------------------------------------------
 | 2 Devices — unchanged, device decides
 |-------------------------------------------------------------------------- */

it('keeps machine-based direction for a two-device location', function () {
    [$tid, $cid] = makeTenantCompany();
    dmEmployee($tid, $cid, 'E1');

    // parse() already resolved the direction from the machine number; import()
    // must not touch it.
    ETimeOfficeService::import([
        dmPunch('E1', '2026-08-28 09:00:00', 'in', '101'),
        dmPunch('E1', '2026-08-28 13:00:00', 'out', '102'),
        dmPunch('E1', '2026-08-28 14:00:00', 'in', '101'),
        dmPunch('E1', '2026-08-28 14:30:00', 'in', '101'),   // forgot to punch out
    ], dmCfg($tid, 'dual', '101', '102'));

    expect(dmDirections('E1'))->toBe(['in', 'out', 'in', 'in']);
});

it('leaves a device configured before this feature exactly as it was', function () {
    [$tid, $cid] = makeTenantCompany();
    dmEmployee($tid, $cid, 'E1');

    // device_mode NULL — every row that existed before the migration.
    ETimeOfficeService::import([
        dmPunch('E1', '2026-08-28 09:00:00', 'in'),
        dmPunch('E1', '2026-08-28 13:00:00', 'in'),
        dmPunch('E1', '2026-08-28 18:00:00', 'out'),
    ], dmCfg($tid, null));

    expect(dmDirections('E1'))->toBe(['in', 'in', 'out']);
});

/* ---------------------------------------------------------------------------
 | isolation between locations
 |-------------------------------------------------------------------------- */

it('keeps each location independent — one single-device branch does not restamp another feed', function () {
    [$tid, $cid] = makeTenantCompany();
    dmEmployee($tid, $cid, 'E1');

    // Ground floor: one reader, source 'push'.
    ETimeOfficeService::import([
        dmPunch('E1', '2026-08-28 09:00:00'),
        dmPunch('E1', '2026-08-28 13:00:00'),
    ], dmCfg($tid, 'single'));

    // A different feed for the same person on the same day.
    $other = dmCfg($tid, 'dual', '201', '202');
    $other['source'] = 'etimeoffice';
    ETimeOfficeService::import([
        dmPunch('E1', '2026-08-28 15:00:00', 'in', '201'),
        dmPunch('E1', '2026-08-28 19:00:00', 'out', '202'),
    ], $other);

    expect(DB::table('attendance_logs')->where('source', 'push')->orderBy('punch_at')->pluck('direction')->all())
        ->toBe(['in', 'out']);
    expect(DB::table('attendance_logs')->where('source', 'etimeoffice')->orderBy('punch_at')->pluck('direction')->all())
        ->toBe(['in', 'out']);
});
