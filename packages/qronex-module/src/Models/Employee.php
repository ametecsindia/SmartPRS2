<?php

namespace Ametecs\Qronex\Models;

use Ametecs\Qronex\Models\Concerns\Tenanted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use Tenanted;

    public const SCAN_SCOPES = ['gate' => 'Only the bound gate', 'office' => 'Any gate of the bound office', 'any' => 'Any office and gate'];

    protected $table = 'qx_employees';

    protected $guarded = ['id'];

    protected $hidden = ['activation_code_hash'];

    protected function casts(): array
    {
        return ['activation_expires_at' => 'datetime'];
    }

    public function office(): BelongsTo { return $this->belongsTo(Office::class); }
    public function gate(): BelongsTo { return $this->belongsTo(Gate::class); }
    public function devices(): HasMany { return $this->hasMany(Device::class); }
    public function events(): HasMany { return $this->hasMany(AttendanceEvent::class); }

    /** One phone per employee; more only as an exception granted by an admin (configure ability). */
    public function deviceLimit(): int
    {
        return max(1, (int) $this->max_devices);
    }

    /** Can this employee punch at $gate? null = yes, otherwise the reason. */
    public function scanBlockedAt(Gate $gate): ?string
    {
        $office = (int) $this->office_id !== (int) $gate->office_id ? 'Assigned office: '.($this->office?->name ?? 'none') : null;

        return match ($this->scan_scope ?: 'office') {
            'any' => null,
            'gate' => $this->gate_id && (int) $this->gate_id !== (int) $gate->id ? 'Bound to gate '.($this->gate?->name ?? '#'.$this->gate_id).' only' : $office,
            default => $office,
        };
    }

    /** Inactive + every phone revoked (the app is signed out). */
    public function deactivate(?int $byUserId = null, string $reason = 'Employee deactivated'): int
    {
        $this->update(['status' => 'inactive']);
        $devices = Device::withoutGlobalScopes()->where('employee_id', $this->id)->where('status', '!=', 'revoked')->get();
        foreach ($devices as $d) {
            $d->update(['status' => 'revoked', 'token_hash' => null, 'approval_code' => null]);
            $d->log('revoked', $byUserId, $reason);
        }

        return $devices->count();
    }
}
