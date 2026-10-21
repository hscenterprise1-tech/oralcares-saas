<?php

namespace App\Http\Requests\Patient;

use App\Models\PatientProfile;
use App\Rules\PhoneNumber;
use App\Services\ClinicContext;
use App\Services\PatientFieldConfigService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by PatientPolicy via controller
    }

    /**
     * Same field-config-driven shape as `RegisterPatientRequest::rules()` —
     * a field an admin has hidden or required is enforced identically
     * whether it's set at registration or edited afterwards — with one rule
     * for edits: a field that is not sent is not changed. A field hidden
     * from this member, or one their role may not edit, is dropped even if
     * sent, so hiding a field never erases what is already recorded.
     * Sending a field empty is an explicit change and clears it (unless the
     * field is required).
     *
     * Leaves out `doctor_id`/`department_id`/visit-only fields: those
     * describe a new visit being booked, which editing a patient's
     * demographics never does — changing who a patient is currently
     * assigned to goes through the audited Transfer action instead.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $clinic = app(ClinicContext::class)->clinic();
        $config = app(PatientFieldConfigService::class)->effective($clinic, $this->user(), null, PatientFieldConfigService::SURFACE_FULL_PAGE);

        // Hidden or not editable by this member: never written. Otherwise: only when sent.
        $rule = function (string $field) use ($config): array {
            $setting = $config[$field] ?? ['state' => PatientFieldConfigService::OPTIONAL, 'editable' => true];
            if ($setting['state'] === PatientFieldConfigService::HIDDEN || ! $setting['editable']) {
                return ['exclude'];
            }

            // Required: must be sent (an edit that leaves it out is refused, never saved empty). Optional: untouched when left out.
            return $setting['state'] === PatientFieldConfigService::REQUIRED ? ['required'] : ['sometimes', 'nullable'];
        };
        $part = fn (string $group): array => $rule($group) === ['exclude'] ? ['exclude'] : ['sometimes', 'nullable'];

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => [...$rule('last_name'), 'string', 'max:100'],
            'date_of_birth' => [...$rule('date_of_birth'), 'date', 'before:today'],
            'age' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:150'],
            'gender' => [...$rule('gender'), 'string', 'in:male,female,other'],
            'blood_group' => [...$rule('blood_group'), 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],

            'phone' => ['sometimes', 'required', 'string', 'max:20', new PhoneNumber],
            'alternate_phone' => [...$rule('alternate_phone'), 'string', 'max:20', new PhoneNumber],
            'email' => [...$rule('email'), 'email', 'max:255'],

            'address' => [...$rule('address'), 'string', 'max:500'],
            'locality' => [...$rule('address_locality'), 'string', 'max:120'],
            'city' => [...$rule('address_city'), 'string', 'max:100'],
            'state' => [...$rule('address_state'), 'string', 'max:100'],
            'pincode' => [...$rule('address_pincode'), 'string', 'max:10'],
            'country' => [...$rule('address_country'), 'string', 'max:80'],
            'preferred_channel' => ['sometimes', 'nullable', Rule::in(PatientProfile::CHANNELS)],

            'emergency_contact_name' => [...$rule('emergency_contact'), 'string', 'max:100'],
            'emergency_contact_phone' => [...$part('emergency_contact'), 'string', 'max:20', new PhoneNumber],

            'allergies' => $rule('allergies') === ['exclude'] ? ['exclude'] : array_values(array_filter([
                ...$rule('allergies'),
                'array',
                ($config['allergies']['state'] ?? null) === PatientFieldConfigService::REQUIRED ? 'min:1' : null,
            ])),
            'allergies.*' => ['string'],
            'medical_alerts' => ['sometimes', 'nullable', 'array'],
            'medical_alerts.*' => ['string'],
        ];
    }
}
