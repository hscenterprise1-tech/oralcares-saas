<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\EndodonticRecord;
use App\Models\ImplantRecord;
use App\Models\LabOrder;
use App\Models\OrthodonticCase;
use App\Models\Patient;
use App\Services\ClinicContext;
use App\Services\Dental\DentalSpecialtyRecords;
use App\Services\LaboratoryCaseService;
use App\Services\LaboratoryWorkflowService;
use App\Services\Patient\Patient360\Patient360SectionPolicy;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Dental specialty records over HTTP: root canal, implant and orthodontic
 * records and lab orders written inside a consultation (the `odontogram`
 * extension, the treating doctor, an open consultation), and lab steps
 * taken from Patient 360 (the desk sends and receives; clinicians fit,
 * complete, remake and cancel). Rules live in the services.
 */
class DentalSpecialtyController extends Controller
{
    private const EXTENSION = 'odontogram';

    public function __construct(
        protected ClinicContext $clinicContext,
        protected DentalSpecialtyRecords $records,
    ) {}

    public function saveEndodontic(Request $request, ClinicalEncounter $encounter, ?int $record = null): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);
        $data = $request->validate([
            'tooth_number' => [$record ? 'prohibited' : 'required', 'integer', 'between:11,85'],
            'status' => ['sometimes', 'string', 'max:20'], 'stage' => ['sometimes', 'string', 'max:30'],
            'pulp_status' => ['sometimes', 'nullable', 'string', 'max:40'], 'periapical_status' => ['sometimes', 'nullable', 'string', 'max:40'],
            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:500'],
            'canals' => ['sometimes', 'array', 'max:6'], 'canals.*.name' => ['required', 'string', 'max:20'],
            'canals.*.working_length' => ['nullable', 'numeric'], 'canals.*.reference' => ['nullable', 'string', 'max:40'], 'canals.*.master_file' => ['nullable', 'string', 'max:40'],
            'instrumentation_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'obturation_technique' => ['sometimes', 'nullable', 'string', 'max:100'], 'obturation_material' => ['sometimes', 'nullable', 'string', 'max:100'],
            'obturated_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'temporary_restoration' => ['sometimes', 'nullable', 'string', 'max:150'], 'permanent_restoration' => ['sometimes', 'nullable', 'string', 'max:150'],
            'review_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $existing = $record ? $this->find(EndodonticRecord::class, $record, $encounter) : null;

        return $this->attempt(fn () => tap(back()->with('success', $existing ? 'Root canal record updated.' : 'Root canal record started.'),
            fn () => $this->records->saveEndodontic($encounter, $existing, $data, $this->doctor())));
    }

    public function saveImplant(Request $request, ClinicalEncounter $encounter, ?int $record = null): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);
        $data = $request->validate([
            'tooth_number' => [$record ? 'prohibited' : 'required', 'integer', 'between:11,85'],
            'status' => ['sometimes', 'string', 'max:20'], 'system' => ['sometimes', 'nullable', 'string', 'max:120'],
            'diameter_mm' => ['sometimes', 'nullable', 'numeric'], 'length_mm' => ['sometimes', 'nullable', 'numeric'],
            'lot_number' => ['sometimes', 'nullable', 'string', 'max:60'], 'placed_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'insertion_torque_ncm' => ['sometimes', 'nullable', 'integer'], 'integration_status' => ['sometimes', 'nullable', 'string', 'max:20'],
            'abutment' => ['sometimes', 'nullable', 'string', 'max:120'], 'abutment_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'crown_status' => ['sometimes', 'nullable', 'string', 'max:20'], 'crown_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'review_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'complications' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $existing = $record ? $this->find(ImplantRecord::class, $record, $encounter) : null;

        return $this->attempt(fn () => tap(back()->with('success', $existing ? 'Implant record updated.' : 'Implant recorded.'),
            fn () => $this->records->saveImplant($encounter, $existing, $data, $this->doctor())));
    }

    public function saveOrthodontic(Request $request, ClinicalEncounter $encounter, ?int $case = null): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);
        $data = $request->validate([
            'arches' => ['sometimes', 'string', 'max:10'], 'appliance' => [$case ? 'sometimes' : 'required', 'string', 'max:30'],
            'bracket_system' => ['sometimes', 'nullable', 'string', 'max:120'], 'phase' => ['sometimes', 'string', 'max:20'],
            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:500'], 'started_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'estimated_months' => ['sometimes', 'nullable', 'integer'], 'bracketed_teeth' => ['sometimes', 'array', 'max:32'],
            'bracketed_teeth.*' => ['integer', 'between:11,85'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $existing = $case ? $this->find(OrthodonticCase::class, $case, $encounter) : null;

        return $this->attempt(fn () => tap(back()->with('success', $existing ? 'Orthodontic case updated.' : 'Orthodontic case opened.'),
            fn () => $this->records->saveOrthodonticCase($encounter, $existing, $data, $this->doctor())));
    }

    public function recordVisit(Request $request, ClinicalEncounter $encounter, int $case): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);
        $data = $request->validate([
            'phase' => ['sometimes', 'nullable', 'string', 'max:20'], 'upper_wire' => ['sometimes', 'nullable', 'string', 'max:80'],
            'lower_wire' => ['sometimes', 'nullable', 'string', 'max:80'], 'adjustments' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'elastics' => ['sometimes', 'nullable', 'string', 'max:150'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'next_visit_weeks' => ['sometimes', 'nullable', 'integer'],
        ]);
        $existing = $this->find(OrthodonticCase::class, $case, $encounter);

        return $this->attempt(fn () => tap(back()->with('success', 'Adjustment recorded.'),
            fn () => $this->records->recordOrthodonticVisit($encounter, $existing, $data, $this->doctor())));
    }

    public function orderLab(Request $request, ClinicalEncounter $encounter, LaboratoryCaseService $lab): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, self::EXTENSION]);
        $data = $request->validate([
            'teeth' => ['required', 'array', 'min:1', 'max:32'], 'teeth.*' => ['integer', 'between:11,85'],
            'restoration' => ['required', 'string', 'max:150'], 'laboratory_name' => ['nullable', 'string', 'max:150'],
            'shade' => ['nullable', 'string', 'max:60'], 'material' => ['nullable', 'string', 'max:100'],
            'delivery_due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'], 'notes' => ['nullable', 'string', 'max:2000'],
            'treatment_record_id' => ['nullable', 'integer'],
        ]);

        return $this->attempt(fn () => tap(back()->with('success', 'Lab work ordered.'), fn () => $lab->order($encounter, $this->doctor(), $data)));
    }

    /**
     * A lab step from Patient 360. The front desk may send and receive;
     * fitting, completing, remaking and cancelling are clinical.
     */
    public function labStep(Request $request, Patient $patient, int $labOrder, string $step, LaboratoryWorkflowService $workflow): RedirectResponse
    {
        $this->authorize('view', $patient);
        abort_unless($patient->clinic_id === $this->clinicContext->clinic()?->id, 404);
        $user = $request->user();
        $policy = app(Patient360SectionPolicy::class);
        $desk = in_array($step, LaboratoryWorkflowService::DESK_STEPS, true);
        abort_unless(
            $policy->field($user, $patient, 'dental') && $user->hasClinicPermission(ClinicPermission::ClinicalEdit)
            || ($desk && $policy->field($user, $patient, 'dental_operational') && $user->hasClinicPermission(ClinicPermission::QueueManage)),
            403,
        );
        $order = LabOrder::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->findOrFail($labOrder);
        $reason = $step === 'cancel' ? $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'] : null;

        return $this->attempt(fn () => tap(back()->with('success', 'Lab case updated.'), fn () => $workflow->advance($order, $step, $reason)));
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    private function find(string $model, int $id, ClinicalEncounter $encounter)
    {
        return $model::query()->where('clinic_id', $encounter->clinic_id)->where('patient_id', $encounter->patient_id)->findOrFail($id);
    }

    private function doctor(): ClinicEmployee
    {
        return $this->clinicContext->currentEmployee() ?? abort(403);
    }

    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['dental' => $e->getMessage()]);
        }
    }
}
