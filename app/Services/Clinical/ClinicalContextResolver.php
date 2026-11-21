<?php

namespace App\Services\Clinical;

use App\EmploymentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Reception\DoctorRoster;
use App\Support\Specialty\PracticeAreas;
use App\Support\Specialty\SpecialtyRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Works out a visit's clinical context — practitioner → module → practice
 * area → specialty — so reception picks a doctor, never a specialty tree.
 *
 * Inputs are taken in a fixed order, the first that names a practitioner
 * deciding (see `ClinicalContext` for the sources):
 *
 *  1. the practitioner chosen or preselected in this request
 *  2. the appointment's practitioner
 *  3. the queue entry's practitioner
 *  4. the patient's assigned practitioner, if working here today
 *  5. the signed-in user, if a practitioner here
 *  6. the branch, when it practises exactly one specialty
 *  7. the specialty the user chose, when no practitioner is known
 *  8. nothing
 *
 * Every id is looked up inside the clinic. A practitioner who is named
 * explicitly (1–3) and cannot take the visit — another clinic's, inactive,
 * not at this branch, on leave, or practising nothing this clinic's plan and
 * set-up allow — is refused, never swapped for someone else. Sources 4–5 are
 * defaults: one that does not qualify is skipped. When nothing decides and
 * the branch practises several specialties, `require` refuses rather than
 * guessing.
 */
class ClinicalContextResolver
{
    public function __construct(
        protected SpecialtyRegistry $registry,
        protected PracticeAreas $areas,
        protected ClinicSpecialties $clinicSpecialties,
        protected DoctorRoster $roster,
    ) {}

    /**
     * @param  array{practitioner?: int|string|null, appointment?: ?Appointment, queue_item?: ?ClinicQueueItem, patient?: ?Patient, user?: ?User, specialty?: ?string, area?: ?string, require?: bool, field?: string, allow_on_leave?: bool}  $sources
     */
    public function resolve(Clinic $clinic, ?Branch $branch, array $sources): ClinicalContext
    {
        $field = $sources['field'] ?? 'practitioner_id';
        $specialty = ($sources['specialty'] ?? null) ?: null;
        $area = ($sources['area'] ?? null) ?: null;
        $roster = $branch ? $this->roster->forBranch($clinic, $branch)->keyBy(fn (array $entry) => $entry['employee']->id) : null;
        $allowOnLeave = (bool) ($sources['allow_on_leave'] ?? false);

        $explicit = [
            ClinicalContext::SELECTED => ($sources['practitioner'] ?? null) ?: null,
            ClinicalContext::APPOINTMENT => $sources['appointment']?->practitioner_id ?? null,
            ClinicalContext::QUEUE => $sources['queue_item']?->doctor_id ?? null,
        ];
        foreach ($explicit as $source => $id) {
            if ($id === null) {
                continue;
            }
            // Another clinic's id is not found, so its existence is not confirmed.
            $employee = $this->employee($clinic, (int) $id)
                ?? throw (new ModelNotFoundException)->setModel(ClinicEmployee::class, [(int) $id]);
            if ($problem = $this->ineligible($employee, $branch, $roster, $allowOnLeave || $source === ClinicalContext::QUEUE)) {
                throw ValidationException::withMessages([$field => $problem]);
            }

            return $this->fromPractitioner($clinic, $branch, $employee, $source, $specialty, $area, $field);
        }

        $defaults = [
            ClinicalContext::ASSIGNED => $sources['patient']?->current_practitioner_id ?? null,
            ClinicalContext::CURRENT_PRACTITIONER => isset($sources['user']) ? $this->employeeForUser($clinic, $sources['user']) : null,
        ];
        foreach ($defaults as $source => $id) {
            $employee = $id ? $this->employee($clinic, (int) $id) : null;
            // A default must be working now, not just placed here.
            $shift = $employee !== null ? ($roster?->get($employee->id)['shift'] ?? null) : null;
            if ($employee === null || $this->ineligible($employee, $branch, $roster, false) !== null || ($roster !== null && ! in_array($shift, ['on_shift', 'not_set'], true))) {
                continue;
            }
            try {
                return $this->fromPractitioner($clinic, $branch, $employee, $source, $specialty, $area, $field);
            } catch (ValidationException) {
                // A default that cannot take this visit is passed over, not an error.
            }
        }

        $practised = $this->practised($clinic, $branch);

        if ($specialty !== null) {
            $key = $this->registry->normalize([$specialty])[0] ?? null;
            $row = $key !== null ? $this->clinicSpecialties->active($clinic, $key) : null;
            if ($row === null || ($practised !== null && ! in_array($key, $practised, true))) {
                throw ValidationException::withMessages(['visit_specialty' => 'That specialty is not offered at this branch.']);
            }

            return new ClinicalContext(ClinicalContext::SPECIALTY_SELECTION, null, $this->areas->moduleOfSpecialty($key), $key, $row->id);
        }

        if ($practised !== null && count($practised) === 1) {
            return $this->forSpecialty(ClinicalContext::BRANCH_DEFAULT, $clinic, $practised[0]);
        }

        if (($sources['require'] ?? false) && $practised !== null && count($practised) > 1) {
            throw ValidationException::withMessages([$field => 'Choose the doctor, or what this visit is for.']);
        }

        return ClinicalContext::none();
    }

