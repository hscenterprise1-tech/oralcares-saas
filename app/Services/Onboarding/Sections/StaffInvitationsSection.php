<?php

namespace App\Services\Onboarding\Sections;

use App\ClinicRole;
use App\Exceptions\ResourceLimitExceededException;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Services\Onboarding\OnboardingContext;
use App\Services\ResourceLimitService;
use App\Services\Staff\StaffDirectory;
use App\Services\Staff\StaffEnrolment;
use App\Services\Staff\StaffInvitationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Invite the first team members. Uses the same staff records and
 * invitations as Staff management: each invitation carries the clinic, the
 * branches they work at, the role to grant, their staff type and who
 * invited them. Owner is never assignable here.
 */
class StaffInvitationsSection extends AbstractSection
{
    public const MAX_PER_SUBMIT = 25;

    public function __construct(
        protected StaffEnrolment $enrolment,
        protected StaffInvitationService $invitations,
        protected ResourceLimitService $limits,
    ) {}

    public function key(): string
    {
        return 'staff_invitations';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Invite your team';
    }

    public function description(OnboardingContext $context): string
    {
        return 'Invite doctors, reception and billing staff. Each gets an email to set their password. You can also do this later.';
    }

    public function required(OnboardingContext $context): bool
    {
        return false;
    }

    public function initial(OnboardingContext $context): array
    {
        return ['invites' => []];
    }

    public function meta(OnboardingContext $context): array
    {
        return [
            'staff_types' => collect(StaffDirectory::staffTypes())->map(fn (array $type, string $key) => ['value' => $key, 'label' => $type['label'], 'clinical' => $type['clinical']])->values()->all(),
            'roles' => $this->roles()->map(fn (Role $role) => ['value' => $role->id, 'label' => $role->name])->values()->all(),
            'branches' => $this->branches($context)->map(fn (Branch $branch) => ['value' => $branch->id, 'label' => $branch->name, 'primary' => (bool) $branch->is_main])->values()->all(),
            'invited' => $this->invited($context),
        ];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'invites' => ['present', 'array', 'max:'.self::MAX_PER_SUBMIT],
            'invites.*.name' => ['required', 'string', 'max:120'],
            'invites.*.email' => ['required', 'email', 'max:160', 'distinct:ignore_case'],
            'invites.*.staff_type' => ['required', Rule::in(array_keys(StaffDirectory::staffTypes()))],
            'invites.*.role_id' => ['required', 'integer', Rule::in($this->roles()->pluck('id')->all())],
            'invites.*.branch_ids' => ['required', 'array', 'min:1'],
            'invites.*.branch_ids.*' => ['integer', Rule::in($this->branches($context)->pluck('id')->all())],
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        foreach ($data['invites'] as $index => $invite) {
            $email = strtolower(trim($invite['email']));

            if (ClinicEmployee::withoutGlobalScopes()->where('clinic_id', $context->clinic->id)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(["invites.{$index}.email" => "{$email} is already on your staff list."]);
            }

            $role = $this->roles()->firstWhere('id', (int) $invite['role_id']);
            try {
                ClinicRole::takesDoctorSeat(array_filter([$role?->name])) ? $this->limits->enforceDoctorLimit($context->clinic) : $this->limits->enforceStaffLimit($context->clinic);
                $this->limits->enforceUserLimit($context->clinic);
            } catch (ResourceLimitExceededException $e) {
                throw ValidationException::withMessages(["invites.{$index}.email" => $e->getMessage()]);
            }

            $branchIds = array_values(array_unique(array_map('intval', $invite['branch_ids'])));
            $employee = $this->enrolment->enrol(
                $context->clinic,
                ['name' => $invite['name'], 'email' => $email, 'status' => 'invited', 'staff_type' => $invite['staff_type']],
                $branchIds,
                $branchIds[0],
                [(int) $invite['role_id']],
                $context->actor,
            );

            $this->invitations->invite($employee, $email, $context->actor);
        }
    }

    public function summary(OnboardingContext $context): array
    {
        $invited = $this->invited($context);

        return $invited === []
            ? ['Nobody invited yet']
            : [count($invited).' invited: '.collect($invited)->take(4)->map(fn (array $row) => $row['name'].($row['role'] ? ' ('.$row['role'].')' : ''))->implode(', ')];
    }

    /**
     * @return Collection<int, Role>
     */
    private function roles()
    {
        return Role::query()
            ->whereNull(config('permission.column_names.team_foreign_key', 'branch_id'))
            ->whereIn('name', config('clinic_onboarding.invitable_roles'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Branch>
     */
    private function branches(OnboardingContext $context)
    {
        return Branch::withoutGlobalScopes()
            ->where('clinic_id', $context->clinic->id)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get(['id', 'name', 'is_main']);
    }

    /**
     * Staff already invited from this clinic, newest first.
     *
     * @return list<array{name: string, email: ?string, role: ?string, status: ?string}>
     */
    private function invited(OnboardingContext $context): array
    {
        return ClinicEmployee::withoutGlobalScopes()
            ->where('clinic_id', $context->clinic->id)
            ->whereNotNull('email')
            ->whereHas('invitations', fn ($query) => $query->withoutGlobalScopes())
            ->with('roles:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (ClinicEmployee $employee) => [
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => $employee->roles->first()?->name,
                'status' => $employee->status?->value,
            ])
            ->all();
    }
}
