<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadImportRow extends Model
{
    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_INVALID = 'invalid';

    protected $fillable = [
        'import_id',
        'lead_id',
        'row_number',
        'outcome',
        'company_name',
        'contact_name',
        'phone',
        'email',
        'reason',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(LeadImport::class, 'import_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
