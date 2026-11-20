<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\ClinicContext;
use App\Services\Patient\PatientDirectory;
use App\Services\Patient\PatientDirectoryAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The patient directory as JSON: keyset pages from `PatientDirectory`
 * (clinic-leading, branch-restricted, prefix search, batched columns), with
 * the same views and contact rule as the directory page.
 */
class PatientDirectoryController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected PatientDirectory $directory,
    ) {}

    public function index(Request $request, PatientDirectoryAudience $audience): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'specialty' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'integer'],
            'practitioner_id' => ['nullable', 'regex:/^(none|\d{1,18})$/'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'status' => ['nullable', 'string'],
            'treatment_status' => ['nullable', 'string', Rule::in(['ongoing', 'completed', 'not_started'])],
            'active_care' => ['nullable', 'boolean'],
            'patient_type' => ['nullable', 'string', Rule::in(['new', 'repeat'])],
            'age_min' => ['nullable', 'integer', 'min:0', 'max:130'],
            'age_max' => array_values(array_filter(['nullable', 'integer', 'min:0', 'max:130', $request->filled('age_min') ? 'gte:age_min' : null])),
            'registered_from' => ['nullable', 'date'],
            'registered_to' => array_values(array_filter(['nullable', 'date', $request->filled('registered_from') ? 'after_or_equal:registered_from' : null])),
            'last_visit_from' => ['nullable', 'date'],
            'last_visit_to' => array_values(array_filter(['nullable', 'date', $request->filled('last_visit_from') ? 'after_or_equal:last_visit_from' : null])),
            // Without a view, the whole directory the member may see (this endpoint's original contract).
            'view' => ['nullable', Rule::in([PatientDirectoryAudience::VIEW_MINE, PatientDirectoryAudience::VIEW_TODAY, PatientDirectoryAudience::VIEW_ALL])],
            'cursor' => ['nullable', 'string', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        unset($filters['status']);
        if (array_key_exists('active_care', $filters)) {
            $filters['active_care'] = (bool) $filters['active_care'];
        }
        $scope = $audience->filtersFor($audience->resolveView($filters['view'] ?? PatientDirectoryAudience::VIEW_ALL));
        unset($filters['view']);
        if (isset($scope['practitioner_id'])) {
            unset($filters['practitioner_id']);
        }

        $canSeeContact = $audience->canSeeContact($request->user());
        $canSeeBilling = $request->user()->hasClinicPermission(ClinicPermission::BillingView);

        return response()->json($this->directory->page(
            $this->clinicContext->clinic()->id,
            [...$filters, ...$scope],
            $filters['cursor'] ?? null,
            (int) ($filters['per_page'] ?? PatientDirectory::PER_PAGE),
            $canSeeContact,
            $canSeeBilling,
        ));
    }
}
