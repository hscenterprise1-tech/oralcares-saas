<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of staff document a clinic collects (Government ID, degree
 * certificate, professional registration...). Each clinic has its own
 * catalogue, seeded from `config('clinic_staff.document_types')`.
 *
 * `audience` is who the type is offered for: practitioner, staff or all.
 */
class StaffDocumentType extends Model
{
    use ClinicScoped;

    public const AUDIENCES = ['practitioner', 'staff', 'all'];

    protected $fillable = [
        'clinic_id',
        'key',
        'name',
        'audience',
        'has_expiry',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'has_expiry' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(StaffDocumentRequirement::class);
    }
}
