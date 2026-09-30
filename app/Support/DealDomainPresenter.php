<?php

namespace App\Support;

use App\FacilityType;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Lead;
use App\Models\LeadBranch;
use Illuminate\Support\Collection;

/**
 * How a healthcare prospect describes itself on screen.
 *
 * One place, because the Deals list and the Deal 360 page both render these and
 * a card that says "Polyclinic — 6 doctors" while the page it opens says
 * something else is the exact divergence this module has spent the rebuild
 * pulling apart.
 *
 * Three questions, answered here and nowhere else:
 *
 *   what do they practise   → specialty badges, grouped and coloured by category
 *   what kind of place      → the facility archetype, sized by the figure that
 *                             actually describes it
 *   how big is the estate   → the branch footprint
 */
class DealDomainPresenter
{
    /**
     * Specialty badges, resolved through the catalogue.
     *
     * A key with no catalogue row is still rendered, from its own text under a
     * neutral tone — a specialty somebody typed before it was catalogued is
     * still true about that practice, and showing fewer badges than the record
     * holds is worse than an uncoloured one.
     *
     * @return list<array<string, mixed>>
     */
    public static function specialties(Lead $lead): array
    {
        return HealthcareSpecialtyMaster::badgesFor($lead->healthcare_specialties);
    }

    /**
     * The archetype, sized by the figure that fits it.
     *
     * "Hospital — 85 beds", "Polyclinic — 8 doctors", "Multi-branch — 4 sites".
     * Which number leads is decided by the facility type, because the wrong one
     * is actively misleading: reporting "0 beds" against a physiotherapy chain
     * implies somebody asked and the answer was none, when the truth is that
     * beds are not how that business is measured.
     *
     * Multi-branch wins over everything. A four-site group is a different sale
     * from a single site whatever happens inside each one, and it is the first
     * thing a rep needs to know.
     *
     * @return array<string, mixed>|null
     */
    public static function facility(Lead $lead): ?array
    {
        $type = $lead->facility_type instanceof FacilityType
            ? $lead->facility_type
            : FacilityType::tryFrom((string) $lead->facility_type);

        $branches = $lead->relationLoaded('branches') ? $lead->branches : collect();
        $siteCount = max((int) $lead->branch_count, $branches->count());

        if (! $type && $siteCount <= 1) {
            return null;
        }

        $capacity = self::capacity($branches);

        return [
            'type' => $type?->value,
            'label' => $type?->label() ?? 'Healthcare facility',
            'site_count' => $siteCount,
            'is_multi_site' => $siteCount > 1,
            'doctors' => $capacity['doctors'],
            'beds' => $capacity['beds'],
            'theatres' => $capacity['theatres'],
            'stations' => $capacity['stations'],
            /*
             * The one-line archetype the card renders. Composed server-side so
             * the list and the detail page cannot word it differently.
             */
            'archetype' => self::archetype($type, $siteCount, $capacity),
        ];
    }

    /**
     * Summed capacity across every branch.
     *
     * Null rather than zero when nothing was recorded, all the way through —
     * "we did not ask" and "they have none" are different answers and only one
     * of them should put a number on screen.
     *
     * @param  Collection<int, LeadBranch>  $branches
     * @return array{doctors:?int, beds:?int, theatres:?int, stations:?int}
     */
    private static function capacity(Collection $branches): array
    {
        $sum = function (string $column) use ($branches): ?int {
            $values = $branches->pluck($column)->filter(fn ($v) => $v !== null);

            return $values->isEmpty() ? null : (int) $values->sum();
        };

        return [
            'doctors' => $sum('doctor_count'),
            'beds' => $sum('bed_count'),
            'theatres' => $sum('operation_theatre_count'),
            'stations' => $sum('treatment_station_count'),
        ];
    }

    /**
     * @param  array{doctors:?int, beds:?int, theatres:?int, stations:?int}  $capacity
     */
    private static function archetype(?FacilityType $type, int $siteCount, array $capacity): string
    {
        // A group is a group first. Everything else is what happens inside it.
        if ($siteCount > 1) {
            return "Multi-branch — {$siteCount} sites";
        }

        $label = $type?->label() ?? 'Healthcare facility';

        $metric = match ($type?->primaryCapacityMetric()) {
            'beds' => $capacity['beds'] ? "{$capacity['beds']} beds" : null,
            'stations' => $capacity['stations'] ? "{$capacity['stations']} stations" : null,
            'doctors' => $capacity['doctors'] ? self::plural($capacity['doctors'], 'doctor') : null,
            default => null,
        };

        /*
         * Fall back to doctors when the type's own metric was never recorded.
         * A hospital with no bed count still has doctors, and "Hospital" alone
         * tells a rep nothing they could not see from the name.
         */
        $metric ??= $capacity['doctors'] ? self::plural($capacity['doctors'], 'doctor') : null;

        return $metric ? "{$label} — {$metric}" : $label;
    }

    private static function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }

    /**
     * The branch list, for the Deal 360 footprint panel.
     *
     * @return list<array<string, mixed>>
     */
    public static function branches(Lead $lead): array
    {
        if (! $lead->relationLoaded('branches')) {
            return [];
        }

        return $lead->branches
            ->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_main' => (bool) $branch->is_main,
                'city' => $branch->city,
                'area' => $branch->area,
                'postal_code' => $branch->postal_code,
                'location' => collect([$branch->area, $branch->city])->filter()->join(', ') ?: null,
                'doctor_count' => $branch->doctor_count,
                'bed_count' => $branch->bed_count,
                'operation_theatre_count' => $branch->operation_theatre_count,
                'treatment_station_count' => $branch->treatment_station_count,
                'contact_name' => $branch->contact_name,
                'phone' => $branch->phone,
            ])
            ->values()
            ->all();
    }
}
