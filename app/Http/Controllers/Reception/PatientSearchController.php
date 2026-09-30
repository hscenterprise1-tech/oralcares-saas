<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\PatientSearchRequest;
use App\Services\ClinicContext;
use App\Services\Patient\PatientSearchService;
use Illuminate\Http\JsonResponse;

class PatientSearchController extends Controller
{
    public function __construct(
        protected PatientSearchService $searchService,
        protected ClinicContext $clinicContext
    ) {}

    /**
     * Instant search endpoint for the reception search bar.
     *
     * GET /reception/search?q=John&search_by=name
     */
    public function __invoke(PatientSearchRequest $request): JsonResponse
    {
        $clinic = $this->clinicContext->clinic();

        $patients = $this->searchService->search(
            clinic: $clinic,
            query: $request->searchQuery(),
            searchBy: $request->searchBy()
        );

        return response()->json([
            'data' => $patients->map(fn ($patient) => [
                'id' => $patient->id,
                'uhid' => $patient->uhid,
                'full_name' => $patient->full_name,
                'phone' => $patient->phone,
                'gender' => $patient->gender,
                'date_of_birth' => $patient->date_of_birth?->format('d M Y'),
                'latest_case_number' => $patient->caseNumbers->first()?->case_number,
                'status' => $patient->status?->value ?? $patient->status,
            ]),
        ]);
    }
}
