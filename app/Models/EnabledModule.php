<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnabledModule extends Model
{
    protected $fillable = [
        'license_id',
        'module_name',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
