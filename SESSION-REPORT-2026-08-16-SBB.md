# Session Report — Smart Biometric Bridge (SBB) ingest path

**Date:** 16 August 2026
**Scope:** Add a second, authenticated JSON punch-ingest path to SmartPRS
**Working copies touched:** `C:\laragon\www\smartprs2`, `C:\laragon\www\smartprs-l3`
**Database changes applied:** **NONE** — migrations are written but have not been run
**Git commits made:** **NONE** — all work is uncommitted in the working tree

---

## 1. Executive summary

SmartPRS previously received biometric punches by exactly one route: emulating the
ZKTeco/eSSL ADMS `iclock` protocol. That route has no authentication, no tenant
binding, no idempotency, no error reporting, and silently discards any punch whose
device PIN is not yet mapped to an employee.

This session added a **second, parallel route** for Smart Biometric Bridge (SBB), the
on-premise Windows service that collects punches from any vendor's device and forwards
them over HTTPS. The new route is authenticated, tenant-bound, idempotent at the
database level, and reports a verdict for every single punch.

**The old route is untouched.** Not one character of `PushController` or the `/iclock`
routes changed. Existing customer hardware is unaffected. A customer may use either
route, or both simultaneously on different devices.

---

## 2. What existed before

### 2.1 The single ingest path

```
Device  ──►  GET/POST /iclock/cdata?SN=…      (routes/web.php:92)
             └─► PushController::cdata
                 └─► PushController::importAttlog
                     └─► ETimeOfficeService::import
                         └─► attendance_logs
```

Three other pull-based connectors (`ETimeOfficeService`, `ETimeTrackLiteService`,
`GenericApiService`) also converge on `ETimeOfficeService::import`.

### 2.2 The `attendance_logs` table

Created by `2026_06_24_000000_ensure_runtime_feature_tables.php`:

| Column | Type |
|---|---|
| `id` | bigint PK |
| `tenant_id` | bigint, nullable, indexed |
| `company_id` | bigint, nullable, indexed |
| `emp_code` | string, indexed |
| `emp_name` | string, nullable |
| `log_date` | date, indexed |
| `punch_at` | datetime |
| `direction` | **string(4)** |
| `source` | string, default `'biometric'` |
| `created_at` / `updated_at` | timestamps |

No unique constraint of any kind.

### 2.3 The five defects this work addresses

Each of these is a real, current behaviour in the codebase, cited by file and line.

**D1 — Cross-customer data leak.**
`PushController::cfgForSn` returns `tenant_id` from the device's `biometric_configs`
row. An unknown serial auto-registers with `tenant_id = NULL`
(`PushController.php:214`). That NULL flows into
`ETimeOfficeService::matchEmployee($full, $raw, null)`, and at
`ETimeOfficeService.php:321` the tenant filter is applied **only if `$tid` is truthy**:

```php
if ($tid && Schema::hasColumn('employees', 'tenant_id')) {
    $q->where('tenant_id', $tid);
}
```

With `$tid = null` the filter is skipped entirely, so a punch for `EMP001` matches
*whichever customer's* `EMP001` the database returns first. One customer's attendance
can be written against another customer's employee.

**D2 — Silent timezone corruption.**
`PushController::parseDate` tries four fixed formats, then falls back to
`Carbon::parse($v)` (`PushController.php:178`). `Carbon::parse` **honours** an offset
in the string. `ETimeOfficeService::import` then stores
`$p['punch_at']->format('Y-m-d H:i:s')` (`ETimeOfficeService.php:420`), which renders
the time *in that offset* and discards the offset itself. The digits are taken
literally. A punch arriving as `2026-08-16 09:41:00+00:00` on an Indian install is
therefore stored as `09:41:00` when the employee actually clocked in at `15:11:00`
local — a 5h30m error, silent, straight into payroll.

**D3 — Permanent loss of unmapped punches.**
`ETimeOfficeService::import` at line ~405:

```php
if (! $emp) {
    $unmatched[$full] = ($unmatched[$full] ?? 0) + 1;
    continue;      // the punch is gone
}
```

Only a **counter** survives, in `biometric_unmapped`. The punch itself is discarded
permanently. During every go-live — precisely the window in which device IDs have not
yet been mapped — real attendance is destroyed and cannot be recovered.

