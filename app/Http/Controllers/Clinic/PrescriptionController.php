<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\Consultation\PrescriptionLineRequest;
use App\Models\ClinicalEncounter;
use App\Models\ClinicMedicine;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Services\ClinicalPrescriptionService;
use App\Services\ClinicContext;
use App\Services\Security\SecurityAudit;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Prescription Studio: drafting, finalising, cancelling and printing the
 * prescriptions of a consultation, and searching the clinic's formulary.
 *
 * Routes sit behind `clinic_permission:clinical.view` (writes also
 * `prescription.manage`); each action is authorised on the record by
 * `PrescriptionPolicy`. Every write goes through `ClinicalPrescriptionService`.
 */
class PrescriptionController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicalPrescriptionService $prescriptions,
    ) {}

    public function store(ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('create', [Prescription::class, $encounter]);

        return $this->attempt(function () use ($encounter) {
            $this->prescriptions->draftFor($encounter, $this->clinicContext->currentEmployee());

            return back();
        });
    }

    public function update(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->authorize('update', $prescription);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(function () use ($prescription, $validated) {
            $this->prescriptions->updateNotes($prescription, $validated['notes'] ?? null);

            return back();
        });
    }

    public function storeItem(PrescriptionLineRequest $request, Prescription $prescription): RedirectResponse
    {
        $this->authorize('update', $prescription);

        return $this->attempt(function () use ($request, $prescription) {
            $line = $this->prescriptions->addItem($prescription, $request->validated());

            return back()->with('success', "{$line->drug_name} added.");
        });
    }

    public function updateItem(PrescriptionLineRequest $request, Prescription $prescription, PrescriptionItem $item): RedirectResponse
    {
        $this->authorize('update', $prescription);

        return $this->attempt(function () use ($request, $prescription, $item) {
            $this->prescriptions->updateItem($prescription, $item, $request->validated());

            return back();
        });
    }

    public function destroyItem(Prescription $prescription, PrescriptionItem $item): RedirectResponse
    {
        $this->authorize('update', $prescription);

        return $this->attempt(function () use ($prescription, $item) {
            $this->prescriptions->removeItem($prescription, $item);

            return back();
        });
    }

    /**
     * The prescribing clinician reviews the patient's documented allergies
     * for this draft. The fingerprint must be the current allergy record's
     * (`PrescriptionAllergyCheck`); the review is stored with who and when,
     * and signing requires it while allergies are documented.
     */
    public function reviewAllergies(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->authorize('update', $prescription);
        $validated = $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);

        return $this->attempt(function () use ($request, $prescription, $validated) {
            $this->prescriptions->reviewAllergies($prescription, $request->user(), $validated['fingerprint']);

            return back()->with('success', 'Allergies reviewed.');
        });
    }

    /**
     * Finalise, saving the advice sent with it first (the screen sends the
     * advice box as it stands, saved or not).
     */
    public function finalize(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->authorize('update', $prescription);
        $validated = $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return $this->attempt(function () use ($prescription, $validated) {
            if (array_key_exists('notes', $validated)) {
                $this->prescriptions->updateNotes($prescription, $validated['notes']);
            }
            $final = $this->prescriptions->finalize($prescription, $this->clinicContext->currentEmployee());

            return back()->with('success', "Prescription {$final->prescription_number} finalised.");
        });
    }

    public function cancel(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->authorize('cancel', $prescription);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why the prescription is being cancelled.']);

        return $this->attempt(function () use ($prescription, $validated) {
            $cancelled = $this->prescriptions->cancel($prescription, $this->clinicContext->currentEmployee(), $validated['reason']);

            return back()->with('success', "Prescription {$cancelled->prescription_number} cancelled.");
        });
    }

    /**
     * The printable prescription (the browser prints it); `?download=1`
     * returns the same page as a PDF.
     */
    public function print(Request $request, Prescription $prescription): Response
    {
        $this->authorize('print', $prescription);

        $prescription->load([
            'items',
            'patient' => fn ($query) => $query->withoutGlobalScopes(),
            'clinic',
            'encounter' => fn ($query) => $query->withoutGlobalScopes()->with('branch', 'diagnoses'),
            'prescribingDoctor.practitionerProfile',
            'canceller:id,name',
        ]);

        app(SecurityAudit::class)->record($request->boolean('download') ? 'prescription.downloaded' : 'prescription.printed', $prescription, [
            'prescription_number' => $prescription->prescription_number,
        ]);

        $data = [
            'rx' => $prescription,
            'clinic' => $prescription->clinic,
            'branch' => $prescription->encounter?->branch,
            'patient' => $prescription->patient,
            'doctor' => $prescription->prescribingDoctor,
            'practitioner' => $prescription->prescribingDoctor?->practitionerProfile,
            'diagnoses' => $prescription->encounter?->diagnoses ?? collect(),
            'followUpOn' => $prescription->encounter?->follow_up_on,
            'frequencies' => config('clinic_prescriptions.frequencies'),
            'timings' => config('clinic_prescriptions.timings'),
            'routes' => config('clinic_prescriptions.routes'),
            'forDownload' => $request->boolean('download'),
        ];

        if ($request->boolean('download')) {
            return Pdf::loadView('clinic.prescription_printable', $data)
                ->setPaper('a5')
                ->download(Str::slug($prescription->prescription_number ?: "prescription-{$prescription->id}").'.pdf');
        }

        return response()->view('clinic.prescription_printable', $data);
    }

    /**
     * The clinic's formulary, most-used first, for the medicine picker.
     */
    public function medicines(Request $request): JsonResponse
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $term = Str::limit(trim((string) $request->query('q', '')), 60, '');

        $medicines = ClinicMedicine::query()
            ->where('clinic_id', $clinic->id)
            ->where('is_active', true)
            ->when($term !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'like', $term.'%')
                ->orWhere('generic_name', 'like', $term.'%')
                ->orWhere('name', 'like', '% '.$term.'%')))
            ->orderByDesc('use_count')
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'generic_name', 'form', 'strength', 'default_dosage', 'default_frequency', 'default_duration', 'default_timing', 'default_instructions']);

        return response()->json(['data' => $medicines]);
    }

    /**
     * @param  Closure(): RedirectResponse  $action
     */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['prescription' => $e->getMessage()]);
        }
    }
}
