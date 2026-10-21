<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One wording of a consent type. Never changed or removed once written: a
 * signed consent points at it.
 */
class ClinicConsentTypeVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['clinic_consent_type_id', 'clinic_id', 'version', 'text', 'text_hash', 'created_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A consent wording is never changed; write a new version.'));
        static::deleting(fn () => throw new LogicException('A consent wording is never removed.'));
    }
}