**D4 — No idempotency, and a mechanism that manufactures duplicates.**
Dedupe relied on `updateOrInsert` with an application-side match
(`ETimeOfficeService.php:429`), with no database constraint behind it. Two concurrent
requests both find no row and both insert. This is not theoretical: `DedupeAttendance`
(`attendance:dedupe`) exists specifically to repair duplicates that rev173g produced
this way, and its docblock records that duplicates "break both the Attendance Report
and payroll".

The duplicate-producing loop is worse than a plain race. `ETimeOfficeService::import`
calls `LateArrivalService::notifyTouched` **inline**, which ends at `Mail::raw`
(`LateArrivalService.php:289`). On a slow or unreachable SMTP host the HTTP response
is blocked until the sender times out — and an at-least-once sender that times out
**retries**, creating exactly the duplicate the retry was meant to avoid.

**D5 — Zero observability.**
The ingest path contains no logging whatsoever. Every failure mode is a bare
`continue`. When a customer reports "my punches aren't showing", there is nothing on
the server to diagnose it with.

---

## 3. What changed

### 3.1 New files (12)

| File | Lines | Purpose |
|---|---:|---|
| `database/migrations/2026_08_16_000100_create_api_keys_table.php` | 45 | `api_keys` table |
| `database/migrations/2026_08_16_000200_extend_attendance_logs_for_sbb.php` | 196 | Provenance columns + the two unique indexes |
| `database/migrations/2026_08_16_000300_create_attendance_pending_table.php` | 50 | `attendance_pending` quarantine table |
| `app/Services/ApiKeys.php` | 78 | Key minting, hashing, prefix parsing |
| `app/Services/PunchIngestService.php` | 390 | Ingest, time parsing, quarantine, replay |
| `app/Http/Middleware/ApiKeyAuth.php` | 156 | Key authentication + scope check |
| `app/Http/Controllers/Api/PublicApiController.php` | 118 | `ping` + `ingestPunches` |
| `app/Http/Controllers/ApiKeyController.php` | 267 | Settings → API Keys / Pending Punches |
| `app/Jobs/NotifyLateArrivals.php` | 55 | Queued late-arrival notification |
| `routes/api.php` | 30 | Stateless `/api/v1` routes |
| `resources/views/settings/api-keys.blade.php` | 136 | API Keys screen |
| `resources/views/settings/pending-punches.blade.php` | 74 | Pending Punches screen |

Plus `tests/Feature/SbbIngestTest.php` (481 lines, 23 test cases).

### 3.2 Modified files (4)

| File | Change | Risk |
|---|---|---|
| `bootstrap/app.php` | Added `api:` routing + `api-key` middleware alias | Additive; loaded every request |
| `routes/web.php` | Added 5 routes for the two Settings screens | Additive; no existing route altered |
| `app/Http/Controllers/BiometricConfigController.php` | `mapEmployee` now calls `replayPending` | Guarded by `hasTable`; try/catch |
| `resources/views/layouts/app.blade.php` | 2 sidebar links | Legacy Blade layout only |

### 3.3 Database changes (written, **not yet applied**)

**`api_keys`** — new. Mirrors SmartEPT's design plus `expires_at`. Stores only the
sha256 of the secret; the plaintext is shown once at creation and never again.

**`attendance_logs`** — extended. All new columns nullable, so every existing row stays
valid and every existing writer keeps working:

- `device_sn` string(64) nullable indexed
- `device_user_id` string(64) nullable
- `external_id` string(96) nullable — the sender's idempotency key
- `verify_mode` string(24) nullable
- `direction` widened **string(4) → string(8)** (so `'unknown'` fits; a truncated
  direction is a wrong attendance record)

Two unique indexes:

- `attlog_tenant_external_unique (tenant_id, external_id)`
- `attlog_natural_unique (tenant_id, emp_code, punch_at, source)`

**`attendance_pending`** — new quarantine table, unique on `(tenant_id, external_id)`.

> **Ordering is load-bearing.** Production data already contains duplicates on the
> natural key. The migration runs `attendance:dedupe` **before** creating
> `attlog_natural_unique`, with an inline fallback if the command is unavailable. If
> the index were created first the migration would abort on any real install. This was
> verified empirically — see §5.2.

### 3.4 The new API contract

```
GET  /api/v1/ping                  middleware: api-key
POST /api/v1/attendance/punches    middleware: api-key:ingest
```

Both under `throttle:300,1`. Authentication via `X-Api-Key: <key>` or
`Authorization: Bearer <key>`.

**`GET /api/v1/ping` → 200**

