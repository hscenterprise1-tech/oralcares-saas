<?php

namespace App\Services\Staff;

use App\AppointmentStatus;
use App\ClinicPermission;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\Leave;
use App\Models\Practitioner;
use App\Models\PractitionerQualification;
use App\Models\SecurityAuditLog;
use App\Models\StaffDocumentType;
use App\Models\User;
use App\Services\ClinicContext;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicClock;
use Illuminate\Support\Facades\DB;

/**
 * How a staff record reads on the staff list and on its profile page.
 *
 * The profile carries every section at once (each query bounded), so tabs
 * switch without a round trip. Sensitive files are never part of it: only
 * document metadata, with downloads going through an authorised route.
 */
class StaffProfileView
{
    public function __construct(
        protected StaffDirectory $directory,
        protected StaffDocumentChecklist $checklist,
        protected PractitionerScheduleEditor $schedules,
        protected LeaveWorkflow $leaves,
        protected ClinicAccess $access,
    ) {}

    /**
     * @return list<array{value: string, label: string, clinical: bool}>
     */
    public function staffTypeOptions(): array
    {
        return collect(StaffDirectory::staffTypes())
            ->map(fn (array $meta, string $key) => ['value' => $key, 'label' => $meta['label'], 'clinical' => (bool) $meta['clinical']])
            ->values()->all();
    }

