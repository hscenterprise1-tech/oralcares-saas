<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Concerns\AnswersDomainErrors;
use App\Http\Controllers\Controller;
use App\Models\ClinicSpecialty;
use App\Services\ClinicContext;
use App\Services\Patient\ClinicSpecialties;
use App\Support\Specialty\SpecialtyEntitlements;
use App\Support\Specialty\SpecialtyRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Specialties API (T6): which specialties the clinic practises. Changing
 * them is the owner's decision, in Owner Mode (route), and
 * `ClinicSpecialties::configure()` re-checks `specialty.configure`.
 * Deactivating never deletes: history keeps its specialty.
 */
class SpecialtyController extends Controller
{
    use AnswersDomainErrors;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicSpecialties $specialties,
        protected SpecialtyRegistry $registry,
        protected SpecialtyEntitlements $entitlements,
    ) {}

    public function index(): JsonResponse
    {
        $clinic = $this->clinicContext->clinic();
        $rows = $this->specialties->all($clinic)->keyBy('specialty_key');
        // Only what the plan sells is offered; a specialty it no longer sells is not shown.
        $offered = collect($this->registry->all())->filter(fn (array $spec, string $key) => $this->entitlements->allows($clinic, $key));

        return response()->json(['data' => $offered->map(fn (array $spec, string $key) => [
            'key' => $key,
            'label' => $spec['label'],
            'extensions' => $spec['extensions'] ?? [],
            'targets' => $spec['targets'] ?? [],
            'status' => $rows->get($key)?->status ?? 'available',
            'active' => $rows->get($key)?->status === ClinicSpecialty::ACTIVE,
            'activated_at' => $rows->get($key)?->activated_at?->toIso8601String(),
        ])->values()]);
    }

    public function update(Request $request): JsonResponse
    {
        $keys = $request->validate([
            'specialties' => ['required', 'array', 'min:1', 'max:30'],
            'specialties.*' => ['string', 'max:50'],
        ])['specialties'];

        return $this->attemptJson(fn () => ['data' => ['active' => $this->specialties->configure($this->clinicContext->clinic(), $keys, $request->user())]]);
    }
}