```json
{
  "ok": true,
  "app": "SmartPRS",
  "version": "2026.6.1",
  "tenant_id": 3,
  "company_name": "Acme Textiles Pvt Ltd",
  "scopes": ["ingest"],
  "timezone": "Asia/Kolkata",
  "server_time": "2026-08-16T09:41:00+05:30"
}
```

`company_name` lets an installer standing in a plant confirm the key points at the
*right customer*. `timezone` + `server_time` let SBB detect a clock mismatch before it
starts shipping punches into the wrong hour.

**`POST /api/v1/attendance/punches` → 200, a verdict per punch**

```json
{
  "ok": true,
  "batch": { "received": 10, "accepted": 8, "duplicates": 1, "pending": 1, "rejected": 0 },
  "results": [
    { "external_id": "3f9c…", "status": "accepted" },
    { "external_id": "7b2a…", "status": "duplicate" },
    { "external_id": "c41d…", "status": "pending",  "reason": "EMPLOYEE_NOT_MAPPED" },
    { "external_id": "9e01…", "status": "rejected", "reason": "TIME_FORMAT" }
  ]
}
```

**Error body** (401 / 403 / 422):

```json
{ "error": { "code": "API_KEY_401", "message": "…" } }
```

Codes: `API_KEY_401`, `API_KEY_403`, `VALIDATION_422`.

**Status meanings for the sender:**

| Status | Meaning | Should SBB retry? |
|---|---|---|
| `accepted` | Stored in `attendance_logs` | No |
| `duplicate` | Already stored; re-send was a no-op | No |
| `pending` | Held in quarantine, PIN not yet mapped | No — it is safe |
| `rejected` | Not stored. See `reason` | Only after fixing the cause |

Reject reasons: `TIME_FORMAT` (bad or offset-bearing timestamp), `VALIDATION`
(missing/oversized field, bad direction).

---

## 4. Why each change was made, and its impact

| # | Change | Fixes | Impact |
|---|---|---|---|
| 1 | Tenant taken from the API key, always passed to `matchEmployee` | **D1** | Cross-customer matching is structurally impossible on this path. An employee code belonging to another tenant now returns `pending`, not a wrong match. |
| 2 | Strict naive-local time parsing; offsets **rejected** | **D2** | A malformed timestamp becomes a visible `TIME_FORMAT` rejection instead of a silent 5h30m payroll error. No timezone is ever guessed. |
| 3 | `attendance_pending` quarantine + replay on mapping | **D3** | Go-live no longer destroys attendance. Punches are held, reported to the sender, listed for the admin, and released the moment the ID is mapped. |
| 4 | `insertOrIgnore` against two DB unique indexes | **D4** | Idempotency is a database guarantee, not a hopeful `SELECT`. Concurrent retries cannot race. |
| 5 | Late-arrival mail moved to a queued job | **D4** | The response no longer blocks on SMTP, removing the timeout→retry→duplicate loop at its source. |
| 6 | `Log::info` per batch, `Log::warning` per rejection | **D5** | "My punches aren't showing" is now diagnosable from the server log alone. |
| 7 | Per-punch verdicts in the response | **D4/D5** | An at-least-once sender knows exactly what landed. No silent discards. |

### 4.1 Impact on existing behaviour

**Unchanged:** `/iclock` endpoints, `PushController`, `ETimeOfficeService::import`,
`ETimeTrackLiteService`, `GenericApiService`, all pull-based syncs, all existing
`attendance_logs` rows, all reports and payroll logic.

**Changed for all writers, after migration:** the two unique indexes constrain
`attendance_logs` globally, including the legacy path. This was checked:
`ETimeOfficeService::import` uses `updateOrInsert` whose match keys are
`(tenant_id, emp_code, punch_at, source)` — *exactly* `attlog_natural_unique`. It
therefore updates rather than inserts, and cannot violate the constraint. Verified
empirically in §5.2.

**One-time destructive step:** `attendance:dedupe` deletes duplicate rows, keeping the
newest of each group. This is the existing, already-shipped repair command; the
migration invokes it because the index cannot be built otherwise.

### 4.2 Deliberate deviation from the specification

`api_keys.tenant_id` is nullable, per SmartEPT parity as specified. **However**,
`ApiKeyAuth` refuses a null-tenant key on the `ingest` scope with a 401.

