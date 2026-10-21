<?php

namespace App\Http\Requests\Clinic\Consultation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A procedure done in a draft consultation, from the clinic's own service
 * catalogue (ownership is checked in `EncounterWorkspace`). Authorization is
 * the controller's.
 */
class StoreEncounterProcedureRequest extends FormRequest
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
            'clinical_procedure_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'between:1,50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['clinical_procedure_id.required' => 'Choose a service from the clinic\'s list.'];
    }
}
