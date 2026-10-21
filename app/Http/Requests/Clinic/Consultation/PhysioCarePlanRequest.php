<?php

namespace App\Http\Requests\Clinic\Consultation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A plan of care and its exercise programme (also used to replace the
 * programme of an active plan). Authorization is the controller's.
 */
class PhysioCarePlanRequest extends FormRequest
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
            // Optional: the plan of an existing treatment; otherwise a new ad-hoc treatment is started.
            'patient_treatment_id' => ['nullable', 'integer'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'goals' => ['nullable', 'string', 'max:5000'],
            'interventions' => ['array'],
            'interventions.*' => [Rule::in(array_keys(config('clinic_physio.modalities')))],
            'planned_sessions' => ['nullable', 'integer', 'between:1,200'],
            'frequency' => ['nullable', 'string', 'max:60'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'exercises' => ['array', 'max:40'],
            'exercises.*.name' => ['required', 'string', 'max:150'],
            'exercises.*.sets' => ['nullable', 'integer', 'between:0,50'],
            'exercises.*.reps' => ['nullable', 'integer', 'between:0,500'],
            'exercises.*.hold_seconds' => ['nullable', 'integer', 'between:0,600'],
            'exercises.*.frequency' => ['nullable', 'string', 'max:60'],
            'exercises.*.instructions' => ['nullable', 'string', 'max:500'],
            'exercises.*.is_home' => ['boolean'],
        ];
    }
}
