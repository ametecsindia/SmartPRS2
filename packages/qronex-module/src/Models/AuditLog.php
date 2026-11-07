<?php

namespace Ametecs\Qronex\Models;

use Ametecs\Qronex\Models\Concerns\Tenanted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditLog extends Model
{
    use Tenanted;

    public const UPDATED_AT = null;

    protected $table = 'qx_audit_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(config('auth.providers.users.model')); }

    public static function record(string $action, ?string $subject = null, array $meta = [], ?int $tenantId = null): void
    {
        static::create(['tenant_id' => $tenantId ?? \Ametecs\Qronex\Qronex::tenantId(), 'user_id' => auth()->id(), 'action' => $action,
            'subject' => $subject, 'meta' => $meta ?: null, 'ip' => request()?->ip()]);
    }
}
