<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Practitioner;
use App\Models\PractitionerQualification;
use App\Models\StaffDocument;
use App\Services\ClinicContext;
use App\Services\Security\Malware\ScannedUploadIntake;
use App\Services\Security\Malware\UploadRejectedException;
use App\Services\Security\SecurityAudit;
use App\Services\Staff\PractitionerScheduleEditor;
use App\Services\Staff\StaffDirectory;
use App\Support\Specialty\PracticeAreas;
use App\Support\Specialty\SpecialtyEntitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The sections of a staff profile other than the core record: photo,
 * branch placements, and for practitioners their professional details,
 * qualifications, services and weekly schedule.
 *
 * Every action checks that the staff record is in the active clinic and
 * visible to the acting member; branch changes only touch branches the
 * member can reach.
 */
class StaffProfileController extends Controller
{
    public function __construct(
        protected ClinicContext $context,
        protected StaffDirectory $directory,
        protected SecurityAudit $audit,
    ) {}

    public function photo(ClinicEmployee $employee): StreamedResponse
    {
        $this->authorizeStaff($employee);
        abort_unless($employee->photo_path && Storage::disk('private')->exists($employee->photo_path), 404);

        return Storage::disk('private')->response($employee->photo_path, 'photo', ['Cache-Control' => 'private, max-age=300']);
    }

    public function uploadPhoto(Request $request, ClinicEmployee $employee, ScannedUploadIntake $intake): RedirectResponse
    {
        $this->authorizeStaff($employee);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);

        try {
            $admitted = $intake->admit($request->file('photo'), "clinic_{$employee->clinic_id}/staff/{$employee->id}/photo");
        } catch (UploadRejectedException $e) {
            return back()->withErrors(['photo' => $e->getMessage()]);
        }

        $employee->update(['photo_path' => $admitted['path']]);
        $this->audit->record('staff.photo_updated', $employee);

