<?php

namespace App\Http\Requests\Clinic\Consultation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A physiotherapy assessment. Outcome-measure ranges and strength grades are
 * checked in `Physio\PhysioRecord`. Authorization is the controller's.
 */
class PhysioAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'presenting_problem' => ['nullable', 'string', 'max:5000'],
            'pain_at_rest' => ['nullable', 'integer', 'between:0,10'],
            'pain_on_activity' => ['nullable', 'integer', 'between:0,10'],
            'body_chart' => ['array', 'max:30'],
            'body_chart.*.region' => ['required', Rule::in(config('clinic_physio.body_regions'))],
            'body_chart.*.side' => ['nullable', Rule::in(array_keys(config('clinic_physio.sides')))],
            'body_chart.*.pain_type' => ['nullable', Rule::in(config('clinic_physio.pain_types'))],
            'body_chart.*.note' => ['nullable', 'string', 'max:200'],
            'range_of_motion' => ['array', 'max:40'],
            'range_of_motion.*.joint' => ['required', 'string', 'max:60'],
            'range_of_motion.*.movement' => ['required', 'string', 'max:60'],
            'range_of_motion.*.side' => ['nullable', Rule::in(array_keys(config('clinic_physio.sides')))],
            'range_of_motion.*.active' => ['nullable', 'integer', 'between:-90,360'],
            'range_of_motion.*.passive' => ['nullable', 'integer', 'between:-90,360'],
            'range_of_motion.*.normal' => ['nullable', 'integer', 'between:-90,360'],
            'strength' => ['array', 'max:40'],
            'strength.*.muscle_group' => ['required', 'string', 'max:60'],
            'strength.*.side' => ['nullable', Rule::in(array_keys(config('clinic_physio.sides')))],
            'strength.*.grade' => ['required', 'integer', 'between:0,5'],
            'posture' => ['nullable', 'string', 'max:5000'],
            'functional_measures' => ['array', 'max:10'],
            'functional_measures.*.measure' => ['required', Rule::in(array_keys(config('clinic_physio.functional_measures')))],
            'functional_measures.*.score' => ['required', 'numeric', 'min:0'],
            'functional_notes' => ['nullable', 'string', 'max:5000'],
            'goals' => ['nullable', 'string', 'max:5000'],
            'pain_location' => ['nullable', 'string', 'max:255'],
            'onset' => ['nullable', 'string', 'max:255'],
            'aggravating_factors' => ['nullable', 'string', 'max:5000'],
            'relieving_factors' => ['nullable', 'string', 'max:5000'],
            'objective_findings' => ['nullable', 'string', 'max:10000'],
            'gait' => ['nullable', 'string', 'max:5000'],
            'special_tests' => ['array', 'max:30'],
            'special_tests.*.test' => ['required', 'string', 'max:100'],
            'special_tests.*.side' => ['nullable', Rule::in(array_keys(config('clinic_physio.sides')))],
            'special_tests.*.result' => ['required', Rule::in(['positive', 'negative', 'equivocal'])],
            'special_tests.*.note' => ['nullable', 'string', 'max:200'],
            'clinical_impression' => ['nullable', 'string', 'max:10000'],
            'diagnosis' => ['nullable', 'string', 'max:500'],
        ];
    }
}
