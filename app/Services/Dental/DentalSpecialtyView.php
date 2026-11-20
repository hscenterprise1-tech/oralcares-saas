<?php

namespace App\Services\Dental;

use App\LabCaseStatus;
use App\Models\ClinicalEncounter;
use App\Models\EndodonticRecord;
use App\Models\ImplantRecord;
use App\Models\LabOrder;
use App\Models\OrthodonticCase;
use App\Models\OrthodonticVisit;
use App\Models\Patient;
use App\Services\LaboratoryWorkflowService;

/**
 * The dental specialty work shown on the visit page: the patient's root
 * canal records, implants, orthodontic case with its adjustment visits, and
 * lab cases, each with the address that saves it. Reading only; every write
 * goes through `DentalSpecialtyRecords` and `LaboratoryCaseService` (see
 * `DentalSpecialtyController`).
 */
class DentalSpecialtyView
{
    /**
     * @return array<string, mixed>
     */
    public function forVisit(ClinicalEncounter $encounter, Patient $patient, bool $canWrite): array
    {
        $scope = fn ($model) => $model::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->whereNull('voided_at');

        $endodontic = $scope(EndodonticRecord::class)->orderByDesc('id')->limit(20)->get();
        $implants = $scope(ImplantRecord::class)->orderByDesc('id')->limit(20)->get();
        $case = $scope(OrthodonticCase::class)->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")->orderByDesc('id')->first();
        $labs = LabOrder::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->orderByDesc('id')->limit(20)->get();

        return [
            'can_write' => $canWrite && $encounter->isEditable(),
            'endodontic' => $endodontic->map(fn (EndodonticRecord $r) => [
                'id' => $r->id, 'tooth' => $r->tooth_number, 'status' => $r->status, 'stage' => $r->stage, 'pulp_status' => $r->pulp_status,
                'periapical_status' => $r->periapical_status, 'diagnosis' => $r->diagnosis, 'canals' => array_values((array) $r->canals),
                'instrumentation_notes' => $r->instrumentation_notes, 'obturation_technique' => $r->obturation_technique, 'obturation_material' => $r->obturation_material,
                'obturated_on' => $r->obturated_on?->toDateString(), 'temporary_restoration' => $r->temporary_restoration, 'permanent_restoration' => $r->permanent_restoration,
                'review_on' => $r->review_on?->toDateString(), 'notes' => $r->notes, 'started' => $r->created_at?->toDateString(),
                'url' => route('clinic.consultations.dental.endodontic.update', [$encounter, $r->id]),
            ])->values()->all(),
            'implants' => $implants->map(fn (ImplantRecord $r) => [
                'id' => $r->id, 'tooth' => $r->tooth_number, 'status' => $r->status, 'system' => $r->system, 'diameter_mm' => $r->diameter_mm, 'length_mm' => $r->length_mm,
                'lot_number' => $r->lot_number, 'placed_on' => $r->placed_on?->toDateString(), 'insertion_torque_ncm' => $r->insertion_torque_ncm,
                'integration_status' => $r->integration_status, 'abutment' => $r->abutment, 'abutment_on' => $r->abutment_on?->toDateString(),
                'crown_status' => $r->crown_status, 'crown_on' => $r->crown_on?->toDateString(), 'review_on' => $r->review_on?->toDateString(),
                'complications' => $r->complications, 'notes' => $r->notes, 'started' => $r->created_at?->toDateString(),
                'url' => route('clinic.consultations.dental.implants.update', [$encounter, $r->id]),
            ])->values()->all(),
            'ortho' => [
                'case' => $case ? [
                    'id' => $case->id, 'status' => $case->status, 'phase' => $case->phase, 'arches' => $case->arches, 'appliance' => $case->appliance,
                    'bracket_system' => $case->bracket_system, 'diagnosis' => $case->diagnosis, 'started_on' => $case->started_on?->toDateString(),
                    'estimated_months' => $case->estimated_months, 'bracketed_teeth' => array_values((array) $case->bracketed_teeth), 'notes' => $case->notes,
                    'url' => route('clinic.consultations.dental.orthodontic.update', [$encounter, $case->id]),
                    'visit_url' => route('clinic.consultations.dental.orthodontic.visit', [$encounter, $case->id]),
                ] : null,
                'visits' => $case ? OrthodonticVisit::query()->withoutGlobalScopes()->where('orthodontic_case_id', $case->id)->orderByDesc('id')->limit(12)->get()
                    ->map(fn (OrthodonticVisit $v) => [
                        'id' => $v->id, 'date' => $v->created_at?->toDateString(), 'phase' => $v->phase, 'upper_wire' => $v->upper_wire, 'lower_wire' => $v->lower_wire,
                        'adjustments' => $v->adjustments, 'elastics' => $v->elastics, 'notes' => $v->notes, 'next_visit_weeks' => $v->next_visit_weeks,
                        'this_visit' => (int) $v->encounter_id === (int) $encounter->id,
                    ])->values()->all() : [],
            ],
            'lab' => $labs->map(function (LabOrder $o) use ($patient) {
                $status = $o->status instanceof LabCaseStatus ? $o->status->value : (string) $o->status;

                return [
                    'id' => $o->id, 'number' => $o->lab_order_number, 'restoration' => $o->restoration, 'teeth' => array_values((array) $o->requested_teeth),
                    'laboratory' => $o->laboratory_name, 'shade' => $o->shade, 'material' => $o->material, 'status' => $status,
                    'due' => $o->delivery_due_date?->toDateString(), 'notes' => $o->notes, 'cancellation_reason' => $o->cancellation_reason,
                    'steps' => collect(LaboratoryWorkflowService::STEPS)->filter(fn (array $rule) => in_array($status, $rule['from'], true))->keys()
                        ->map(fn (string $step) => ['step' => $step, 'url' => route('clinic.patients.dental.lab.step', [$patient, $o->id, $step])])->values()->all(),
                ];
            })->values()->all(),
            'vocab' => [
                'endodontic' => ['statuses' => EndodonticRecord::STATUSES, 'stages' => EndodonticRecord::STAGES, 'pulp' => DentalSpecialtyRecords::PULP, 'periapical' => DentalSpecialtyRecords::PERIAPICAL],
                'implant' => ['statuses' => ImplantRecord::STATUSES, 'integration' => ImplantRecord::INTEGRATION, 'crown' => ImplantRecord::CROWN],
                'ortho' => ['phases' => OrthodonticCase::PHASES, 'appliances' => OrthodonticCase::APPLIANCES],
            ],
            'links' => [
                'endodontic' => route('clinic.consultations.dental.endodontic.store', $encounter),
                'implant' => route('clinic.consultations.dental.implants.store', $encounter),
                'ortho' => route('clinic.consultations.dental.orthodontic.store', $encounter),
                'lab' => route('clinic.consultations.dental.lab.store', $encounter),
            ],
        ];
    }
}
