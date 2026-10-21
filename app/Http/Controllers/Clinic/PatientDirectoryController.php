<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\PatientStatus;
use App\Services\ClinicContext;
use App\Services\Patient\PatientDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The patient directory as JSON: keyset pages from `PatientDirectory`
 * (clinic-leading, branch-restricted, prefix search, batched columns).
 */
class PatientDirectoryController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected PatientDirectory $directory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(array_map(fn (PatientStatus $s) => $s->value, PatientStatus::cases()))],
            'specialty' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'integer'],
            'practitioner_id' => ['nullable', 'integer'],
            'active_care' => ['nullable', 'boolean'],
            'registered_from' => ['nullable', 'date'],
            'registered_to' => ['nullable', 'date', 'after_or_equal:registered_from'],
            'cursor' => ['nullable', 'string', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (array_key_exists('active_care', $filters)) {
            $filters['active_care'] = (bool) $filters['active_care'];
        }

        return response()->json($this->directory->page(
            $this->clinicContext->clinic()->id,
            $filters,
            $filters['cursor'] ?? null,
            (int) ($filters['per_page'] ?? PatientDirectory::PER_PAGE),
        ));
    }
}
