<?php

namespace App\Shared\Search\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Search\Requests\SearchQueryRequest;
use App\Shared\Search\Services\SearchEngineService;
use Illuminate\Http\JsonResponse;

class GlobalSearchController extends Controller
{
    public function __construct(
        protected SearchEngineService $searchEngine
    ) {}

    /**
     * API Endpoint for Instant Search (Dropdown)
     */
    public function search(SearchQueryRequest $request): JsonResponse
    {
        $query = $request->validated('q', '');

        $filters = array_filter([
            'module' => $request->validated('module'),
            'status' => $request->validated('status'),
            'priority' => $request->validated('priority'),
        ]);

        $perPage = $request->validated('per_page', 5); // Default 5 for instant search

        $results = $this->searchEngine->search(
            $request->user(),
            $query,
            $filters,
            $perPage
        );

        // Group results by module for the UI dropdown
        $grouped = collect($results->items())->groupBy(function ($item) {
            return $item->module->value;
        });

        return response()->json([
            'data' => $grouped,
            'meta' => [
                'total' => $results->total(),
                'has_more' => $results->hasMorePages(),
            ],
        ]);
    }
}
