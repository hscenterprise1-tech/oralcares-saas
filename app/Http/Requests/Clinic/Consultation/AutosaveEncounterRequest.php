<?php

namespace App\Http\Requests\Clinic\Consultation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A draft save from the consultation screen. Authorization is the
 * controller's (`ClinicalEncounterPolicy::update`).
 */
class AutosaveEncounterRequest extends FormRequest
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
        $rules = [
            'lock_version' => ['required', 'integer', 'min:0'],
            'chief_complaint' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'history' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'examination' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'follow_up_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'follow_up_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            // Vitals are no longer recorded in the consultation: anything sent under that name is dropped.
            'vitals' => ['exclude'],
        ];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'follow_up_on.after_or_equal' => 'The follow-up date cannot be in the past.',
        ];
    }
}
