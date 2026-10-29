<?php

namespace App\Services;

use App\ClinicRole;
use App\Models\Clinic;
use App\Models\ClinicSettingAudit;
use App\Models\User;
use App\Services\OwnerMode\OwnerMode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * How a clinic's patient-registration fields behave: required, optional or
 * hidden; their order; whether they appear in Reception's New Case dialog,
 * on the full patient page, or both; which specialties they apply to; and
 * which staff roles see and edit them. The one place both the forms and the
 * server validation (`RegisterPatientRequest`, `UpdatePatientRequest`) read,
 * so a setting can never be advisory only.
 *
 * `first_name` and `phone` are not configurable: identity and duplicate
 * detection depend on them, so they are always required.
 * `emergency_contact` governs the contact's name and phone. The address is
 * six settings — the line (`address`) and each part — and a part with no
 * setting of its own follows the address line's, as the whole group did
 * before the parts could be set one by one.
 *
 * Each field may carry the clinic's own label and help text; without them
 * the forms use their own wording. Templates (Dental, Physiotherapy,
 * Combined) set sensible starting values without overwriting a field the
 * clinic has already set (`applyTemplate`).
 *
 * Stored on `clinics.patient_field_config`, one entry per field. An entry
 * may be a bare state (the shape saved before this grew) or a definition;
 * both resolve the same way. Every change is audited.
 *
 * @phpstan-type FieldDefinition array{state: string, order: int, new_case: bool, full_page: bool, specialties: ?list<string>, visible_roles: ?list<string>, editable_roles: ?list<string>, label: ?string, help: ?string}
 */
class PatientFieldConfigService
{
    public const REQUIRED = 'required';

    public const OPTIONAL = 'optional';

    public const HIDDEN = 'hidden';

    public const STATES = [self::REQUIRED, self::OPTIONAL, self::HIDDEN];

    public const SURFACE_NEW_CASE = 'new_case';

    public const SURFACE_FULL_PAGE = 'full_page';

    public const AUDIT_SECTION = 'patient_fields';

    /** Always required; listed so callers can show them, never configurable. */
    public const SYSTEM_FIELDS = ['first_name', 'phone'];

    /**
     * Configurable field => its default state, matching this app's behaviour
     * before the setting existed: an unconfigured clinic sees no change.
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
        'medical_conditions' => self::OPTIONAL,
        'medications' => self::OPTIONAL,
        'doctor_id' => self::OPTIONAL,
        // Added later; listed last so the fields above keep their default order.
        'address_locality' => self::OPTIONAL,
        'address_city' => self::OPTIONAL,
        'address_state' => self::OPTIONAL,
        'address_pincode' => self::OPTIONAL,
        'address_country' => self::OPTIONAL,
        'surgical_history' => self::OPTIONAL,
        'family_history' => self::OPTIONAL,
    ];

    /** The address parts; each follows the address line's setting until given its own. */
    public const ADDRESS_PARTS = ['address_locality', 'address_city', 'address_state', 'address_pincode', 'address_country'];

    /** Default positions for the fields added later: beside the field they belong with. */
    private const DEFAULT_ORDER = [
        'address_locality' => 71, 'address_city' => 72, 'address_state' => 73, 'address_pincode' => 74, 'address_country' => 75,
        'surgical_history' => 111, 'family_history' => 112,
    ];

    /** Longest label and help text a clinic may set. */
    public const LABEL_MAX = 60;

    public const HELP_MAX = 160;

