<?php

namespace App\Services;

use App\Models\GeoArea;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoDistrict;
use App\Models\GeoPincode;
use App\Models\GeoState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class GeoLocationService
{
    /**
     * Cache TTL in seconds (24 hours).
     */
    protected int $cacheTtl = 86400;

    /**
     * Resolve 6-digit Pincode to complete geographic hierarchy.
     *
     * @return array<string, mixed>|null
     */
    public function resolvePincode(string $pincode): ?array
    {
        $normalizedPincode = preg_replace('/[^0-9]/', '', trim($pincode));

        if (strlen($normalizedPincode) !== 6) {
            return null;
        }

        $cacheKey = "geo:pincode:{$normalizedPincode}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($normalizedPincode) {
            $records = GeoPincode::where('pincode', $normalizedPincode)
                ->where('is_active', true)
                ->with(['area', 'city', 'district', 'state.country'])
                ->get();

            if ($records->isEmpty()) {
                return null;
            }

            $primary = $records->first();
            $state = $primary->state;
            $country = $state?->country;
            $district = $primary->district;
            $city = $primary->city;

            $areas = $records->filter(fn ($r) => $r->area !== null)
                ->map(fn ($r) => [
                    'id' => $r->area->id,
                    'name' => $r->area->name,
                    'slug' => $r->area->slug,
                    'latitude' => $r->latitude,
                    'longitude' => $r->longitude,
                ])
                ->unique('id')
                ->values()
                ->toArray();

            return [
                'pincode' => $normalizedPincode,
                'country' => $country ? [
                    'id' => $country->id,
                    'code' => $country->code,
                    'name' => $country->name,
                    'phone_code' => $country->phone_code,
                ] : null,
                'state' => $state ? [
                    'id' => $state->id,
                    'code' => $state->code,
                    'name' => $state->name,
                    'gst_code' => $state->gst_code,
                    'type' => $state->type,
                ] : null,
                'district' => $district ? [
                    'id' => $district->id,
                    'name' => $district->name,
                ] : null,
                'city' => $city ? [
                    'id' => $city->id,
                    'name' => $city->name,
                    'slug' => $city->slug,
                    'tier' => $city->tier,
                ] : null,
                'areas' => $areas,
                'latitude' => $primary->latitude,
                'longitude' => $primary->longitude,
            ];
        });
    }

    /**
     * Get active countries with optional search.
     *
     * @return Collection<int, GeoCountry>
     */
    public function getCountries(?string $search = null): Collection
    {
        $search = $search ? trim($search) : null;

        if ($search) {
            return GeoCountry::active()
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "{$search}%")
                        ->orWhere('code', 'like', "{$search}%");
                })
                ->orderBy('name')
                ->get();
        }

        return Cache::remember('geo:countries', $this->cacheTtl, function () {
            return GeoCountry::active()->orderBy('name')->get();
        });
    }

    /**
     * Get active states/UTs for a country with optional search.
     *
     * @return Collection<int, GeoState>
     */
    public function getStates(?int $countryId = null, ?string $search = null): Collection
    {
        $search = $search ? trim($search) : null;

        // Default to India if no countryId provided
        $countryId = $countryId ?? GeoCountry::where('code', 'IN')->value('id') ?? 1;

        if ($search) {
            return GeoState::active()
                ->where('country_id', $countryId)
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "{$search}%")
                        ->orWhere('code', 'like', "{$search}%");
                })
                ->orderBy('name')
                ->get();
        }

        $cacheKey = "geo:states:{$countryId}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($countryId) {
            return GeoState::active()
                ->where('country_id', $countryId)
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Get active districts for a state with optional search.
     *
     * @return Collection<int, GeoDistrict>
     */
    public function getDistricts(?int $stateId = null, ?string $search = null): Collection
    {
        $search = $search ? trim($search) : null;

        $query = GeoDistrict::active();

        if ($stateId) {
            $query->where('state_id', $stateId);
        }

        if ($search) {
            return $query->where('name', 'like', "{$search}%")
                ->orderBy('name')
                ->get();
        }

        $cacheKey = 'geo:districts:'.($stateId ?? 'all');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($stateId) {
            $q = GeoDistrict::active();
            if ($stateId) {
                $q->where('state_id', $stateId);
            }

            return $q->orderBy('name')->get();
        });
    }

    /**
     * Get active cities for a state and/or district with optional search.
     *
     * @return Collection<int, GeoCity>
     */
    public function getCities(?int $stateId = null, ?int $districtId = null, ?string $search = null): Collection
    {
        $search = $search ? trim($search) : null;

        $query = GeoCity::active();

        if ($districtId) {
            $query->where('district_id', $districtId);
        } elseif ($stateId) {
            $query->where('state_id', $stateId);
        }

        if ($search) {
            return $query->where('name', 'like', "{$search}%")
                ->orderBy('name')
                ->get();
        }

        $cacheKey = 'geo:cities:'.($stateId ?? 'all').':'.($districtId ?? 'all');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($stateId, $districtId) {
            $q = GeoCity::active();
            if ($districtId) {
                $q->where('district_id', $districtId);
            } elseif ($stateId) {
                $q->where('state_id', $stateId);
            }

            return $q->orderBy('name')->get();
        });
    }

    /**
     * Get active areas for a city with optional search.
     *
     * @return Collection<int, GeoArea>
     */
    public function getAreas(int $cityId, ?string $search = null): Collection
    {
        $search = $search ? trim($search) : null;

        if ($search) {
            return GeoArea::active()
                ->where('city_id', $cityId)
                ->where('name', 'like', "{$search}%")
                ->orderBy('name')
                ->get();
        }

        $cacheKey = "geo:areas:{$cityId}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($cityId) {
            return GeoArea::active()
                ->where('city_id', $cityId)
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Get active pincodes for an area, city, or state.
     *
     * @return Collection<int, GeoPincode>
     */
    public function getPincodes(?int $areaId = null, ?int $cityId = null, ?string $search = null): Collection
    {
        $search = $search ? trim($search) : null;

        $query = GeoPincode::active();

        if ($areaId) {
            $query->where('area_id', $areaId);
        } elseif ($cityId) {
            $query->where('city_id', $cityId);
        }

        if ($search) {
            return $query->where('pincode', 'like', "{$search}%")
                ->distinct('pincode')
                ->limit(20)
                ->get();
        }

        $cacheKey = 'geo:pincodes:'.($cityId ?? 'all').':'.($areaId ?? 'all');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($areaId, $cityId) {
            $q = GeoPincode::active();
            if ($areaId) {
                $q->where('area_id', $areaId);
            } elseif ($cityId) {
                $q->where('city_id', $cityId);
            }

            return $q->distinct('pincode')->limit(100)->get();
        });
    }

    /**
     * Multi-tier location search across cities, areas, and pincodes.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function search(string $query, array $filters = [], int $limit = 15): array
    {
        $term = trim($query);

        if (strlen($term) < 2) {
            return [
                'cities' => [],
                'areas' => [],
                'pincodes' => [],
            ];
        }

        // 1. Pincodes search if digits
        $pincodes = [];
        if (preg_match('/^[0-9]+$/', $term)) {
            $pincodes = GeoPincode::active()
                ->where('pincode', 'like', "{$term}%")
                ->with(['city', 'state'])
                ->limit($limit)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'pincode' => $p->pincode,
                    'city_name' => $p->city?->name,
                    'state_name' => $p->state?->name,
                ])
                ->unique('pincode')
                ->values()
                ->toArray();
        }

        // 2. Cities search
        $citiesQuery = GeoCity::active()
            ->where('name', 'like', "{$term}%")
            ->with('state');

        if (! empty($filters['state_id'])) {
            $citiesQuery->where('state_id', $filters['state_id']);
        }

        $cities = $citiesQuery->limit($limit)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'state_id' => $c->state_id,
                'state_name' => $c->state?->name,
                'tier' => $c->tier,
            ])
            ->toArray();

        // 3. Areas search
        $areasQuery = GeoArea::active()
            ->where('name', 'like', "{$term}%")
            ->with(['city.state']);

        if (! empty($filters['city_id'])) {
            $areasQuery->where('city_id', $filters['city_id']);
        }

        $areas = $areasQuery->limit($limit)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'city_id' => $a->city_id,
                'city_name' => $a->city?->name,
                'state_name' => $a->city?->state?->name,
            ])
            ->toArray();

        return [
            'cities' => $cities,
            'areas' => $areas,
            'pincodes' => $pincodes,
        ];
    }

    /**
     * Validate relational consistency of a location payload.
     *
     * @param  array<string, mixed>  $location
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateHierarchy(array $location): array
    {
        $errors = [];

        $stateId = $location['state_id'] ?? null;
        $cityId = $location['city_id'] ?? null;
        $districtId = $location['district_id'] ?? null;
        $areaId = $location['area_id'] ?? null;
        $pincode = ! empty($location['pincode']) ? trim((string) $location['pincode']) : null;

        // Pincode format validation
        if ($pincode !== null && ! preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            $errors[] = 'Pincode must be exactly 6 numeric digits starting with 1-9.';
        }

        // City + State relation
        if ($cityId && $stateId) {
            $cityBelongs = GeoCity::where('id', $cityId)
                ->where('state_id', $stateId)
                ->exists();

            if (! $cityBelongs) {
                $cityName = GeoCity::find($cityId)?->name ?? "City #{$cityId}";
                $stateName = GeoState::find($stateId)?->name ?? "State #{$stateId}";
                $errors[] = "{$cityName} does not belong to {$stateName}.";
            }
        }

        // District + State relation
        if ($districtId && $stateId) {
            $districtBelongs = GeoDistrict::where('id', $districtId)
                ->where('state_id', $stateId)
                ->exists();

            if (! $districtBelongs) {
                $districtName = GeoDistrict::find($districtId)?->name ?? "District #{$districtId}";
                $stateName = GeoState::find($stateId)?->name ?? "State #{$stateId}";
                $errors[] = "{$districtName} does not belong to {$stateName}.";
            }
        }

        // Area + City relation
        if ($areaId && $cityId) {
            $areaBelongs = GeoArea::where('id', $areaId)
                ->where('city_id', $cityId)
                ->exists();

            if (! $areaBelongs) {
                $areaName = GeoArea::find($areaId)?->name ?? "Area #{$areaId}";
                $cityName = GeoCity::find($cityId)?->name ?? "City #{$cityId}";
                $errors[] = "{$areaName} does not belong to {$cityName}.";
            }
        }

        // Pincode + City relation
        if ($pincode && $cityId && empty($errors)) {
            $pincodeBelongs = GeoPincode::where('pincode', $pincode)
                ->where('city_id', $cityId)
                ->exists();

            if (! $pincodeBelongs) {
                $cityName = GeoCity::find($cityId)?->name ?? "City #{$cityId}";
                $errors[] = "Pincode {$pincode} is not registered under {$cityName}.";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
