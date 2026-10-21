<?php

namespace App\Http\Requests\Clinic\Consultation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A treatment plan proposed in a consultation. Services are checked to be
 * this clinic's in `Dental\DentalRecord`. Authorization is the controller's.
 */
class ProposeTreatmentPlanRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.clinical_procedure_id' => ['required', 'integer'],
            'items.*.tooth_number' => ['nullable', 'integer', 'between:11,85'],
            'items.*.surfaces' => ['array'],
            'items.*.surfaces.*' => [Rule::in(array_keys(config('clinic_dental.surfaces')))],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one treatment to the plan.',
            'items.*.clinical_procedure_id.required' => 'Choose a service for each treatment.',
        ];
    }
}
