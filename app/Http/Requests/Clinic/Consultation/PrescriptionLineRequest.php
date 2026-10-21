<?php

namespace App\Http\Requests\Clinic\Consultation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One medicine on a draft prescription. The medicine, if chosen from the
 * formulary, is checked to be this clinic's in `ClinicalPrescriptionService`.
 * Authorization is the controller's (`PrescriptionPolicy::update`).
 */
class PrescriptionLineRequest extends FormRequest
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
            'medicine_id' => ['nullable', 'integer'],
            'drug_name' => ['required_without:medicine_id', 'nullable', 'string', 'max:150'],
            'form' => ['nullable', 'string', 'max:30'],
            'strength' => ['nullable', 'string', 'max:40'],
            'dosage' => ['required', 'string', 'max:60'],
            'frequency' => ['required', 'string', 'max:30'],
            'duration' => ['required', 'string', 'max:40'],
            'route' => ['nullable', Rule::in(array_keys(config('clinic_prescriptions.routes')))],
            'timing' => ['nullable', Rule::in(array_keys(config('clinic_prescriptions.timings')))],
            'instructions' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'drug_name.required_without' => 'Name the medicine or choose it from the list.',
            'dosage.required' => 'Give the dose, e.g. 1 tablet.',
            'frequency.required' => 'Say how often, e.g. twice a day.',
            'duration.required' => 'Say for how long, e.g. 5 days.',
        ];
    }
}
