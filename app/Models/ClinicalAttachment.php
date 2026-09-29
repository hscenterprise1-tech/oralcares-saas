<?php

namespace App\Models;

use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ClinicalAttachment extends Model
{
    use OrganizationScoped;

    protected $fillable = [
        'organization_id',
        'attachable_type',
        'attachable_id',
        'file_path',
        'file_type',
        'title',
        'description',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