    /**
     * Today's practitioners at a branch, as reception's picker shows them:
     * name, qualifications, practice area and module, and every context
     * they can take here. Practitioners who can take none are left out.
     *
     * @return list<array{id: int, name: string, credentials: string|null, area: array{key: string, label: string}|null, module: array{key: string, label: string}|null, shift: string, shift_label: string, contexts: list<array{area: string|null, area_label: string|null, specialty: string|null, module: string|null, module_label: string|null}>}>
     */
    public function practitionerOptions(Clinic $clinic, Branch $branch): array
    {
        // Off shift is listed, with when they are back (`shift_label`), because a patient can arrive early or late and an empty picker
        // with no reason looks like a fault; only a doctor on leave is left out. Working now comes first.
        $order = ['on_shift' => 0, 'not_set' => 1, 'off_shift' => 2];
        $entries = $this->roster->forBranch($clinic, $branch)
            ->filter(fn (array $entry) => isset($order[$entry['shift']]))
            ->sortBy(fn (array $entry) => $order[$entry['shift']])
            ->values();
        $this->loadProfiles($entries->map(fn (array $entry) => $entry['employee']->practitionerProfile)->filter());
        $practised = $this->practised($clinic, $branch);

        return $entries->map(function (array $entry) use ($clinic, $practised) {
            /** @var ClinicEmployee $employee */
            $employee = $entry['employee'];
            $profile = $employee->practitionerProfile;
            $contexts = $this->contextsFor($clinic, $profile, $practised);
            if ($contexts === []) {
                return null;
            }
            $primary = $contexts[0];

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'credentials' => $profile->credentials(),
                'area' => $primary['area'] ? ['key' => $primary['area'], 'label' => $primary['area_label']] : null,
                'module' => $primary['module'] ? ['key' => $primary['module'], 'label' => $primary['module_label']] : null,
                'shift' => $entry['shift'],
                'shift_label' => $entry['shift_label'],
                'room' => $entry['room'],
                // Billed once: at check-in by `CheckInCharges` when the clinic says so, otherwise by `EncounterCheckout` after the visit.
                'fee' => $profile->consultation_fee !== null && (float) $profile->consultation_fee > 0 ? (float) $profile->consultation_fee : null,
                'contexts' => $contexts,
            ];
        })->filter()->values()->all();
    }

    /**
     * The registry specialties a branch practises, within the clinic's active,
     * entitled set; null when the clinic declares no specialty at all (an
     * unconfigured clinic on an unrestricted plan), so nothing narrows it.
     *
     * @return list<string>|null
     */
    public function practised(Clinic $clinic, ?Branch $branch): ?array
    {
        $active = $this->clinicSpecialties->activeKeys($clinic);
        $declared = $active !== []
            || $this->registry->normalize((array) ($clinic->specialties ?? [])) !== []
            || ($branch && $this->registry->normalize((array) ($branch->specialties ?? [])) !== []);
        if (! $declared) {
            return null;
        }

        $keys = $branch ? $this->registry->forBranch($branch, $clinic) : $this->registry->forClinic($clinic);

        return $active === [] ? $keys : array_values(array_intersect($keys, $active));
    }

    private function fromPractitioner(Clinic $clinic, ?Branch $branch, ClinicEmployee $employee, string $source, ?string $specialty, ?string $area, string $field): ClinicalContext
    {
        $profile = $employee->practitionerProfile;
        $practised = $this->practised($clinic, $branch);
        $contexts = $this->contextsFor($clinic, $profile, $practised);
        $requested = $specialty !== null ? ($this->registry->normalize([$specialty])[0] ?? $specialty) : null;

        $chosen = match (true) {
            $area !== null => collect($contexts)->firstWhere('area', $area)
                ?? throw ValidationException::withMessages([$field => "{$employee->name} does not practise that here."]),
            $requested !== null => collect($contexts)->first(fn (array $context) => $context['area'] !== null
                && in_array($requested, $this->areas->specialtiesFor($context['area']), true)
                && ($practised === null || in_array($requested, $practised, true)))
                ?? collect($contexts)->first(fn (array $context) => $context['area'] === null && $context['specialty'] === $requested)
                ?? throw ValidationException::withMessages([$field => "{$employee->name} does not practise ".($this->registry->get($requested)['label'] ?? $requested).'. Choose another doctor or change what the visit is for.']),
            default => $contexts[0] ?? null,
        };

        if ($chosen === null) {
            $offered = collect($profile->practiceAreaKeys())->map(fn (string $key) => $this->areas->get($key)['label'] ?? $key)->implode(', ');
            throw ValidationException::withMessages([$field => $offered !== ''
                ? "{$employee->name} practises {$offered}, which ".($branch ? 'this branch' : 'this clinic').' does not offer.'
                : "{$employee->name} has no specialty recorded. Set it on their staff profile first."]);
        }

        // A chosen area decides the specialty; a different one asked for alongside it is refused, never recorded against that area.
        if ($area !== null && $requested !== null && ! in_array($requested, $this->areas->specialtiesFor($area), true) && $requested !== $chosen['specialty']) {
            throw ValidationException::withMessages([$field => ($this->areas->get($area)['label'] ?? $area).' is not part of '.($this->registry->get($requested)['label'] ?? $requested).'. Choose what the visit is for again.']);
        }
        $key = $requested ?? $chosen['specialty'];

        return new ClinicalContext(
            $source,
            $employee,
            $chosen['module'] ?? $this->areas->moduleOfSpecialty($key),
            $key,
            $key !== null ? $this->clinicSpecialties->active($clinic, $key)?->id : null,
            $chosen['area'],
        );
    }

    /**
     * Each practice area the profile can take here, with the specialty it is
     * recorded under (the area's own when practised, else its module's
     * root). A profile with no area at a single-specialty branch takes that
     * specialty; at an unconfigured clinic it takes a context with no
     * specialty.
     *
     * @param  list<string>|null  $practised
     * @return list<array{area: string|null, area_label: string|null, specialty: string|null, module: string|null, module_label: string|null}>
     */
    private function contextsFor(Clinic $clinic, ?Practitioner $profile, ?array $practised): array
    {
        if ($profile === null || ! $profile->is_active) {
            return [];
        }

        $contexts = [];
        $areaKeys = $profile->practiceAreaKeys();
        foreach ($areaKeys as $areaKey) {
            $definition = $this->areas->get($areaKey);
            foreach ($this->areas->specialtiesFor($areaKey) as $candidate) {
                if ($practised === null || in_array($candidate, $practised, true)) {
                    $contexts[] = [
                        'area' => $areaKey,
                        'area_label' => $definition['label'],
                        'specialty' => $candidate,
                        'module' => $definition['module'],
                        'module_label' => $this->areas->moduleLabel($definition['module']),
                    ];
                    break;
                }
            }
        }

        if ($contexts === [] && $areaKeys === []) {
            if ($practised !== null && count($practised) === 1) {
                $module = $this->areas->moduleOfSpecialty($practised[0]);

                return [['area' => null, 'area_label' => null, 'specialty' => $practised[0], 'module' => $module, 'module_label' => $this->areas->moduleLabel($module)]];
            }
            if ($practised === null) {
                return [['area' => null, 'area_label' => null, 'specialty' => null, 'module' => null, 'module_label' => null]];
            }
        }

        return $contexts;
    }

    private function forSpecialty(string $source, Clinic $clinic, string $key): ClinicalContext
    {
        return new ClinicalContext($source, null, $this->areas->moduleOfSpecialty($key), $key, $this->clinicSpecialties->active($clinic, $key)?->id);
    }

    /**
     * Why this practitioner cannot take a visit here, or null when they can.
     *
     * @param  Collection<int, array{employee: ClinicEmployee, shift: string}>|null  $roster
     */
    private function ineligible(ClinicEmployee $employee, ?Branch $branch, ?Collection $roster, bool $allowOnLeave): ?string
    {
        $profile = $employee->practitionerProfile;

        return match (true) {
            $employee->status !== EmploymentStatus::Active => "{$employee->name} is not active at this clinic.",
            $profile === null || ! $profile->is_active => "{$employee->name} is not set up as a practitioner.",
            $roster !== null && ! $roster->has($employee->id) => "{$employee->name} does not see patients at {$branch->name}.",
            $roster !== null && ! $allowOnLeave && $roster->get($employee->id)['shift'] === 'on_leave' => "{$employee->name} is on leave today.",
            default => null,
        };
    }

    private function employee(Clinic $clinic, int $id): ?ClinicEmployee
    {
        $employee = ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereKey($id)
            ->with(['practitionerProfile' => fn ($query) => $query->withoutGlobalScopes()])->first();
        if ($employee?->practitionerProfile) {
            $this->loadProfiles(collect([$employee->practitionerProfile]));
        }

        return $employee;
    }

    private function employeeForUser(Clinic $clinic, User $user): ?int
    {
        $id = ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('user_id', $user->id)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * What `practiceAreaKeys()` and `credentials()` read, for many profiles
     * in a few queries.
     *
     * @param  Collection<int, Practitioner>  $profiles
     */
    public function loadProfiles(Collection $profiles): void
    {
        if ($profiles->isEmpty()) {
            return;
        }

        EloquentCollection::make($profiles->all())->load([
            'primarySpecialty',
            'specialties' => fn ($query) => $query->withoutGlobalScopes(),
            'qualificationRecords' => fn ($query) => $query->withoutGlobalScopes(),
        ]);
    }
}
