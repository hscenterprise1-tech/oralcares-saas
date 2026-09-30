<?php

namespace App\Http\Requests\Patient;

use App\Services\ClinicContext;
use App\Services\PatientFieldConfigService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by PatientPolicy via controller
    }

    /**
     * Built from the acting clinic's `PatientFieldConfigService::resolve()`
     * state rather than a fixed array — a field an admin has marked
     * required or hidden here must actually be enforced server-side, not
     * just hidden from the form, or the setting would be advisory only.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $clinic = app(ClinicContext::class)->clinic();
        $config = app(PatientFieldConfigService::class)->resolve($clinic);

        $required = fn (string $field): string => $config[$field] === PatientFieldConfigService::REQUIRED ? 'required' : 'nullable';

        return [
            // Identity — first_name is the one field never configurable.
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => [$required('last_name'), 'string', 'max:100'],
            'date_of_birth' => [$required('date_of_birth'), 'date', 'before:today'],
            'age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'gender' => [$required('gender'), 'string', 'in:male,female,other'],
            'blood_group' => [$required('blood_group'), 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],

            // Contact — phone is the other never-configurable field.
            'phone' => ['required', 'string', 'max:15'],
            'alternate_phone' => [$required('alternate_phone'), 'string', 'max:15'],
            'email' => [$required('email'), 'email', 'max:255'],

            // Address — one group setting governs all four inputs.
            'address' => [$required('address'), 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:10'],

            // Emergency contact — one group setting governs both inputs.
            'emergency_contact_name' => [$required('emergency_contact'), 'string', 'max:100'],
            'emergency_contact_phone' => [$required('emergency_contact'), 'string', 'max:15'],

            // Medical
            'allergies' => array_filter([
                $required('allergies'),
                'array',
                $config['allergies'] === PatientFieldConfigService::REQUIRED ? 'min:1' : null,
            ]),
            'allergies.*' => ['string'],
            'medical_alerts' => ['nullable', 'array'],
            'medical_alerts.*' => ['string'],

            // Visit options
            'department_id' => ['nullable', 'integer', Rule::exists('clinic_departments', 'id')->where('clinic_id', $clinic?->id)],
            'doctor_id' => [$required('doctor_id'), 'integer', Rule::exists('clinic_employees', 'id')->where('clinic_id', $clinic?->id)],
            'fee_collected' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function patientData(): array
    {
        return $this->only([
            'first_name', 'last_name', 'date_of_birth', 'age', 'gender',
            'blood_group', 'phone', 'alternate_phone', 'email',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function visitData(): array
    {
        return $this->only(['department_id', 'doctor_id', 'fee_collected', 'notes']);
    }

    /**
     * @return array<string, mixed>
     */
    public function profileData(): array
    {
        return $this->only([
            'address', 'city', 'state', 'pincode',
            'emergency_contact_name', 'emergency_contact_phone',
        ]);
    }
}
