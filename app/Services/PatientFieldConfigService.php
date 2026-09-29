<?php

namespace App\Services;

use App\Models\Clinic;

/**
 * Which patient-registration fields a clinic admin has made required,
 * optional, or hidden — and the one place both the frontend (what
 * `Create.tsx` renders) and the backend (`RegisterPatientRequest::rules()`)
 * read that from, so the two can never disagree about what a clinic
 * actually requires.
 *
 * `first_name` and `phone` are deliberately absent from the configurable
 * set — they stay hard-required in the request regardless of clinic
 * settings, matching the explicit "only these two are non-negotiable" ask.
 * `address` and `emergency_contact` are each one entry here even though
 * they render as several inputs (`address`/`city`/`state`/`pincode`,
 * `emergency_contact_name`/`_phone`) — a clinic configures visibility for
 * the group, not each of its fields individually.
 */
class PatientFieldConfigService
{
    public const REQUIRED = 'required';

    public const OPTIONAL = 'optional';

    public const HIDDEN = 'hidden';

    public const STATES = [self::REQUIRED, self::OPTIONAL, self::HIDDEN];

    /**
     * Configurable field => its default state, chosen to match this app's
     * behaviour before this setting existed — an unconfigured clinic sees
     * no change at all.
     *
     * @var array<string, string>
     */
    public const CONFIGURABLE_FIELDS = [
        'last_name' => self::REQUIRED,
        'date_of_birth' => self::OPTIONAL,
        'gender' => self::REQUIRED,
        'blood_group' => self::OPTIONAL,
        'alternate_phone' => self::OPTIONAL,
        'email' => self::OPTIONAL,
        'address' => self::OPTIONAL,
        'emergency_contact' => self::OPTIONAL,
        'allergies' => self::OPTIONAL,
        'doctor_id' => self::OPTIONAL,
    ];

    /**
     * The resolved state for every configurable field — a clinic's stored
     * overrides layered onto the defaults, with anything invalid or
     * unrecognised (a field renamed since a clinic saved its config,
     * a stray value) falling back to the default rather than breaking
     * the form.
     *
     * @return array<string, string>
     */
    public function resolve(?Clinic $clinic): array
    {
        $stored = $clinic?->patient_field_config ?? [];

        $resolved = [];
        foreach (self::CONFIGURABLE_FIELDS as $field => $default) {
            $value = $stored[$field] ?? $default;
            $resolved[$field] = in_array($value, self::STATES, true) ? $value : $default;
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function update(Clinic $clinic, array $config): void
    {
        $sanitized = [];
        foreach (self::CONFIGURABLE_FIELDS as $field => $default) {
            $value = $config[$field] ?? $default;
            $sanitized[$field] = in_array($value, self::STATES, true) ? $value : $default;
        }

        $clinic->update(['patient_field_config' => $sanitized]);
    }
}
