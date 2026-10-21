<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A consent the clinic asks patients for. Its wording lives in immutable
 * versions (`ClinicConsentTypeVersion`); `current_version` is the one a new
 * consent is given against. Written by `ConsentTypes` only.
 */
class ClinicConsentType extends Model
{
    use ClinicScoped;

    protected $fillable = ['clinic_id', 'key', 'label', 'specialty_key', 'current_version', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'current_version' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ClinicConsentTypeVersion::class);
    }
}