*Reason:* both `attendance_logs` unique indexes include `tenant_id`, and **NULL never
collides in a unique index** in either MySQL or SQLite. A tenant-less key would
therefore silently lose its de-duplication guarantee and re-insert every retried punch
— the exact defect being fixed. Refusing the key is the only way the idempotency
promise is actually true.

*Blast radius:* none. `InstallController` creates a tenant row even on on-prem
installs (`InstallController.php:192`), and the admin UI stamps the creating user's
tenant onto every key.

---

## 5. Testing

### 5.1 Automated tests — written, **not yet executed**

`tests/Feature/SbbIngestTest.php` — 23 test cases (one parameterised over 6 timestamp
formats, so 28 assertions-groups in total).

> **These have not been run.** Packagist is blocked from the environment this work was
> done in, so no Laravel harness could be built, and there was no shell access to the
> Windows machine. **Running them is the real acceptance gate — see §6.**

| # | Test | Proves |
|---|---|---|
| 1 | ping identifies the customer | `company_name`, `tenant_id`, `scopes`, `timezone` returned |
| 2 | ingest stores a punch | Row lands with correct emp, tenant, source, device fields |
| 3 | same `external_id` twice | `accepted` then `duplicate`, exactly **one** row |
| 4 | same moment, new `external_id` | Natural key catches it, one row |
| 5 | unmapped PIN | Returns `pending`, row held in `attendance_pending`, **not lost** |
| 6 | mapping the PIN afterwards | Held punch promoted, `resolved_at` stamped, not replayable twice |
| 7 | `punch_at` with `+05:30` | **Rejected** with `TIME_FORMAT`, nothing stored |
| 8 | 6 more bad timestamp formats | All rejected (`Z`, `T…+0530`, ` UTC`, `d/m/Y`, `2026-02-30`, missing seconds) |
| 9 | wall clock stored literally | `09:41:00` stays `09:41:00`, never shifted to UTC |
| 10 | tenant A cannot write to tenant B | Both tenants have `EMP001`; A's key writes only to A |
| 11 | `tenant_id` in request body ignored | Body value discarded, key's tenant used |
| 12 | employee belongs to another tenant | Returns `pending`, not a wrong match |
| 13–16 | missing / unknown / expired / revoked key | 401 with `API_KEY_401` |
| 17 | key without `ingest` scope | 403 with `API_KEY_403`; same key still works on `ping` |
| 18 | `Authorization: Bearer` | Accepted as an alternative to `X-Api-Key` |
| 19 | `last_used_at` | Written on use |
| 20 | mixed batch | 1 accepted + 1 duplicate + 1 pending + 1 rejected, all four reported |
| 21 | one malformed punch | Rejected individually; its good neighbour still lands |
| 22 | empty / oversized batch | 422 with `VALIDATION_422` |
| 23 | `/iclock` still works | ADMS handshake and ATTLOG upload both still respond 200 |

### 5.2 What *was* verified in this session

**a. Syntax.** `php -l` clean on all 17 PHP files. Blade directives balance
(8 `@if` / 8 `@endif`, 2 `@foreach` / 2 `@endforeach`, 1 `@section` / 1 `@endsection`).

**b. Time-parsing rules — 45 cases, all passing.** The parser was extracted and run
against 15 inputs × 3 timezones (`Asia/Kolkata`, `UTC`, `America/New_York`):

| Input | Result |
|---|---|
| `2026-08-16 09:41:00` | accepted, unchanged |
| `2026-08-16 09:41:00+05:30` | rejected |
| `2026-08-16 09:41:00-08:00` | rejected |
| `2026-08-16T09:41:00Z` | rejected |
| `2026-08-16T09:41:00+0530` | rejected |
| `2026-08-16 09:41:00 UTC` / ` IST` | rejected |
| `16/08/2026 09:41:00` | rejected |
| `2026-02-30 09:41:00` | rejected (roll-over guard) |
| `2026-13-01`, `25:00:00`, `09:41`, `''` | rejected |

**c. Dedupe and unique-index semantics — verified in real SQLite.** A table matching
the post-migration schema was built and exercised:

```
rows before dedupe: 3
index refused while duplicates exist (as designed)   ← proves the ordering matters
rows after dedupe:  2
both unique indexes created OK
legacy rows with NULL external_id coexist: 4         ← history unaffected
1st send                  -> inserted=1  (accepted)
identical re-send         -> inserted=0  (duplicate)
same moment, new ext id   -> inserted=0  (duplicate)
same ext id, other tenant -> inserted=1  (accepted)
legacy updateOrInsert     -> updated=1   (no constraint violation)
EMP1043 in tenant 1: 1
```

