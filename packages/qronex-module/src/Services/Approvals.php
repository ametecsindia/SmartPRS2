<?php

namespace Ametecs\Qronex\Services;

use Ametecs\Qronex\Models\AttendanceEvent;
use Ametecs\Qronex\Models\AuditLog;
use Ametecs\Qronex\Models\Device;
use Ametecs\Qronex\Models\PunchCorrection;
use Ametecs\Qronex\Models\RemotePermission;
use Ametecs\Qronex\Qronex;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** One place for every approve/reject decision so audit, notifications and the host sync never diverge (same rules as QRONEX). */
class Approvals
{
    /** Approve a device; if it is a replacement and the limit is reached, the old phone is revoked first. */
    public static function approveDevice(Device $d, ?int $by): void
    {
        DB::transaction(function () use ($d, $by) {
            // lock the employee's phones so two approvals at once can't both pass the limit
            Device::withoutGlobalScopes()->where('employee_id', $d->employee_id)->lockForUpdate()->get();
            if (! in_array(Device::withoutGlobalScopes()->whereKey($d->id)->value('status'), ['pending', 'rejected'], true)) {
                throw new RuntimeException('This phone was already decided.');
            }
            $limit = $d->employee->deviceLimit();
            $active = Device::withoutGlobalScopes()->where('employee_id', $d->employee_id)->where('status', 'approved')->where('id', '!=', $d->id);
            if ($active->count() >= $limit) {
                if (! $d->replaces_device_id) {
                    throw new RuntimeException("{$d->employee->name} already has {$active->count()} approved phone(s); the limit is {$limit}. Revoke one first, or an admin can allow more phones on the employee's page.");
                }
                $old = Device::withoutGlobalScopes()->find($d->replaces_device_id);
                $old?->update(['status' => 'revoked', 'token_hash' => null]);
                $old?->log('replaced', $by, null, ['new_device_id' => $d->id]);
                AuditLog::record('device.replaced', $d->install_ref, ['old_device_id' => $d->replaces_device_id], $d->tenant_id);
            }
            $d->update(['status' => 'approved', 'approved_by' => $by, 'approved_at' => now(), 'approval_code' => null]);
            $d->log('approved', $by);
        });
        Notify::send($d->employee->email, 'Your phone is approved', "Hi {$d->employee->name},\n\nYour phone ({$d->model}) is approved. You can now mark attendance by scanning the QR code at the office entrance.");
    }

    public static function decideRemote(RemotePermission $p, bool $approve, ?int $by): void
    {
        DB::transaction(function () use ($p, $approve, $by) {
            $locked = RemotePermission::withoutGlobalScopes()->whereKey($p->id)->lockForUpdate()->first();
            if ($locked->status !== 'pending') {
                throw new RuntimeException("Permission is already {$locked->status}.");
            }
            if ($approve && ! $p->location_waived && $p->lat === null) {
                throw new RuntimeException('Set an approved location (or waive the location check) before approving.');
            }
            $p->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by, 'decided_at' => now()]);
        });
        AuditLog::record($approve ? 'remote.approved' : 'remote.rejected', $p->ref, ['employee' => $p->employee->employee_code, 'waived' => $p->location_waived], $p->tenant_id);
        $approve ? self::remoteApproved($p)
            : Notify::send($p->employee->email, 'Remote attendance request not approved', "Hi {$p->employee->name},\n\nYour {$p->typeLabel()} request was not approved.");
    }

    public static function remoteApproved(RemotePermission $p): void
    {
        Notify::send($p->employee->email, 'Remote attendance approved', "Hi {$p->employee->name},\n\nYour {$p->typeLabel()} request for {$p->from_date->format('d M Y')} to {$p->to_date->format('d M Y')} is approved. Use \"Remote check-in\" in the app.");
    }

    /** Approving never edits an existing punch: it adds a MANUAL_EXCEPTION event marked adjusted (and writes it to the host). */
    public static function decideCorrection(PunchCorrection $c, bool $approve, ?int $by): ?AttendanceEvent
    {
        $e = DB::transaction(function () use ($c, $approve, $by) {
            $status = PunchCorrection::withoutGlobalScopes()->whereKey($c->id)->lockForUpdate()->value('status');
            if ($status !== 'pending') {
                throw new RuntimeException("Correction is already {$status}.");
            }
            $c->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by, 'decided_at' => now()]);
            AuditLog::record($approve ? 'correction.approved' : 'correction.rejected', $c->ref,
                ['employee' => $c->employee->employee_code, 'type' => $c->type, 'at' => $c->punch_at->toIso8601ZuluString()], $c->tenant_id);
            if (! $approve) {
                return null;
            }

            return AttendanceEvent::create([
                'ref' => AttendanceVerifier::ref('QRX'), 'tenant_id' => $c->tenant_id, 'employee_id' => $c->employee_id, 'office_id' => $c->employee->office_id,
                'type' => $c->type, 'mode' => 'MANUAL_EXCEPTION', 'adjusted' => true, 'punch_correction_id' => $c->id, 'recorded_at' => $c->punch_at,
            ]);
        });
        if ($e) {
            HostSync::push($e->setRelation('employee', $c->employee)->setRelation('correction', $c));
        }

        return $e;
    }

    /** Email the tenant's approvers when something waits for them. */
    public static function notifyApprovers(int $tenantId, string $subject, string $body, string $settingFlag): void
    {
        if (! Qronex::settings($tenantId)->{$settingFlag}) {
            return;
        }
        foreach (Qronex::host()->approverEmails($tenantId) as $email) {
            Notify::send($email, $subject, $body."\n\nOpen QR Attendance → Approvals: ".url(config('qronex.admin_prefix', 'qronex').'/approvals'));
        }
    }
}
