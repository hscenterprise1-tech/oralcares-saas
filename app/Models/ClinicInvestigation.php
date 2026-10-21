<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A test or study on a clinic's own investigation list. It grows from what
 * the clinic's doctors order, so the next order starts from it. There is no
 * shared, platform-wide test list.
 */
class ClinicInvestigation extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'name',
        'code',
        'category',
        'lookup_key',
        'is_active',
        'use_count',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'use_count' => 'integer',
        ];
    }

    public static function lookupKey(string $name, string $category): string
    {
        return Str::limit($category.'|'.Str::of($name)->lower()->replaceMatches('/\s+/', ' ')->trim()->value(), 195, '');
    }
}
