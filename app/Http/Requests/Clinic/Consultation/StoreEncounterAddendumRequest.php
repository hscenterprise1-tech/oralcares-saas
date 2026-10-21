<?php

namespace App\Http\Requests\Clinic\Consultation;

use App\Models\ClinicalEncounter;
use App\Models\ClinicalEncounterAddendum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An addendum to, or an amendment of, a signed consultation. Authorization
 * is the controller's; "only the signer amends" is `EncounterWorkspace`'s.
 */
class StoreEncounterAddendumRequest extends FormRequest
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
            'kind' => ['required', Rule::in([ClinicalEncounterAddendum::ADDENDUM, ClinicalEncounterAddendum::AMENDMENT])],
            'content' => ['required', 'string', 'max:20000'],
            'section' => ['nullable', 'required_if:kind,'.ClinicalEncounterAddendum::AMENDMENT, Rule::in(ClinicalEncounter::NARRATIVE)],
            'reason' => ['nullable', 'required_if:kind,'.ClinicalEncounterAddendum::AMENDMENT, 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'section.required_if' => 'Choose the section the amendment corrects.',
            'reason.required_if' => 'Say why the signed record is being amended.',
        ];
    }
}
