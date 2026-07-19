<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponseService
{
    /**
     * Return a standardized success response.
     */
    public function success(mixed $data = null, ?array $meta = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => $meta,
            'error' => null,
        ], $status);
    }

    /**
     * Return a standardized paginated response.
     */
    public function paginated(LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        $collection = $resourceClass::collection($paginator->items());

        return response()->json([
            'success' => true,
            'data' => $collection,
            'meta' => [
                'pagination' => [
                    'total' => $paginator->total(),
                    'count' => $paginator->count(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'total_pages' => $paginator->lastPage(),
                ],
            ],
            'error' => null,
        ]);
    }

    /**
     * Return a standardized error response.
     */
    public function error(string $message, int $status = 400, ?array $details = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'meta' => null,
            'error' => [
                'message' => $message,
                'status_code' => $status,
                'details' => $details,
            ],
        ], $status);
    }
}
