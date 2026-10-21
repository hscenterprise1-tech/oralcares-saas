<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A medicine in a clinic's own formulary. It grows from what the clinic's
 * doctors prescribe (each new medicine written is remembered, with the dose
 * it was written with), so the next prescription starts from it. There is no
 * shared, platform-wide drug list.
 */
class ClinicMedicine extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'name',
        'generic_name',
        'form',
        'strength',
        'lookup_key',
        'default_dosage',
        'default_frequency',
        'default_duration',
        'default_timing',
        'default_instructions',
        'is_active',
        'use_count',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'use_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /** One formulary entry per name + strength + form, however it was typed. */
    public static function lookupKey(string $name, ?string $strength, ?string $form): string
    {
        return Str::limit(collect([$name, $strength, $form])
            ->map(fn (?string $part) => Str::of((string) $part)->lower()->replaceMatches('/\s+/', ' ')->trim()->value())
            ->implode('|'), 250, '');
    }
}