The second line is the important one: creating the natural-key index **fails** while
duplicates are present. That is the empirical justification for running
`attendance:dedupe` first.

**d. Compatibility checks.**
- All four modified files were byte-identical between `smartprs2` and `smartprs-l3`
  before editing, so the same content is correct for both.
- Code is PHP 8.2-compatible (`smartprs-l3` pins 8.2; `smartprs2` requires ^8.3). No
  8.3-only syntax used.
- The new routes `app/api-keys` and `app/pending-punches` match no pattern in
  `Edition::BLOCK_SAAS`, so `EditionGuard` will not block them on on-prem builds.

### 5.3 Manual smoke test (after migration)

```bash
# 1. Create a key in the UI:  Settings → API Keys → Create key (scope: ingest)
#    Copy the secret — it is shown once.

# 2. Confirm you hit the right customer
curl -H "X-Api-Key: sk_prs_xxxx_…" https://your-server/api/v1/ping

# 3. Send one punch
curl -X POST https://your-server/api/v1/attendance/punches \
  -H "X-Api-Key: sk_prs_xxxx_…" \
  -H "Content-Type: application/json" \
  -d '{"punches":[{
        "external_id":"smoke-test-001",
        "device_sn":"AXK7231900123",
        "device_user_id":"1043",
        "punch_at":"2026-08-16 09:41:00",
        "direction":"IN",
        "verify_mode":"FINGERPRINT"}]}'

# 4. Send the SAME payload again — must return "duplicate", and the row count must stay 1
```

Then confirm in the database:

```sql
SELECT emp_code, punch_at, direction, source, external_id
FROM attendance_logs WHERE external_id = 'smoke-test-001';
-- exactly ONE row, punch_at exactly 2026-08-16 09:41:00
```

---

## 6. Update instructions

### 6.1 Local / development (`C:\laragon\www\smartprs2`)

```bash
cd C:\laragon\www\smartprs2

# 1. See exactly what changed
git status
git diff

# 2. Run the test suite — THIS IS THE ACCEPTANCE GATE
vendor\bin\pest tests/Feature/SbbIngestTest.php

# 3. Only if green, apply the schema
php artisan attendance:dedupe --dry      # look first; deletes nothing
php artisan attendance:dedupe            # deletes duplicates
php artisan migrate

# 4. Clear caches (new routes + new middleware alias)
php artisan optimize:clear

# 5. Full suite, to confirm nothing else regressed
vendor\bin\pest
```

If the tests fail, **do not migrate**. Send me the failure output.

### 6.2 Live / production server

> Do this in a maintenance window. Step 3 deletes rows and step 4 locks
> `attendance_logs` while the indexes build. On a large table that can take minutes.

```bash
# 0. BACK UP THE DATABASE FIRST — non-negotiable
mysqldump -u root -p smartprs > smartprs_backup_2026-08-16.sql

# 1. Stop the biometric feed so nothing writes mid-migration
#    (pause SBB, and/or block /iclock at the firewall for the window)

# 2. Deploy the code (git pull, or copy the 16 files)

# 3. Inspect, then clear duplicates
php artisan attendance:dedupe --dry      # READ THIS OUTPUT before continuing
php artisan attendance:dedupe

# 4. Apply the schema
php artisan migrate --force

# 5. Rebuild caches
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

# 6. Restart the queue worker — NotifyLateArrivals uses the 'mail' queue
php artisan queue:restart

# 7. Verify BEFORE re-enabling the feed
curl -H "X-Api-Key: <key>" https://your-server/api/v1/ping
curl "https://your-server/iclock/cdata?SN=<a-real-serial>&options=all"   # old path still OK

# 8. Re-enable the biometric feed
```

**Queue worker.** If no worker is running, `QUEUE_CONNECTION=sync` makes the job run
inline — notifications still go out, just synchronously. To get the async behaviour
the job exists for, run a worker:

```bash
php artisan queue:work --queue=mail,default
```

### 6.3 `smartprs-l3` (client build)

This folder is **not a git repository** — there is no `.git` directory, so there is no
version history and no `git checkout` to fall back on. The 16 files were copied in
directly. Treat this folder as a build artifact: if it is regenerated from `smartprs2`,
the changes will come across with it.

### 6.4 Rollback

**Code, `smartprs2`:** `git checkout -- <file>` for the 4 modified files; delete the
12 new files. Or `git stash` / `git reset --hard` if nothing else is in the working
tree.

