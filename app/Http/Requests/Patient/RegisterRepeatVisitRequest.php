<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRepeatVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'exists:clinic_departments,id'],
            'doctor_id' => ['nullable', 'integer', 'exists:clinic_employees,id'],
            'fee_collected' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function visitData(): array
    {
        return $this->only(['department_id', 'doctor_id', 'fee_collected', 'notes']);
    }
}
