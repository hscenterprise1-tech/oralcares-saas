<?php

namespace App\Http\Requests\Patient;

use App\Services\ClinicContext;
use App\Services\PatientFieldConfigService;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by PatientPolicy via controller
    }

    /**
     * Same field-config-driven shape as `RegisterPatientRequest::rules()` —
     * a field an admin has hidden or required must be enforced identically
     * whether it's being set at registration or edited afterwards. Leaves
     * out `doctor_id`/`department_id`/visit-only fields: those describe a
     * new visit being booked, which editing a patient's demographics never
     * does — changing who a patient is currently assigned to goes through
     * the audited Transfer action instead.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $clinic = app(ClinicContext::class)->clinic();
        $config = app(PatientFieldConfigService::class)->resolve($clinic);

        $required = fn (string $field): string => $config[$field] === PatientFieldConfigService::REQUIRED ? 'required' : 'nullable';

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => [$required('last_name'), 'string', 'max:100'],
            'date_of_birth' => [$required('date_of_birth'), 'date', 'before:today'],
            'age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'gender' => [$required('gender'), 'string', 'in:male,female,other'],
            'blood_group' => [$required('blood_group'), 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],

            'phone' => ['required', 'string', 'max:15'],
            'alternate_phone' => [$required('alternate_phone'), 'string', 'max:15'],
            'email' => [$required('email'), 'email', 'max:255'],

            'address' => [$required('address'), 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:10'],

            'emergency_contact_name' => [$required('emergency_contact'), 'string', 'max:100'],
            'emergency_contact_phone' => [$required('emergency_contact'), 'string', 'max:15'],

            'allergies' => array_filter([
                $required('allergies'),
                'array',
                $config['allergies'] === PatientFieldConfigService::REQUIRED ? 'min:1' : null,
            ]),
            'allergies.*' => ['string'],
            'medical_alerts' => ['nullable', 'array'],
            'medical_alerts.*' => ['string'],
        ];
    }
}