**Code, `smartprs-l3`:** no git. Restore from backup, or re-copy from `smartprs2`.

**Database:** restore the dump from §6.2 step 0. Note that `attendance:dedupe` deletes
rows and `migrate:rollback` will **not** bring them back — the dump is the only way
back. The migration's own `down()` drops the two unique indexes and the four new
columns, which is safe but does not restore deleted duplicates.

---

## 7. Git status

### 7.1 `smartprs2`

| | |
|---|---|
| Repository | Yes |
| Branch | `main` |
| Remote | `origin` → `https://github.com/ametecsindia/SmartPRS2.git` |
| Last commit | `11-08-2026 update 2` (11 Aug 2026, 10:24 UTC) |
| **Commits made this session** | **None** |
| **Pushed** | **No** |

Nothing has been committed or pushed. I have no shell access to the machine, so `git`
was never invoked. All changes sit uncommitted in the working tree.

`git status` will show:

**Modified (4)**
```
bootstrap/app.php
routes/web.php
app/Http/Controllers/BiometricConfigController.php
resources/views/layouts/app.blade.php
```

**Untracked (13)**
```
database/migrations/2026_08_16_000100_create_api_keys_table.php
database/migrations/2026_08_16_000200_extend_attendance_logs_for_sbb.php
database/migrations/2026_08_16_000300_create_attendance_pending_table.php
app/Services/ApiKeys.php
app/Services/PunchIngestService.php
app/Http/Middleware/ApiKeyAuth.php
app/Http/Controllers/Api/PublicApiController.php
app/Http/Controllers/ApiKeyController.php
app/Jobs/NotifyLateArrivals.php
routes/api.php
resources/views/settings/api-keys.blade.php
resources/views/settings/pending-punches.blade.php
tests/Feature/SbbIngestTest.php
```

None of these are covered by `.gitignore`, so all 17 will appear.

**Suggested commit, once the tests pass:**

```bash
git checkout -b feature/sbb-ingest
git add bootstrap/app.php routes/api.php routes/web.php \
        app/Services/ApiKeys.php app/Services/PunchIngestService.php \
        app/Http/Middleware/ApiKeyAuth.php \
        app/Http/Controllers/Api/PublicApiController.php \
        app/Http/Controllers/ApiKeyController.php \
        app/Http/Controllers/BiometricConfigController.php \
        app/Jobs/NotifyLateArrivals.php \
        database/migrations/2026_08_16_0001*.php \
        database/migrations/2026_08_16_0002*.php \
        database/migrations/2026_08_16_0003*.php \
        resources/views/settings/ resources/views/layouts/app.blade.php \
        tests/Feature/SbbIngestTest.php
git commit -m "Add authenticated SBB JSON ingest path (/api/v1)

Second ingest path alongside the unchanged /iclock ADMS emulation.
Tenant comes from the API key, naive-local time parsing rejects offsets,
dedupe enforced by DB unique indexes, unmapped PINs quarantined and
replayed on mapping, per-punch verdicts, late-arrival mail queued."
```

A branch is recommended over committing straight to `main`, because the migration
performs a one-way destructive dedupe and should be merged deliberately.

### 7.2 `smartprs-l3`

Not a git repository. No version control, no history, no rollback path other than a
file backup.

---

## 8. Open items

1. **Run the test suite.** Nothing here is proven until `vendor\bin\pest
   tests/Feature/SbbIngestTest.php` is green. This is the single most important
   outstanding action.
2. **SPA navigation.** The two Settings screens are standalone Blade pages on
   `layouts/app`, reachable at `/app/api-keys` and `/app/pending-punches`. Your live UI
   is the SPA in `AppController.php` (1.1 MB), which I did not touch — if that is the
   interface users actually see, the two menu entries need adding there as well.
3. **Employee matching strategy.** A punch matches by `employee_code` when SBB sends
   it, otherwise by the device serial's configured `emp_prefix` + `device_user_id`. If
   SBB will not send `employee_code`, confirm each device row in Biometric Device Setup
   has its `emp_prefix` set correctly.
4. **Confirm the null-tenant guard** (§4.2) is acceptable, or ask for it to be removed.
5. **`smartprs-l3` version control.** Consider putting the client build under git, or
   generating it from `smartprs2` by script, so changes like this are traceable.

---

*Prepared 16 August 2026. No database was modified and no commit was made during this
session.*
