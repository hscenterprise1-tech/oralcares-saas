<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeoLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoLocationController extends Controller
{
    public function __construct(
        protected GeoLocationService $geoService
    ) {}

    /**
     * Resolve pincode to geographic hierarchy.
     */
    public function pincode(string $pincode): JsonResponse
    {
        $result = $this->geoService->resolvePincode($pincode);

        if (! $result) {
            return response()->json([
                'error' => 'Pincode not found',
                'message' => "No geographic hierarchy matched for pincode: {$pincode}",
                'data' => null,
            ], 404);
        }

        return response()->json([
            'data' => $result,
            'meta' => [
                'resolved' => true,
                'pincode' => $result['pincode'],
            ],
        ]);
    }

    /**
     * List active countries.
     */
    public function countries(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $countries = $this->geoService->getCountries($search ? (string) $search : null);

        return response()->json([
            'data' => $countries->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'phone_code' => $c->phone_code,
                'currency_code' => $c->currency_code,
            ]),
            'meta' => [
                'count' => $countries->count(),
            ],
        ]);
    }

    /**
     * List active states/UTs.
     */
    public function states(Request $request): JsonResponse
    {
        $countryId = $request->has('country_id') ? (int) $request->query('country_id') : null;
        $search = $request->query('search');

        $states = $this->geoService->getStates($countryId, $search ? (string) $search : null);

        return response()->json([
            'data' => $states->map(fn ($s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'gst_code' => $s->gst_code,
                'type' => $s->type,
                'country_id' => $s->country_id,
            ]),
            'meta' => [
                'count' => $states->count(),
            ],
        ]);
    }

    /**
     * List active districts.
     */
    public function districts(Request $request): JsonResponse
    {
        $stateId = $request->has('state_id') ? (int) $request->query('state_id') : null;
        $search = $request->query('search');

        $districts = $this->geoService->getDistricts($stateId, $search ? (string) $search : null);

        return response()->json([
            'data' => $districts->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'state_id' => $d->state_id,
            ]),
            'meta' => [
                'count' => $districts->count(),
            ],
        ]);
    }

    /**
     * List active cities.
     */
    public function cities(Request $request): JsonResponse
    {
        $stateId = $request->has('state_id') ? (int) $request->query('state_id') : null;
        $districtId = $request->has('district_id') ? (int) $request->query('district_id') : null;
        $search = $request->query('search');

        $cities = $this->geoService->getCities($stateId, $districtId, $search ? (string) $search : null);

        return response()->json([
            'data' => $cities->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'tier' => $c->tier,
                'state_id' => $c->state_id,
                'district_id' => $c->district_id,
            ]),
            'meta' => [
                'count' => $cities->count(),
            ],
        ]);
    }

    /**
     * List active areas for a city.
     */
    public function areas(Request $request): JsonResponse
    {
        $cityId = (int) $request->query('city_id');

        if (! $cityId) {
            return response()->json([
                'error' => 'Validation error',
                'message' => 'city_id query parameter is required to list areas.',
                'data' => [],
            ], 422);
        }

        $search = $request->query('search');
        $areas = $this->geoService->getAreas($cityId, $search ? (string) $search : null);

        return response()->json([
            'data' => $areas->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
                'city_id' => $a->city_id,
            ]),
            'meta' => [
                'count' => $areas->count(),
            ],
        ]);
    }

    /**
     * List active pincodes.
     */
    public function pincodes(Request $request): JsonResponse
    {
        $areaId = $request->has('area_id') ? (int) $request->query('area_id') : null;
        $cityId = $request->has('city_id') ? (int) $request->query('city_id') : null;
        $search = $request->query('search');

        $pincodes = $this->geoService->getPincodes($areaId, $cityId, $search ? (string) $search : null);

        return response()->json([
            'data' => $pincodes->map(fn ($p) => [
                'id' => $p->id,
                'pincode' => $p->pincode,
                'city_id' => $p->city_id,
                'area_id' => $p->area_id,
                'latitude' => $p->latitude,
                'longitude' => $p->longitude,
            ]),
            'meta' => [
                'count' => $pincodes->count(),
            ],
        ]);
    }

    /**
     * Multi-tier location search.
     */
    public function search(Request $request): JsonResponse
    {
        $query = (string) ($request->query('q') ?? $request->query('query') ?? '');
        $filters = array_filter([
            'state_id' => $request->query('state_id'),
            'city_id' => $request->query('city_id'),
        ]);

        $results = $this->geoService->search($query, $filters);

        return response()->json([
            'data' => $results,
            'meta' => [
                'query' => $query,
                'total_matches' => count($results['cities']) + count($results['areas']) + count($results['pincodes']),
            ],
        ]);
    }

    /**
     * Validate location hierarchy combinations.
     */
    public function validateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',
            'district_id' => 'nullable|integer',
            'area_id' => 'nullable|integer',
            'pincode' => 'nullable|string|max:10',
        ]);

        $result = $this->geoService->validateHierarchy($validated);

        return response()->json([
            'data' => $result,
        ], $result['valid'] ? 200 : 422);
    }
}
