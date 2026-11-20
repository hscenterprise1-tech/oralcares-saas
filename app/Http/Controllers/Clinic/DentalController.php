<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\Consultation\ChartToothRequest;
use App\Http\Requests\Clinic\Consultation\ProposeTreatmentPlanRequest;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientToothStatusHistory;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Services\ClinicContext;
use App\Services\Dental\DentalRecord;
use App\Services\Dental\PeriodontalRecord;
use App\Services\Patient\Patient360\Patient360SectionPolicy;
use App\Services\Security\SecurityAudit;
use App\TreatmentStatus;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The dental extension inside a consultation: charting teeth, a tooth's
 * history, and treatment plans. Every action needs the consultation's
 * branch to practise a specialty with the `odontogram` extension; writes
 * also need the consultation to be open and the user its treating doctor.
 */
class DentalController extends Controller
{
    private const EXTENSION = 'odontogram';

    public function __construct(
        protected ClinicContext $clinicContext,
        protected DentalRecord $dental,
    ) {}

    public function chart(ChartToothRequest $request, ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);

        return $this->attempt(function () use ($request, $encounter) {
            $finding = $this->dental->recordFinding(
                $encounter,
                $request->integer('tooth'),
                $request->validated('condition'),
                $request->validated('surfaces') ?? [],
                $request->validated('note'),
                $this->doctor(),
            );

            return back()->with('success', "Tooth {$finding->tooth_number} charted.");
        });
    }

    public function toothHistory(ClinicalEncounter $encounter, int $tooth): JsonResponse
    {
        $this->authorize('viewExtension', [$encounter, self::EXTENSION]);
        $patient = Patient::query()->withoutGlobalScopes()->findOrFail($encounter->patient_id);

        return response()->json(['data' => $this->dental->toothHistory($patient, $tooth)->map(fn (PatientToothStatusHistory $entry) => [
            'id' => $entry->id,
            'kind' => $entry->kind,
            'status' => $entry->status,
            'previous_status' => $entry->previous_status,
            'surfaces' => $entry->surfaces ?? [],
            'notes' => $entry->notes,
            'doctor' => $entry->doctor?->name,
            'at' => $entry->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function proposePlan(ProposeTreatmentPlanRequest $request, ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);

        return $this->attempt(function () use ($request, $encounter) {
            $this->dental->proposePlan($encounter, (string) $request->validated('title'), $request->validated('items'), $request->validated('notes'), $this->doctor());

            return back()->with('success', 'Treatment plan proposed.');
        });
    }

    public function acceptPlan(ClinicalEncounter $encounter, TreatmentPlan $plan): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);
        $this->assertSamePatient($encounter, $plan);

        return $this->attempt(function () use ($plan) {
            $this->dental->acceptPlan($plan);

            return back()->with('success', 'Treatment plan accepted.');
        });
    }

    public function completeItem(Request $request, ClinicalEncounter $encounter, TreatmentPlan $plan, TreatmentRecord $item): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);
        $this->assertSamePatient($encounter, $plan);
        $validated = $request->validate([
            'resulting_condition' => ['nullable', Rule::in(array_keys(config('clinic_dental.conditions')))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(function () use ($encounter, $plan, $item, $validated) {
            $this->dental->completeItem($plan, $item, $encounter, $this->doctor(), $validated['resulting_condition'] ?? null, $validated['note'] ?? null);

            return back()->with('success', 'Treatment recorded in this consultation.');
        });
    }

    public function cancelItem(ClinicalEncounter $encounter, TreatmentPlan $plan, TreatmentRecord $item): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);
        $this->assertSamePatient($encounter, $plan);

        return $this->attempt(function () use ($plan, $item) {
            $this->dental->cancelItem($plan, $item);

            return back()->with('success', 'Treatment removed from the plan.');
        });
    }

    /**
     * Record (or, while the consultation is open, re-record) its
     * periodontal exam.
     */
    public function periodontal(Request $request, ClinicalEncounter $encounter, PeriodontalRecord $periodontal): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);
        $vocabulary = config('clinic_dental.periodontal');
        $validated = $request->validate([
            'teeth' => ['required', 'array', 'min:1', 'max:52'],
            'teeth.*.tooth' => ['required', 'integer', 'between:11,85'],
            'teeth.*.missing' => ['boolean'],
            'teeth.*.probing_depths' => ['array', 'max:6'],
            'teeth.*.probing_depths.*' => ['nullable', 'integer', 'between:0,15'],
            'teeth.*.gingival_margins' => ['array', 'max:6'],
            'teeth.*.gingival_margins.*' => ['nullable', 'integer', 'between:-10,15'],
            'teeth.*.bleeding' => ['array', 'max:6'],
            'teeth.*.bleeding.*' => ['boolean'],
            'teeth.*.plaque' => ['array', 'max:6'],
            'teeth.*.plaque.*' => ['boolean'],
            'teeth.*.mobility' => ['nullable', 'integer', 'between:0,3'],
            'teeth.*.furcation' => ['nullable', 'integer', 'between:0,3'],
            'stage' => ['nullable', Rule::in($vocabulary['stages'])],
            'grade' => ['nullable', Rule::in($vocabulary['grades'])],
            'extent' => ['nullable', Rule::in(array_keys($vocabulary['extents']))],
            'diagnosis' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->attempt(function () use ($encounter, $validated, $periodontal) {
            $periodontal->recordExam($encounter, $validated['teeth'], $validated, $this->doctor());

            return back()->with('success', 'Periodontal chart saved.');
        });
    }

    /**
     * A plan's lifecycle outside a consultation (the patient accepting at
     * the desk, a pause, a decline, a cancellation). Doing treatment still
     * happens in a consultation.
     */
    /**
     * A plan as an estimate the patient takes home: its treatments at today's prices, never a bill. For whoever may read the patient and
     * works with plans or money (the dentist, the desk, billing).
     */
    public function estimate(Request $request, Patient $patient, TreatmentPlan $plan): HttpResponse
    {
        $this->authorize('view', $patient);
        abort_unless($patient->clinic_id === $this->clinicContext->clinic()?->id, 404);
        abort_unless($plan->clinic_id === $patient->clinic_id && $plan->patient_id === $patient->id, 404);
        $user = $request->user();
        abort_unless(collect([ClinicPermission::ClinicalView, ClinicPermission::BillingView, ClinicPermission::BillingCreate])->contains(fn (ClinicPermission $permission) => $user->hasClinicPermission($permission)), 403);

        $items = $plan->records()->where('status', '!=', TreatmentStatus::Aborted->value)->orderBy('sort_order')->orderBy('id')->get();
        $branchId = $plan->encounter_id ? ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($plan->encounter_id)->value('branch_id') : null;
        app(SecurityAudit::class)->record('dental.plan.estimate_printed', $plan, ['patient_id' => $patient->id]);

        return response()->view('clinic.treatment_estimate', [
            'plan' => $plan, 'items' => $items, 'patient' => $patient,
            'clinic' => Clinic::query()->findOrFail($plan->clinic_id),
            'branch' => $branchId ? Branch::query()->withoutGlobalScopes()->find($branchId) : null,
        ]);
    }

    public function transitionPlan(Request $request, Patient $patient, TreatmentPlan $plan, string $transition): RedirectResponse
    {
        $this->authorize('writeClinicalData', $patient);
        abort_unless($patient->clinic_id === $this->clinicContext->clinic()?->id, 404);
        abort_unless($plan->clinic_id === $patient->clinic_id && $plan->patient_id === $patient->id, 404);
        abort_unless(app(Patient360SectionPolicy::class)->field($request->user(), $patient, 'dental'), 403);
        $reason = in_array($transition, ['pause', 'decline', 'cancel'], true)
            ? $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason']
            : '';

        return $this->attempt(function () use ($plan, $transition, $reason, $patient) {
            match ($transition) {
                'accept' => $this->dental->acceptPlan($plan),
                'pause' => $this->dental->pausePlan($plan, $reason),
                'resume' => $this->dental->resumePlan($plan),
                'decline' => $this->dental->declinePlan($plan, $reason),
                'cancel' => $this->dental->cancelPlan($plan, $reason),
            };
            app(SecurityAudit::class)->record("dental.plan.{$transition}", $plan, ['patient_id' => $patient->id, 'reason' => $reason ?: null]);

            return back()->with('success', match ($transition) {
                'accept' => 'Plan accepted.', 'pause' => 'Plan paused.', 'resume' => 'Plan resumed.',
                'decline' => 'Plan marked declined.', 'cancel' => 'Plan cancelled.',
            });
        });
    }

    private function assertSamePatient(ClinicalEncounter $encounter, TreatmentPlan $plan): void
    {
        abort_unless($plan->clinic_id === $encounter->clinic_id && $plan->patient_id === $encounter->patient_id, 404);
    }

    private function doctor(): ClinicEmployee
    {
        return $this->clinicContext->currentEmployee() ?? abort(403);
    }

    /**
     * @param  Closure(): RedirectResponse  $action
     */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['dental' => $e->getMessage()]);
        }
    }
}