    /**
     * Starting points by what the clinic practises. Nothing medical is
     * required; optional extras stay on the full page; the popup keeps to
     * what the desk needs. Fields not listed keep their defaults.
     *
     * @var array<string, array{label: string, description: string, fields: array<string, array<string, mixed>>}>
     */
    public const TEMPLATES = [
        'dental' => [
            'label' => 'Dental',
            'description' => 'Identity on the popup, contact and address folded beneath it; blood group on the full page; allergies and conditions optional.',
            'fields' => [
                'last_name' => ['state' => self::REQUIRED], 'gender' => ['state' => self::REQUIRED],
                'date_of_birth' => ['state' => self::OPTIONAL, 'new_case' => true],
                'blood_group' => ['state' => self::OPTIONAL, 'new_case' => false, 'full_page' => true],
                'allergies' => ['state' => self::OPTIONAL, 'new_case' => true], 'medical_conditions' => ['state' => self::OPTIONAL, 'new_case' => true],
                'medications' => ['state' => self::OPTIONAL, 'new_case' => false], 'surgical_history' => ['state' => self::OPTIONAL, 'new_case' => false],
                'family_history' => ['state' => self::HIDDEN],
            ],
        ],
        'physio' => [
            'label' => 'Physiotherapy',
            'description' => 'Identity, conditions and past surgery on the popup, contact and address folded beneath; medications and family history on the full page.',
            'fields' => [
                'last_name' => ['state' => self::REQUIRED], 'gender' => ['state' => self::REQUIRED],
                'date_of_birth' => ['state' => self::OPTIONAL, 'new_case' => true],
                'blood_group' => ['state' => self::HIDDEN],
                'allergies' => ['state' => self::OPTIONAL, 'new_case' => true], 'medical_conditions' => ['state' => self::OPTIONAL, 'new_case' => true],
                'surgical_history' => ['state' => self::OPTIONAL, 'new_case' => true], 'medications' => ['state' => self::OPTIONAL, 'new_case' => false],
                'family_history' => ['state' => self::OPTIONAL, 'new_case' => false],
            ],
        ],
        'combined' => [
            'label' => 'Dental + Physiotherapy',
            'description' => 'Shared identity and safety for both; past surgery asked on the popup for Physiotherapy visits only.',
            'fields' => [
                'last_name' => ['state' => self::REQUIRED], 'gender' => ['state' => self::REQUIRED],
                'date_of_birth' => ['state' => self::OPTIONAL, 'new_case' => true],
                'blood_group' => ['state' => self::OPTIONAL, 'new_case' => false],
                'allergies' => ['state' => self::OPTIONAL, 'new_case' => true], 'medical_conditions' => ['state' => self::OPTIONAL, 'new_case' => true],
                'surgical_history' => ['state' => self::OPTIONAL, 'new_case' => true, 'specialties' => ['physiotherapy']],
                'medications' => ['state' => self::OPTIONAL, 'new_case' => false], 'family_history' => ['state' => self::OPTIONAL, 'new_case' => false],
            ],
        ],
    ];

    /**
     * Fields shown in the New Case dialog by default; required ones always
     * are. Contact details and the address are offered too, folded away in
     * the dialog's Contact details section until opened (or required).
     */
    private const NEW_CASE_DEFAULTS = ['last_name', 'date_of_birth', 'gender', 'allergies', 'medical_conditions', 'doctor_id', 'alternate_phone', 'email', 'address', 'emergency_contact'];

    /**
     * The state of every configurable field (the shape the forms and older
     * callers read).
     *
     * @return array<string, string>
     */
    public function resolve(?Clinic $clinic): array
    {
        return array_map(fn (array $definition) => $definition['state'], $this->definitions($clinic));
    }

