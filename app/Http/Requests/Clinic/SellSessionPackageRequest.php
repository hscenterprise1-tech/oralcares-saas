<?php

namespace App\Http\Requests\Clinic;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A prepaid session package sold to a patient. The price is entered at sale
 * (never a hard-coded fee); with a price the sale is billed. Authorization is
 * the controller's.
 */
class SellSessionPackageRequest extends FormRequest
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
            // A package always pays for one treatment.
            'patient_treatment_id' => ['required', 'integer'],
            'renews_package_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'sessions_total' => ['required', 'integer', 'between:1,200'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'clinical_procedure_id' => ['nullable', 'integer'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            // Made when the form opened: a repeated submit sells the package once.
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:80'],
        ];
    }
}
