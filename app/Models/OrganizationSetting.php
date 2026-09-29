<?php

namespace App\Models;

use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSetting extends Model
{
    use OrganizationScoped;

    protected $fillable = [
        'organization_id',
        'group',
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