    /**
     * Every configurable field's full definition, in display order.
     *
     * @return array<string, FieldDefinition>
     */
    public function definitions(?Clinic $clinic): array
    {
        $stored = $clinic?->patient_field_config ?? [];
        $definitions = [];
        foreach (array_keys(self::CONFIGURABLE_FIELDS) as $field) {
            $definitions[$field] = $this->normalise($field, $stored[$field] ?? null);
        }
        // A part never set on its own follows the address line, as the group did before (never required by inheritance).
        foreach (self::ADDRESS_PARTS as $part) {
            if (! array_key_exists($part, $stored)) {
                $line = $definitions['address'];
                $definitions[$part] = [
                    ...$definitions[$part],
                    ...collect($line)->only(['new_case', 'full_page', 'specialties', 'visible_roles', 'editable_roles'])->all(),
                    'state' => $line['state'] === self::HIDDEN ? self::HIDDEN : self::OPTIONAL,
                ];
            }
        }
        uasort($definitions, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $definitions;
    }

    /**
     * What one member sees and may change, for one surface and specialty:
     * field => {state, order, editable}. A field the member's role may not
     * see, that does not apply to the specialty, or that this surface leaves
     * out (and is not required) is hidden; the server then ignores it.
     *
     * @return array<string, array{state: string, order: int, editable: bool, label: ?string, help: ?string}>
     */
    public function effective(?Clinic $clinic, ?User $user, ?string $specialtyKey = null, string $surface = self::SURFACE_FULL_PAGE): array
    {
        $effective = [];
        foreach ($this->definitions($clinic) as $field => $definition) {
            $state = $definition['state'];
            $applies = $specialtyKey === null || $definition['specialties'] === null || in_array($specialtyKey, $definition['specialties'], true);
            $visible = $this->roleAllowed($user, $definition['visible_roles']);
            $onSurface = $surface === self::SURFACE_NEW_CASE ? $definition['new_case'] : $definition['full_page'];

            if (! $applies || ! $visible || (! $onSurface && $state !== self::REQUIRED)) {
                $state = self::HIDDEN;
            }

            $effective[$field] = [
                'state' => $state,
                'order' => $definition['order'],
                'editable' => $state !== self::HIDDEN && $this->roleAllowed($user, $definition['editable_roles']),
                'label' => $definition['label'],
                'help' => $definition['help'],
            ];
        }

        return $effective;
    }

    /**
     * Change some fields' settings; fields left out keep theirs. Each value
     * is a bare state or a (partial) definition. Audited.
     *
     * @param  array<string, string|array<string, mixed>>  $config
     */
    public function update(Clinic $clinic, array $config, ?User $actor = null): void
    {
        $before = $this->definitions($clinic);
        $after = $before;
        foreach ($config as $field => $value) {
            if (! array_key_exists($field, self::CONFIGURABLE_FIELDS)) {
                continue; // System fields and unknown names are never stored.
            }
            $after[$field] = $this->normalise($field, is_array($value) ? [...$before[$field], ...$value] : [...$before[$field], 'state' => $value]);
        }

        if ($after === $before) {
            return;
        }

        // Stored: what was set before or is set now. Anything else stays
        // unstored, so it keeps its default — an address part keeps following
        // the address line until it is given a setting of its own.
        $stored = $clinic->patient_field_config ?? [];
        $clinic->update(['patient_field_config' => collect($after)
            ->filter(fn (array $definition, string $field) => array_key_exists($field, $stored) || array_key_exists($field, $config))
            ->all()]);
        $after = $this->definitions($clinic);

        ClinicSettingAudit::create([
            'clinic_id' => $clinic->id,
            'user_id' => $actor?->id,
            'section' => self::AUDIT_SECTION,
            'old_values' => $before,
            'new_values' => $after,
        ]);
    }

    /**
     * @return FieldDefinition
     */
    private function normalise(string $field, mixed $stored): array
    {
        $index = array_search($field, array_keys(self::CONFIGURABLE_FIELDS), true);
        $default = [
            'state' => self::CONFIGURABLE_FIELDS[$field],
            'order' => self::DEFAULT_ORDER[$field] ?? ((int) $index + 1) * 10,
            'new_case' => in_array($field, self::NEW_CASE_DEFAULTS, true),
            'full_page' => true,
            'specialties' => null,
            'visible_roles' => null,
            'editable_roles' => null,
            'label' => null,
            'help' => null,
        ];
        $stored = is_string($stored) ? ['state' => $stored] : (is_array($stored) ? $stored : []);

        $definition = [
            'state' => in_array($stored['state'] ?? null, self::STATES, true) ? $stored['state'] : $default['state'],
            'order' => isset($stored['order']) && is_numeric($stored['order']) ? max(0, min(9999, (int) $stored['order'])) : $default['order'],
            'new_case' => isset($stored['new_case']) ? (bool) $stored['new_case'] : $default['new_case'],
            'full_page' => isset($stored['full_page']) ? (bool) $stored['full_page'] : $default['full_page'],
            'specialties' => $this->keyList($stored['specialties'] ?? null),
            'visible_roles' => $this->roleList($stored['visible_roles'] ?? null),
            'editable_roles' => $this->roleList($stored['editable_roles'] ?? null),
            'label' => $this->text($stored['label'] ?? null, self::LABEL_MAX),
            'help' => $this->text($stored['help'] ?? null, self::HELP_MAX),
        ];

        // A required field must be reachable wherever a patient is registered.
        if ($definition['state'] === self::REQUIRED) {
            $definition['new_case'] = true;
            $definition['full_page'] = true;
        }

        return $definition;
    }

    /**
     * The fields this clinic has set to something other than the default —
     * what a template must not overwrite unasked.
     *
     * @return list<string>
     */
    public function customised(?Clinic $clinic): array
    {
        $defaults = $this->definitions(null);
        $current = $this->definitions($clinic);

        return array_values(array_filter(array_keys($current), fn (string $field) => $current[$field] != $defaults[$field]));
    }

    /** The clinic's own wording, trimmed and bounded; empty means "use the form's". */
    private function text(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim(strip_tags($value)) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /**
     * Apply a starting template. A field the clinic has already set keeps its
     * setting unless `$replaceCustomised` is true (the owner asked to reset
     * it); every other field in the template is set. One locked, audited change.
     *
     * @return array{applied: list<string>, kept: list<string>}
     */
    public function applyTemplate(Clinic $clinic, string $template, ?User $actor, bool $replaceCustomised = false): array
    {
        $fields = self::TEMPLATES[$template]['fields'] ?? throw new InvalidArgumentException("Unknown template “{$template}”.");

        return DB::transaction(function () use ($clinic, $template, $fields, $actor, $replaceCustomised) {
            $locked = Clinic::query()->withoutGlobalScopes()->whereKey($clinic->id)->lockForUpdate()->firstOrFail();
            $customised = $this->customised($locked);
            $changes = [];
            $kept = [];
            foreach ($fields as $field => $setting) {
                if (! $replaceCustomised && in_array($field, $customised, true)) {
                    $kept[] = $field;

                    continue;
                }
                $changes[$field] = $setting;
            }
            $this->update($locked, $changes, $actor);
            ClinicSettingAudit::create([
                'clinic_id' => $locked->id,
                'user_id' => $actor?->id,
                'section' => self::AUDIT_SECTION,
                'old_values' => ['template' => null],
                'new_values' => ['template' => $template, 'applied' => array_keys($changes), 'kept' => $kept, 'replace_customised' => $replaceCustomised],
            ]);
            $clinic->setRawAttributes($locked->getAttributes(), true);

            return ['applied' => array_keys($changes), 'kept' => $kept];
        });
    }

    /**
     * @return list<string>|null
     */
    private function keyList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $keys = array_values(array_unique(array_filter($value, fn ($key) => is_string($key) && preg_match('/^[a-z0-9_]{1,64}$/', $key))));

        return $keys === [] ? null : $keys;
    }

    /**
     * @return list<string>|null
     */
    private function roleList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $roles = array_values(array_intersect(ClinicRole::values(), $value));

        return $roles === [] ? null : $roles;
    }

    /**
     * @param  list<string>|null  $roles
     */
    public function roleAllowed(?User $user, ?array $roles): bool
    {
        if ($roles === null) {
            return true;
        }
        if (! $user) {
            return false;
        }
        if ($user->isMasterAdmin() || app(OwnerMode::class)->authorized()) {
            return true;
        }

        try {
            return $user->hasAnyRole($roles);
        } catch (\Throwable) {
            return false;
        }
    }
}
