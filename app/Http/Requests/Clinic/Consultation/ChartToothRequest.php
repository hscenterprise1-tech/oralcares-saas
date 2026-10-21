<?php

namespace App\Http\Requests\Clinic\Consultation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A finding charted on one tooth. FDI numbering and which conditions take
 * surfaces are checked in `Dental\DentalRecord`. Authorization is the
 * controller's (`ClinicalEncounterPolicy::writeExtension`).
 */
class ChartToothRequest extends FormRequest
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
            'tooth' => ['required', 'integer', 'between:11,85'],
            'condition' => ['required', Rule::in(array_keys(config('clinic_dental.conditions')))],
            'surfaces' => ['array'],
            'surfaces.*' => [Rule::in(array_keys(config('clinic_dental.surfaces')))],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
