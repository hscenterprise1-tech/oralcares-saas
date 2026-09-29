<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The specialty catalogue.
 *
 * A reference table, not a constraint. `leads.healthcare_specialties` stays an
 * unconstrained JSON array of keys — a rep on a call with a practice that does
 * something nobody has catalogued yet must be able to write it down, and a
 * foreign key would have stopped them. What this table gives is the things a
 * free string cannot: a display name, a category to group and colour by, an
 * order a practitioner would recognise, and the ability to retire a specialty
 * without rewriting the rows that reference it.
 *
 * Eight categories, spanning what the product is actually sold into: primary
 * care, maternal and child, surgical, super-specialty, dental, rehabilitation,
 * AYUSH and diagnostics.
 */
class HealthcareSpecialtyMaster extends Model
{
    /**
     * Category keys, and the colour role each maps to on screen.
     *
     * Held here rather than in the page so the palette is decided once. The
     * values are role names the design system resolves, not hex — a category
     * that renders coral in light mode must not be pinned to a colour that
     * disappears in dark.
     *
     * @var array<string, array{label: string, tone: string}>
     */
    public const CATEGORIES = [
        'general' => ['label' => 'General', 'tone' => 'blue'],
        'maternal_child' => ['label' => 'Maternal & child', 'tone' => 'red'],
        'surgical_specialty' => ['label' => 'Surgical', 'tone' => 'info'],
        'super_specialty' => ['label' => 'Super-specialty', 'tone' => 'danger'],
        'dental' => ['label' => 'Dental', 'tone' => 'green'],
        'rehabilitation' => ['label' => 'Rehabilitation', 'tone' => 'yellow'],
        'ayush' => ['label' => 'AYUSH', 'tone' => 'good'],
        'diagnostics' => ['label' => 'Diagnostics', 'tone' => 'neutral'],
    ];

    protected $fillable = [
        'key',
        'name',
        'category',
        'category_label',
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

    public function tone(): string
    {
        return self::CATEGORIES[$this->category]['tone'] ?? 'neutral';
    }

    /**
     * The whole catalogue, shaped for a picker and cached for the request.
     *
     * Every CRM payload that renders a specialty badge needs this, and a deals
     * page renders twenty-five rows' worth — reading the table once per request
     * rather than once per row is the difference between one query and one
     * per badge.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public static function catalogue(): Collection
    {
        static $cached = null;

        return $cached ??= self::query()
            ->active()
            ->orderBy('category')
            ->orderBy('position')
            ->get()
            ->mapWithKeys(fn (self $specialty) => [
                $specialty->key => [
                    'key' => $specialty->key,
                    'name' => $specialty->name,
                    'category' => $specialty->category,
                    'category_label' => $specialty->category_label,
                    'tone' => $specialty->tone(),
                ],
            ]);
    }

    /**
     * Resolve stored keys into badges the page can render.
     *
     * A key with no catalogue entry is *not* dropped. It is rendered from its
     * own text under the neutral tone, because a specialty somebody typed
     * before it was catalogued is still true about that practice — silently
     * showing fewer badges than the record holds is worse than an uncoloured
     * one.
     *
     * @param  array<int, string>|null  $keys
     * @return list<array<string, mixed>>
     */
    public static function badgesFor(?array $keys): array
    {
        if (! $keys) {
            return [];
        }

        $catalogue = self::catalogue();

        return collect($keys)
            ->filter(fn ($key) => filled($key))
            ->unique()
            ->map(fn (string $key) => $catalogue->get($key) ?? [
                'key' => $key,
                'name' => Str::headline($key),
                'category' => null,
                'category_label' => null,
                'tone' => 'neutral',
            ])
            ->values()
            ->all();
    }
}
