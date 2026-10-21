<?php

namespace App\Http\Requests\Clinic\Consultation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The result of one investigation: a written report and/or measured values.
 * Authorization is the controller's (`ClinicalOrderPolicy::fulfil`).
 */
class RecordResultRequest extends FormRequest
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
            'summary' => ['nullable', 'required_without:values', 'string', 'max:20000'],
            'is_abnormal' => ['boolean'],
            'values' => ['nullable', 'array', 'max:60'],
            'values.*.name' => ['required', 'string', 'max:100'],
            'values.*.value' => ['required', 'string', 'max:60'],
            'values.*.unit' => ['nullable', 'string', 'max:30'],
            'values.*.reference_range' => ['nullable', 'string', 'max:60'],
            'values.*.flag' => ['nullable', Rule::in(['normal', 'high', 'low', 'abnormal', 'critical', 'positive', 'negative'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['summary.required_without' => 'Enter the report or at least one value.'];
    }
}
