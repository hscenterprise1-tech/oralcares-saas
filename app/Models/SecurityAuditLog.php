<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One entry in the security trail. Written only through `SecurityAudit`.
 *
 * Append-only at the application layer: saving an existing row or deleting
 * one throws. That keeps the trail from being edited through the app. It
 * does not stop someone with direct database access. Shipping these rows to
 * an external, write-once log store is the step that closes that gap (see
 * the compliance register).
 */
class SecurityAuditLog extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = [
        'event',
        'actor_user_id',
        'clinic_id',
        'branch_id',
        'subject_type',
        'subject_id',
        'ip_address',
        'user_agent',
        'request_id',
        'context',
        'occurred_at',
        'prev_hash',
        'entry_hash',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Security audit entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Security audit entries cannot be deleted.'));
    }
}