        return back()->with('success', 'Photo updated.');
    }

    /**
     * Branch placements: which branches, which is primary, and which are
     * paused. A paused placement keeps the schedule but is not bookable.
     */
    public function branches(Request $request, ClinicEmployee $employee): RedirectResponse
    {
        $this->authorizeStaff($employee);
        $reachable = $this->directory->branches($this->context->clinic(), $this->context->membership())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $validated = $request->validate([
            'placements' => ['present', 'array'],
            'placements.*.branch_id' => ['required', 'integer', 'distinct', Rule::in($reachable)],
            'placements.*.is_active' => ['required', 'boolean'],
            'primary_branch_id' => ['nullable', 'integer'],
        ], ['placements.*.branch_id.in' => 'You can only place staff at branches you work at.']);

        $requested = collect($validated['placements'])->mapWithKeys(fn (array $row) => [(int) $row['branch_id'] => ['is_active' => (bool) $row['is_active']]]);
        $kept = $employee->branches()->get()
            ->reject(fn (Branch $branch) => in_array((int) $branch->id, $reachable, true))
            ->mapWithKeys(fn (Branch $branch) => [(int) $branch->id => ['is_active' => (bool) $branch->pivot->is_active]]);
        $all = $requested->union($kept);

        if ($all->isEmpty() && $employee->signsInAtPlacedBranches()) {
            throw ValidationException::withMessages(['placements' => 'Someone who signs in needs at least one branch. Add a branch, or deactivate them instead.']);
        }

        $primary = isset($validated['primary_branch_id']) ? (int) $validated['primary_branch_id'] : null;
        if ($primary && ! $all->has($primary)) {
            throw ValidationException::withMessages(['primary_branch_id' => 'The primary branch must be one of the staff member\'s branches.']);
        }

        DB::transaction(function () use ($employee, $all, $primary, $request) {
            $employee->branches()->sync($all->all());
            $employee->update(['primary_branch_id' => $primary ?? ($all->count() === 1 ? $all->keys()->first() : null)]);

            $membership = $employee->user_id
                ? ClinicMembership::query()->where('clinic_id', $employee->clinic_id)->where('user_id', $employee->user_id)->first()
                : null;
            if ($membership && ! $membership->has_all_branches_access) {
                $membership->syncAssignedBranches($employee->branches()->get(), $request->user()?->id);
            }
        });

        $this->audit->record('staff.branches_updated', $employee, ['branches' => $all->keys()->all()]);

        return back()->with('success', 'Branches saved.');
    }

    /**
     * Profession, specialties, registration and the short professional note.
     */
    public function professional(Request $request, ClinicEmployee $employee): RedirectResponse
    {
        $profile = $this->practitionerOf($employee);
        $organizationId = $this->context->clinic()->organization_id;

        $validated = $request->validate([
            'profession' => ['nullable', 'string', 'max:80'],
            'primary_specialty' => ['nullable', 'array'],
            'primary_specialty.id' => ['nullable', 'integer'],
            'primary_specialty.name' => ['nullable', 'string', 'max:120'],
            'primary_specialty.area' => ['nullable', 'string', 'max:60'],
            'additional_specialties' => ['array', 'max:10'],
            'additional_specialties.*.id' => ['nullable', 'integer'],
            'additional_specialties.*.name' => ['nullable', 'string', 'max:120'],
            'additional_specialties.*.area' => ['nullable', 'string', 'max:60'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:70'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'registration_number' => ['nullable', 'string', 'max:80'],
            'registration_authority' => ['nullable', 'string', 'max:150'],
            'registration_state' => ['nullable', 'string', 'max:80'],
            'registration_type' => ['nullable', Rule::in(array_keys(config('clinic_staff.registration_types')))],
            'registration_date' => ['nullable', 'date'],
            'registration_expires_at' => ['nullable', 'date', 'after_or_equal:registration_date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($profile, $validated, $organizationId) {
            $primary = $this->specialty($organizationId, $validated['primary_specialty'] ?? null);
            $additional = collect($validated['additional_specialties'] ?? [])
                ->map(fn (array $entry) => $this->specialty($organizationId, $entry))
                ->filter()
                ->reject(fn (ClinicalSpecialty $specialty) => $primary && $specialty->id === $primary->id)
                ->unique('id');

            $profile->update([
                'profession' => $validated['profession'] ?? null,
                'primary_specialty_id' => $primary?->id,
                'specialty' => $primary?->name,
                'experience_years' => $validated['experience_years'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'registration_number' => $validated['registration_number'] ?? null,
                'registration_authority' => $validated['registration_authority'] ?? null,
                'registration_state' => $validated['registration_state'] ?? null,
                'registration_type' => $validated['registration_type'] ?? null,
                'registration_date' => $validated['registration_date'] ?? null,
                'registration_expires_at' => $validated['registration_expires_at'] ?? null,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $profile->is_active,
            ]);
            $profile->specialties()->sync($additional->pluck('id')->all());
        });

        $this->audit->record('practitioner.profile_updated', $employee, ['practice_areas' => $profile->fresh()->practiceAreaKeys()]);

        return back()->with('success', 'Professional details saved.');
    }

    public function storeQualification(Request $request, ClinicEmployee $employee): RedirectResponse
    {
        $profile = $this->practitionerOf($employee);
        $validated = $request->validate($this->qualificationRules($employee));

        PractitionerQualification::create($validated + [
            'clinic_id' => $employee->clinic_id,
            'doctor_profile_id' => $profile->id,
            'position' => (int) $profile->qualificationRecords()->max('position') + 1,
        ]);
        $this->audit->record('practitioner.qualification_added', $employee, ['qualification' => $validated['qualification']]);

        return back()->with('success', 'Qualification added.');
    }

    public function updateQualification(Request $request, ClinicEmployee $employee, PractitionerQualification $qualification): RedirectResponse
    {
        $profile = $this->practitionerOf($employee);
        abort_unless((int) $qualification->doctor_profile_id === (int) $profile->id, 404);

        $qualification->update($request->validate($this->qualificationRules($employee) + [
            'verification_status' => ['nullable', Rule::in(PractitionerQualification::VERIFICATION)],
        ]));
        $this->audit->record('practitioner.qualification_updated', $employee, ['qualification_id' => $qualification->id]);

        return back()->with('success', 'Qualification saved.');
    }

    public function destroyQualification(ClinicEmployee $employee, PractitionerQualification $qualification): RedirectResponse
    {
        $profile = $this->practitionerOf($employee);
        abort_unless((int) $qualification->doctor_profile_id === (int) $profile->id, 404);

        $qualification->delete();
        $this->audit->record('practitioner.qualification_removed', $employee, ['qualification_id' => $qualification->id]);

        return back()->with('success', 'Qualification removed.');
    }

    /**
     * The services this practitioner provides. An empty list means not
     * restricted: they stay bookable for every service.
     */
    public function services(Request $request, ClinicEmployee $employee): RedirectResponse
    {
        $this->practitionerOf($employee);
        $clinic = $this->context->clinic();

        $validated = $request->validate([
            'services' => ['present', 'array'],
            'services.*' => ['integer', 'distinct', Rule::exists('clinical_procedures', 'id')->where('clinic_id', $clinic->id)],
        ]);

        $employee->services()->sync(collect($validated['services'])->mapWithKeys(fn ($id) => [(int) $id => ['clinic_id' => $clinic->id]])->all());
        $this->audit->record('practitioner.services_updated', $employee, ['services' => count($validated['services'])]);

        return back()->with('success', count($validated['services']) === 0 ? 'Services cleared: bookable for every service.' : 'Services saved.');
    }

    /**
     * The weekly working hours at one branch: per weekday, a list of shifts.
     */
    public function schedule(Request $request, ClinicEmployee $employee, PractitionerScheduleEditor $editor): RedirectResponse
    {
        $this->practitionerOf($employee);
        $reachable = $this->directory->branches($this->context->clinic(), $this->context->membership())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $placed = $employee->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();

        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in(array_values(array_intersect($reachable, $placed ?: $reachable)))],
            'days' => ['present', 'array'],
            'days.*' => ['array', 'max:6'],
            'days.*.*.start' => ['required', 'date_format:H:i'],
            'days.*.*.end' => ['required', 'date_format:H:i'],
        ], ['branch_id.in' => 'Choose one of this practitioner\'s branches that you work at.']);

        $branch = Branch::withoutGlobalScopes()->where('clinic_id', $employee->clinic_id)->findOrFail($validated['branch_id']);
        $editor->save($employee, $branch, $validated['days']);

        return back()->with('success', "Schedule at {$branch->name} saved.");
    }

    private function authorizeStaff(ClinicEmployee $employee): void
    {
        abort_unless($this->directory->canSee($employee, $this->context->clinic(), $this->context->membership()), 403);
    }

    private function practitionerOf(ClinicEmployee $employee): Practitioner
    {
        $this->authorizeStaff($employee);

        return Practitioner::withoutGlobalScopes()->where('clinic_employee_id', $employee->id)->first()
            ?? throw ValidationException::withMessages(['employee' => 'This staff member has no practitioner profile.']);
    }

    /**
     * A catalogue specialty by id, or the organisation's entry for a new or
     * suggested name, created on first use.
     *
     * @param  array{id?: int|null, name?: string|null}|null  $entry
     */
    /**
     * The catalogue entry for a chosen specialty: a registry practice area
     * (by key, or by a name that means one) is one entry per organisation,
     * keyed, and only for a module the clinic's plan sells; anything else is
     * the clinic's own informational entry.
     */
    private function specialty(int $organizationId, ?array $entry): ?ClinicalSpecialty
    {
        if (! $entry) {
            return null;
        }

        $areas = app(PracticeAreas::class);
        $existing = ! empty($entry['id'])
            ? (ClinicalSpecialty::withoutGlobalScopes()->where('organization_id', $organizationId)->find($entry['id'])
                ?? throw ValidationException::withMessages(['primary_specialty' => 'Choose a specialty from this clinic\'s list.']))
            : null;

        $name = trim((string) ($entry['name'] ?? $existing?->name ?? ''));
        $area = ($entry['area'] ?? null) ?: ($existing?->practice_area_key ?: $areas->match($name));
        if (! empty($entry['area']) && $areas->get($entry['area']) === null) {
            throw ValidationException::withMessages(['primary_specialty' => 'Choose a practice area from the list.']);
        }
        if ($area !== null && $areas->get($area) === null) {
            $area = null;
        }

        if ($area !== null) {
            $definition = $areas->get($area);
            if (! app(SpecialtyEntitlements::class)->allows($this->context->clinic(), $definition['specialty'])) {
                throw ValidationException::withMessages(['primary_specialty' => "{$definition['label']} is part of ".$areas->moduleLabel($definition['module']).', which this clinic\'s plan does not include.']);
            }

            $keyed = ClinicalSpecialty::withoutGlobalScopes()->where('organization_id', $organizationId)->where('practice_area_key', $area)->first();
            if ($keyed) {
                return $keyed;
            }
            // A row recorded before areas existed becomes the area's entry.
            $adopt = $existing && $existing->practice_area_key === null ? $existing
                : ClinicalSpecialty::withoutGlobalScopes()->where('organization_id', $organizationId)->whereNull('practice_area_key')
                    ->get()->first(fn (ClinicalSpecialty $specialty) => $areas->match($specialty->name) === $area);
            if ($adopt) {
                $adopt->forceFill(['practice_area_key' => $area])->save();

                return $adopt;
            }

            return ClinicalSpecialty::withoutGlobalScopes()->forceCreate([
                'organization_id' => $organizationId,
                'name' => $definition['label'],
                'practice_area_key' => $area,
                'is_active' => true,
            ]);
        }

        if ($existing) {
            return $existing;
        }
        if ($name === '') {
            return null;
        }

        $existing = ClinicalSpecialty::withoutGlobalScopes()->where('organization_id', $organizationId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        return $existing ?? ClinicalSpecialty::withoutGlobalScopes()->forceCreate([
            'organization_id' => $organizationId,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function qualificationRules(ClinicEmployee $employee): array
    {
        return [
            'qualification' => ['required', 'string', 'max:120'],
            'degree_type' => ['required', Rule::in(array_keys(config('clinic_staff.degree_types')))],
            'specialization' => ['nullable', 'string', 'max:120'],
            'institution' => ['nullable', 'string', 'max:160'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'country' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'staff_document_id' => ['nullable', 'integer', Rule::exists('staff_documents', 'id')
                ->where('clinic_id', $employee->clinic_id)
                ->where('clinic_employee_id', $employee->id)
                ->whereNot('status', StaffDocument::REPLACED)],
        ];
    }
}
