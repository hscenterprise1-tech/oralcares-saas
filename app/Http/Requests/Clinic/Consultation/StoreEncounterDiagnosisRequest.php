<?php

namespace App\Http\Requests\Clinic\Consultation;

use App\Models\ClinicalDiagnosis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A diagnosis on a draft consultation. Authorization is the controller's.
 */
class StoreEncounterDiagnosisRequest extends FormRequest
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
            'description' => ['required', 'string', 'max:500'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'status' => ['required', Rule::in(array_keys(ClinicalDiagnosis::STATUSES))],
            'is_primary' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.regex' => 'Use the code as written, e.g. K02.1.'];
    }
}