    /**
     * A staff list row: no contact details beyond what the list needs.
     *
     * @return array<string, mixed>
     */
    public function row(ClinicEmployee $employee, ?string $lastActivity): array
    {
        $profile = $employee->relationLoaded('practitionerProfile') ? $employee->practitionerProfile : null;
        $invitation = $employee->relationLoaded('invitations') ? $employee->invitations->first() : null;

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'photo_url' => $employee->photo_path ? route('admin.employees.photo', $employee) : null,
            'roles' => $employee->relationLoaded('roles') ? $employee->roles->pluck('name')->values() : [],
            'staff_type' => $employee->staff_type,
            'staff_type_label' => StaffDirectory::typeLabel($employee->staff_type) ?? ($profile ? ($profile->profession ?: 'Practitioner') : null),
            'primary_branch' => $employee->primaryBranch?->name ?? ($employee->relationLoaded('branches') && $employee->branches->count() === 1 ? $employee->branches->first()->name : null),
            'branch_count' => $employee->relationLoaded('branches') ? $employee->branches->count() : 0,
            'status' => $employee->status?->value,
            'tab' => StaffDirectory::tabOf($employee->status),
            'is_practitioner' => $profile !== null,
            'specialty' => $profile ? ($profile->primarySpecialty?->name ?? $profile->specialty) : null,
            'headline' => $profile ? $this->headline($profile) : null,
            'has_login' => $employee->user_id !== null,
            'invitation_pending' => $invitation !== null,
            'last_activity' => $lastActivity,
        ];
    }

    /**
     * "MDS (Orthodontics)": the highest qualification on record.
     */
    public function headline(Practitioner $profile): ?string
    {
        $rank = ['doctorate' => 0, 'fellowship' => 1, 'master' => 2, 'diploma' => 3, 'bachelor' => 4, 'certification' => 5, 'other' => 6];
        $records = $profile->relationLoaded('qualificationRecords') ? $profile->qualificationRecords : $profile->qualificationRecords()->get();
        $top = $records->sortBy(fn (PractitionerQualification $q) => [$rank[$q->degree_type] ?? 9, $q->position])->first();

        return $top?->label() ?? ($profile->qualifications ?: null);
    }

    /**
     * Everything the profile page shows.
     *
     * @return array<string, mixed>
     */
    public function profile(ClinicContext $context, ClinicEmployee $employee, User $viewer, string $tab): array
    {
        $clinic = $context->clinic();
        $employee->load([
            'user:id,email',
            'branches:id,name',
            'primaryBranch:id,name',
            'departments:id,name',
            'roles',
            'services:id',
            'practitionerProfile' => fn ($q) => $q->withoutGlobalScopes()->with(['qualificationRecords', 'primarySpecialty:id,name', 'specialties' => fn ($s) => $s->withoutGlobalScopes()]),
            'invitations' => fn ($query) => $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest(),
        ]);

        $profile = $employee->practitionerProfile;
        $today = ClinicClock::today();
        $reachable = $this->directory->branches($clinic, $context->membership());
        $reachableIds = $reachable->pluck('id')->map(fn ($id) => (int) $id)->all();
        $branchScope = $this->directory->clinicWide($context->membership()) ? null : $reachableIds;
        $lastActivity = $employee->user_id ? $this->directory->lastActivity($clinic, [(int) $employee->user_id])->get($employee->user_id) : null;

        $can = [
            'edit' => $viewer->hasClinicPermission(ClinicPermission::StaffManage),
            'practitioner' => $viewer->hasClinicPermission(ClinicPermission::PractitionerManage),
            'verify' => $viewer->hasClinicPermission(ClinicPermission::StaffDocumentsVerify),
            'roles' => $viewer->hasClinicPermission(ClinicPermission::RolesManage),
            'approveLeave' => $viewer->hasClinicPermission(ClinicPermission::LeaveApprove),
            'appointments' => $viewer->hasClinicPermission(ClinicPermission::AppointmentView),
        ];

        $leaves = Leave::query()
            ->where('clinic_id', $clinic->id)
            ->where('employee_id', $employee->id)
            ->with(['decider:id,name'])
            ->orderByDesc('start_date')
            ->limit(30)
            ->get();
        $affected = $this->leaves->affectedCounts($leaves, $branchScope);

        $upcoming = Appointment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('practitioner_id', $employee->id)
            ->when($branchScope !== null, fn ($q) => $q->whereIn('branch_id', $branchScope))
            ->where('scheduled_at', '>=', $today)
            ->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::checkable()))
            ->with(['patient:id,first_name,last_name,name', 'service:id,name', 'branch:id,name'])
            ->orderBy('scheduled_at')
            ->limit(12)
            ->get();

        return [
            'tab' => $tab,
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'status' => $employee->status?->value,
                'status_tab' => StaffDirectory::tabOf($employee->status),
                'status_changed_at' => $employee->status_changed_at?->toIso8601String(),
                'staff_type' => $employee->staff_type,
                'staff_type_label' => StaffDirectory::typeLabel($employee->staff_type) ?? ($profile ? ($profile->profession ?: 'Practitioner') : null),
                'photo_url' => $employee->photo_path ? route('admin.employees.photo', $employee) : null,
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'gender' => $employee->gender,
                'joining_date' => $employee->joining_date?->toDateString(),
                'notes' => $employee->notes,
                'primary_branch' => $employee->primaryBranch ? ['id' => $employee->primaryBranch->id, 'name' => $employee->primaryBranch->name] : null,
                'branches' => $employee->branches->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'is_active' => (bool) ($branch->pivot->is_active ?? true),
                    'is_primary' => $branch->id === $employee->primary_branch_id,
                    'editable' => in_array((int) $branch->id, $reachableIds, true),
                ])->values(),
                'departments' => $employee->departments->pluck('name')->values(),
                'roles' => $employee->roles->pluck('name')->values(),
                'has_login' => $employee->user_id !== null,
                'login_email' => $employee->user?->email,
                'invitation' => ($invite = $employee->invitations->first()) ? ['email' => $invite->email, 'expires_at' => $invite->expires_at?->toIso8601String()] : null,
                'created_at' => $employee->created_at?->toIso8601String(),
                'last_activity' => $lastActivity,
                'is_self' => $employee->user_id !== null && (int) $employee->user_id === (int) $viewer->id,
            ],
            'practitioner' => $profile ? $this->practitioner($profile, $today) : null,
            'services' => $profile ? [
                'catalogue' => ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_active', true)->orderBy('category')->orderBy('name')->limit(300)->get(['id', 'name', 'category', 'default_duration_minutes'])
                    ->map(fn (ClinicalProcedure $service) => ['id' => $service->id, 'name' => $service->name, 'category' => $service->category, 'duration_minutes' => $service->default_duration_minutes])->values(),
                'selected' => $employee->services->pluck('id')->map(fn ($id) => (int) $id)->values(),
            ] : null,
            'schedule' => $profile ? [
                'weekly' => (object) $this->schedules->weekly($employee),
            ] : null,
            'leave' => $leaves->map(fn (Leave $leave) => [
                'id' => $leave->id,
                'type' => $leave->leave_type,
                'type_label' => config("clinic_staff.leave_types.{$leave->leave_type}", ucfirst((string) $leave->leave_type)),
                'status' => $leave->status,
                'start_date' => $leave->start_date->toDateString(),
                'end_date' => $leave->end_date->toDateString(),
                'reason' => $leave->reason,
                'note' => $leave->note,
                'decision_note' => $leave->decision_note,
                'decided_by' => $leave->decider?->name,
                'affected' => $affected[$leave->id] ?? 0,
            ])->values(),
            'appointments' => $upcoming->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'start' => $appointment->scheduled_at->format('Y-m-d\TH:i:s'),
                'patient' => $appointment->patient?->full_name ?? $appointment->prospect_name ?? 'New patient',
                'service' => $appointment->service?->name,
                'branch' => $appointment->branch?->name,
                'status' => $appointment->status->value,
            ])->values(),
            'documents' => $this->checklist->forEmployee($clinic, $employee),
            'documentTypes' => StaffDocumentType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_active', true)->orderBy('position')->get(['id', 'name', 'audience', 'has_expiry'])
                ->filter(fn (StaffDocumentType $type) => $type->audience === 'all' || ($type->audience === 'practitioner') === ($profile !== null))
                ->values(),
            'activity' => $this->activity($clinic->id, $employee),
            'options' => [
                'branches' => $reachable->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name])->values(),
                'staffTypes' => $this->staffTypeOptions(),
                'degreeTypes' => config('clinic_staff.degree_types'),
                'registrationTypes' => config('clinic_staff.registration_types'),
                'leaveTypes' => config('clinic_staff.leave_types'),
                'specialties' => $profile ? $this->specialtyOptions($clinic->organization_id, $context) : [],
                'upload' => ['mimes' => StaffDocumentService::MIMES, 'max_kb' => StaffDocumentService::MAX_KB],
            ],
            'can' => $can,
            'links' => [
                'index' => route('admin.employees.index'),
                'edit' => route('admin.employees.edit', $employee),
                'self' => route('admin.employees.show', $employee),
                'invite' => route('admin.invitations.store'),
                'leave' => route('clinic.leave.index'),
                'appointments' => route('clinic.appointments.index'),
                'documentSettings' => route('admin.staff-documents.settings'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function practitioner(Practitioner $profile, $today): array
    {
        $expires = $profile->registration_expires_at;

        return [
            'id' => $profile->id,
            'profession' => $profile->profession,
            'headline' => $this->headline($profile),
            'primary_specialty' => $profile->primarySpecialty ? ['id' => $profile->primarySpecialty->id, 'name' => $profile->primarySpecialty->name] : ($profile->specialty ? ['id' => null, 'name' => $profile->specialty] : null),
            'additional_specialties' => $profile->specialties->map(fn (ClinicalSpecialty $specialty) => ['id' => $specialty->id, 'name' => $specialty->name])->values(),
            'experience_years' => $profile->experience_years,
            'bio' => $profile->bio,
            'is_active' => (bool) $profile->is_active,
            'registration' => [
                'number' => $profile->registration_number,
                'authority' => $profile->registration_authority,
                'state' => $profile->registration_state,
                'type' => $profile->registration_type,
                'date' => $profile->registration_date?->toDateString(),
                'expires_at' => $expires?->toDateString(),
                'expiry_state' => ! $expires ? null : ($expires->lt($today) ? 'expired' : ($expires->lte($today->addDays((int) config('clinic_staff.expiring_soon_days', 30))) ? 'expiring_soon' : 'valid')),
            ],
            'qualifications' => $profile->qualificationRecords->map(fn (PractitionerQualification $q) => [
                'id' => $q->id,
                'label' => $q->label(),
                'qualification' => $q->qualification,
                'degree_type' => $q->degree_type,
                'specialization' => $q->specialization,
                'institution' => $q->institution,
                'year' => $q->year,
                'country' => $q->country,
                'state' => $q->state,
                'staff_document_id' => $q->staff_document_id,
                'verification_status' => $q->verification_status,
            ])->values(),
        ];
    }

    /**
     * The organisation's specialty catalogue, plus suggestions from the
     * global catalogue for the clinic's field that it does not have yet.
     *
     * @return list<array{id: int|null, key: string|null, name: string}>
     */
    private function specialtyOptions(int $organizationId, ClinicContext $context): array
    {
        $own = ClinicalSpecialty::withoutGlobalScopes()->where('organization_id', $organizationId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $categories = collect($this->access->specialties())
            ->flatMap(fn (string $specialty) => config("clinic_staff.specialty_categories.{$specialty}", []))
            ->unique()->values()->all();

        $suggested = $categories === [] ? collect() : DB::table('healthcare_specialty_masters')
            ->whereIn('category', $categories)
            ->where('is_active', true)
            ->orderBy('position')
            ->limit(60)
            ->get(['key', 'name'])
            ->reject(fn ($master) => $own->contains(fn (ClinicalSpecialty $specialty) => strcasecmp($specialty->name, $master->name) === 0));

        return [
            ...$own->map(fn (ClinicalSpecialty $specialty) => ['id' => $specialty->id, 'key' => null, 'name' => $specialty->name])->all(),
            ...$suggested->map(fn ($master) => ['id' => null, 'key' => $master->key, 'name' => $master->name])->values()->all(),
        ];
    }

    /**
     * Recent audit events about this staff member, newest first.
     *
     * @return list<array{event: string, at: string|null, actor: string|null}>
     */
    private function activity(int $clinicId, ClinicEmployee $employee): array
    {
        return SecurityAuditLog::query()
            ->where('clinic_id', $clinicId)
            ->where(fn ($q) => $q
                ->where(fn ($subject) => $subject->where('subject_type', 'ClinicEmployee')->where('subject_id', $employee->id))
                ->when($employee->user_id, fn ($actor) => $actor->orWhere('actor_user_id', $employee->user_id)))
            ->orderByDesc('id')
            ->limit(20)
            ->get(['event', 'occurred_at', 'actor_user_id'])
            ->map(fn (SecurityAuditLog $log) => [
                'event' => $log->event,
                'at' => $log->occurred_at?->toIso8601String(),
                'actor' => null,
            ])->values()->all();
    }
}
