<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Support\ClinicSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The top bar's search (`ClinicSearch`): every group is decided here, on the
 * server, from this request's clinic, branch and permissions, so what the
 * browser receives is already only what this user may find and open.
 */
class ClinicSearchController extends Controller
{
    public function index(Request $request, ClinicSearch $search): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $routeParameters = array_filter(['clinic' => $request->route('clinic')], fn ($value) => is_string($value));

        return response()->json([
            'query' => trim((string) ($validated['q'] ?? '')),
            'groups' => $search->search((string) ($validated['q'] ?? ''), $request->user(), $routeParameters),
        ]);
    }
}
