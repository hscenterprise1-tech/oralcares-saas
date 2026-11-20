<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\PatientTreatment;
use App\Services\ClinicContext;
use App\Services\Patient\Patient360\Patient360Access;
use App\Services\Patient\PatientBranchAccess;
use App\Services\Treatment\TreatmentAccess;
use App\Services\Treatment\TreatmentReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The clinic treatment board (T9): open (or one status of) treatments across
 * patients, keyset-paged. Limited to the member's branches, and a clinician
 * to the specialties they practise; amounts only for billing roles.
 */
class TreatmentBoardController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected TreatmentReadModel $readModel,
        protected TreatmentAccess $access,
        protected PatientBranchAccess $branchAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', PatientTreatment::PLANNED, PatientTreatment::IN_PROGRESS, PatientTreatment::PAUSED, PatientTreatment::COMPLETED, PatientTreatment::CANCELLED])],
            'specialty' => ['nullable', 'string', 'max:50'],
            'practitioner_id' => ['nullable', 'integer'],
            'cursor' => ['nullable', 'string', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();

        return response()->json($this->readModel->board(
            $this->clinicContext->clinic()->id,
            $this->branchAccess->branchIds(),
            $this->access->boardSpecialties($user),
            $filters,
            $filters['cursor'] ?? null,
            $user->hasClinicPermission(ClinicPermission::BillingView),
            (int) ($filters['per_page'] ?? 25),
            // A practitioner limited to their own fees sees their own treatments' agreed estimates only.
            app(Patient360Access::class)->ownFeesPractitionerId($user),
        ));
    }
}
