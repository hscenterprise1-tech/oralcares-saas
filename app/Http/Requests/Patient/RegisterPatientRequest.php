<?php

namespace App\Http\Requests\Patient;

use App\Models\ConsentRecord;
use App\Models\PatientProfile;
use App\Rules\PhoneNumber;
use App\Services\ClinicContext;
use App\Services\Patient\ClinicSpecialties;
use App\Services\PatientDuplicateDetectionService;
use App\Services\PatientFieldConfigService;
use App\VisitPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One registration request for every way in: the full New Patient page and
 * Reception's New Case dialog (`surface=new_case`). Rules come from the
 * clinic's effective field configuration for this member, specialty and
 * surface: a required field is enforced, a hidden one is dropped, so a
 * setting is never advisory only. First name and mobile are always required.
 */
class RegisterPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by PatientPolicy via controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $clinic = app(ClinicContext::class)->clinic();
        $config = app(PatientFieldConfigService::class)->effective($clinic, $this->user(), $this->primarySpecialty(), $this->surface());

        $rule = function (string $field) use ($config): string {
            return match ($config[$field]['state']) {
                PatientFieldConfigService::REQUIRED => 'required',
                PatientFieldConfigService::HIDDEN => 'exclude',
                default => 'nullable',
            };
        };
        $listRule = fn (string $field): array => array_values(array_filter([
            $rule($field),
            'array',
            'max:30',
            $config[$field]['state'] === PatientFieldConfigService::REQUIRED ? 'min:1' : null,
        ]));

        return [
            'surface' => ['nullable', Rule::in([PatientFieldConfigService::SURFACE_NEW_CASE, PatientFieldConfigService::SURFACE_FULL_PAGE])],

            // Identity — first name and mobile are never configurable.
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$rule('last_name'), 'string', 'max:100'],
            'date_of_birth' => [$rule('date_of_birth'), 'date', 'before:today'],
            'age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'gender' => [$rule('gender'), 'string', 'in:male,female,other'],
            'blood_group' => [$rule('blood_group'), 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],

            'phone' => ['required', 'string', 'max:20', new PhoneNumber],
            'alternate_phone' => [$rule('alternate_phone'), 'string', 'max:20', new PhoneNumber],
            'email' => [$rule('email'), 'email', 'max:255'],

            // The address line and each part has its own setting (a part not
            // set follows the line's — `PatientFieldConfigService`).
            'address' => [$rule('address'), 'string', 'max:500'],
            'locality' => [$rule('address_locality'), 'string', 'max:120'],
            'city' => [$rule('address_city'), 'string', 'max:100'],
            'state' => [$rule('address_state'), 'string', 'max:100'],
            'pincode' => [$rule('address_pincode'), 'string', 'max:10'],
            'country' => [$rule('address_country'), 'string', 'max:80'],
            'emergency_contact_name' => [$rule('emergency_contact'), 'string', 'max:100'],
            'emergency_contact_phone' => [$rule('emergency_contact'), 'string', 'max:20', new PhoneNumber],

            // Clinical: each entry a catalogue key, or a custom name (checked
            // against the clinic's enabled catalogue by PatientRegistrar).
            'allergies' => $listRule('allergies'),
            'medical_conditions' => $listRule('medical_conditions'),
            'medications' => $listRule('medications'),
            'surgical_history' => $listRule('surgical_history'),
            'family_history' => $listRule('family_history'),
            'medical_alerts' => ['nullable', 'array', 'max:20'],
            'medical_alerts.*' => ['string', 'max:160'],
            'alert_keys' => ['nullable', 'array', 'max:12'],
            'alert_keys.*' => ['string', 'max:64'],
            // 'none_known' is a recorded fact ("no known allergies"); not sent means not asked.
            'allergy_status' => ['nullable', Rule::in(['none_known'])],

            // Clinic context: the clinic's active specialties only.
            'specialties' => ['nullable', 'array', 'max:10'],
            'specialties.*' => ['string', Rule::in($clinic ? app(ClinicSpecialties::class)->activeKeys($clinic) : [])],

            'preferred_channel' => ['nullable', Rule::in(PatientProfile::CHANNELS)],
            'communication_consent' => ['nullable', 'boolean'],
            'consent_capture_method' => ['nullable', Rule::in(ConsentRecord::METHODS)],

            // Registering despite a possible existing patient.
            'duplicate_acknowledged_ids' => ['nullable', 'array', 'max:'.PatientDuplicateDetectionService::LIMIT],
            'duplicate_acknowledged_ids.*' => ['integer'],
            'duplicate_reason' => ['nullable', 'string', 'max:500'],

            // First visit / check-in.
            'department_id' => ['nullable', 'integer', Rule::exists('clinic_departments', 'id')->where('clinic_id', $clinic?->id)],
            'doctor_id' => [$rule('doctor_id'), 'integer', Rule::exists('clinic_employees', 'id')->where('clinic_id', $clinic?->id)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'check_in' => ['nullable', 'boolean'],
            'visit_type' => ['nullable', 'string', 'max:64'],
            'visit_reason' => ['nullable', 'string', 'max:255'],
            'visit_area' => ['nullable', 'string', 'max:60'],
            'visit_reason_key' => ['nullable', 'string', 'max:64'],
            // Vitals are no longer taken at registration: anything sent under that name is dropped.
            'vitals' => ['exclude'],
            'appointment_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:80'],
            // Payment at check-in, when the clinic charges then. The amount charged is never sent: it is the doctor's fee.
            // Money is never taken at check-in: the check-in stands on its own, and a charge it raised is
            // paid afterwards through the billing payment endpoint. A payment sent here is dropped.
            'payment' => ['exclude'],
            // What the desk showed would be charged at check-in (null: nothing); verified by `CheckInCharges`.
            'expected_charge' => ['nullable', 'numeric', 'min:0'],
            'priority' => ['nullable', Rule::enum(VisitPriority::class)],
        ];
    }

    public function surface(): string
    {
        return $this->input('surface') === PatientFieldConfigService::SURFACE_NEW_CASE
            ? PatientFieldConfigService::SURFACE_NEW_CASE
            : PatientFieldConfigService::SURFACE_FULL_PAGE;
    }

    /** The specialty field applicability is judged by: the first one chosen. */
    public function primarySpecialty(): ?string
    {
        $specialties = $this->input('specialties');

        return is_array($specialties) && is_string($specialties[0] ?? null) ? $specialties[0] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function patientData(): array
    {
        return collect($this->validated())->only([
            'first_name', 'middle_name', 'last_name', 'date_of_birth', 'age', 'gender',
            'blood_group', 'phone', 'alternate_phone', 'email',
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function visitData(): array
    {
        return collect($this->validated())->only(['department_id', 'doctor_id', 'notes'])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function profileData(): array
    {
        return collect($this->validated())->only([
            'address', 'locality', 'city', 'state', 'pincode', 'country',
            'emergency_contact_name', 'emergency_contact_phone', 'preferred_channel',
        ])->all();
    }

    /**
     * The clinical lists as entries: a plain string is a catalogue key or a
     * name; an object may carry key/label/severity/details. Hidden fields
     * were dropped by validation and come back empty.
     *
     * @return array<string, list<array{key?: ?string, label?: ?string, severity?: ?string, details?: ?array<string, mixed>}>>
     */
    public function clinicalInput(): array
    {
        $validated = $this->validated();
        $input = [];
        foreach (['allergies', 'medical_conditions', 'medications', 'surgical_history', 'family_history'] as $field) {
            $input[$field] = collect($validated[$field] ?? [])->map(function ($entry) {
                if (is_string($entry)) {
                    $entry = trim($entry);

                    return preg_match('/^[a-z0-9_]{1,64}$/', $entry) ? ['key' => $entry, 'label' => $entry] : ['label' => $entry];
                }
                if (! is_array($entry)) {
                    return null;
                }
                $details = collect($entry['details'] ?? [])->only(['dose', 'frequency', 'notes', 'reaction'])
                    ->map(fn ($value) => is_scalar($value) ? mb_substr(trim((string) $value), 0, 160) : null)->filter()->all();

                return [
                    'key' => is_string($entry['key'] ?? null) ? $entry['key'] : null,
                    'label' => is_string($entry['label'] ?? null) ? $entry['label'] : null,
                    'severity' => is_string($entry['severity'] ?? null) ? $entry['severity'] : null,
                    'details' => $details ?: null,
                ];
            })->filter(fn ($entry) => $entry && (($entry['key'] ?? null) || trim((string) ($entry['label'] ?? '')) !== ''))->values()->all();
        }

        return $input;
    }

    /**
     * What duplicate detection compares.
     *
     * @return array{phone: ?string, alternate_phone: ?string, email: ?string, first_name: ?string, last_name: ?string, date_of_birth: ?string}
     */
    public function duplicateCriteria(): array
    {
        return [
            'phone' => $this->input('phone'),
            'alternate_phone' => $this->input('alternate_phone'),
            'email' => $this->input('email'),
            'first_name' => $this->input('first_name'),
            'last_name' => $this->input('last_name'),
            'date_of_birth' => $this->input('date_of_birth'),
        ];
    }
}
