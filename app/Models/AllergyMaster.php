<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The curated allergy catalogue a patient-registration form picks from.
 *
 * A reference table, not a constraint — see `HealthcareSpecialtyMaster` for
 * the identical reasoning. `patient_medical_histories.allergies` stores
 * plain names, not `allergy_masters.key`, so a name typed through the
 * picker's "add custom" escape hatch is stored exactly the same way as one
 * chosen from the list.
 */
class AllergyMaster extends Model
{
    public const CATEGORIES = [
        'medication' => 'Medication',
        'material' => 'Material',
        'food' => 'Food',
    ];

    protected $fillable = [
        'key',
        'name',
        'category',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The whole catalogue, shaped for a picker and cached for the request.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function catalogue(): Collection
    {
        static $cached = null;

        return $cached ??= self::query()
            ->active()
            ->orderBy('category')
            ->orderBy('position')
            ->get()
            ->map(fn (self $allergy) => [
                'key' => $allergy->key,
                'name' => $allergy->name,
                'category' => $allergy->category,
                'category_label' => self::CATEGORIES[$allergy->category] ?? $allergy->category,
            ])
            ->values();
    }
}
