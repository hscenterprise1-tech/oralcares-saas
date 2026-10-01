<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether a document type is required, optional or not applicable for a
 * staff type, in one clinic: the checklist the owner controls.
 */
class StaffDocumentRequirement extends Model
{
    use ClinicScoped;

    public const REQUIRED = 'required';

    public const OPTIONAL = 'optional';

    public const NOT_APPLICABLE = 'not_applicable';

    public const LEVELS = [self::REQUIRED, self::OPTIONAL, self::NOT_APPLICABLE];

    protected $fillable = [
        'clinic_id',
        'staff_document_type_id',
        'staff_type',
        'requirement',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(StaffDocumentType::class, 'staff_document_type_id');
    }
}
