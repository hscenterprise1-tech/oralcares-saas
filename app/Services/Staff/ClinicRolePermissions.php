<?php

namespace App\Services\Staff;

use App\ClinicPermission;
use App\ClinicRole;
use App\Models\Clinic;
use App\Models\ClinicRolePermissionOverride;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * What each staff role may do at one clinic.
 *
 * The standard roles (`ClinicRole`) are shared by every clinic and are never
 * edited here. A clinic's owner decides, for their own clinic only, to give a
 * role a permission it lacks or take one away; those choices are
 * `ClinicRolePermissionOverride` rows, layered over the standard role by
 * `decide()`, which `User::hasClinicPermission()` asks. A choice equal to the
 * standard is not stored. Everything is audited.
 *
 * The owner themself always holds every permission (`hasClinicPermission`) and
 * is not a role here. Two permissions can never be given to a role: managing
 * roles and choosing the clinic's specialties are the owner's alone.
 */
class ClinicRolePermissions
{
    /** Never given to a role: only the owner holds them. */
    public const LOCKED = ['roles.manage', 'specialty.configure'];

    /** Shown with a warning: they touch money, other people's records or the clinic's setup. */
    public const SENSITIVE = [
        'refund.process', 'discount.apply', 'payroll.view', 'payroll.manage', 'expense.approve', 'expense.manage', 'patient.merge', 'staff.manage',
        'branch.manage', 'settings.manage', 'catalog.manage', 'leave.approve', 'staff.documents.verify', 'clinical.cross_branch', 'billing.view',
    ];

    /**
     * Permissions by area, each with a plain label and what it lets a person do.
     *
     * @var array<string, array{label: string, items: array<string, array{0: string, 1: string}>}>
     */
    public const AREAS = [
        'patients' => ['label' => 'Patients', 'items' => [
            'patient.view' => ['See patients', 'Open a patient\'s record and basic details.'],
            'patient.search' => ['Search patients', 'Find a patient by name, number or phone.'],
            'patient.create' => ['Register patients', 'Add a new patient.'],
            'patient.edit' => ['Edit patient details', 'Change a patient\'s details.'],
            'patient.merge' => ['Merge duplicate patients', 'Join two records of the same person.'],
            'documents.manage' => ['Patient documents', 'Upload and open a patient\'s documents and scans.'],
            'consent.manage' => ['Patient consents', 'Take and withdraw a patient\'s consents.'],
        ]],
        'front_desk' => ['label' => 'Front desk and appointments', 'items' => [
            'appointment.view' => ['See appointments', 'Open the calendar.'],
            'appointment.create' => ['Book appointments', 'Book a patient in.'],
            'appointment.manage' => ['Change appointments', 'Move, cancel or complete appointments.'],
            'queue.manage' => ['Run the queue', 'Call the next patient and change the order.'],
            'front_office.operate' => ['Run the front desk', 'Registration, check-in and the day\'s patients.'],
            'visit.view' => ['See visits', 'Open the visit log.'],
            'visit.create' => ['Start visits', 'Start a visit for a patient.'],
        ]],
        'clinical' => ['label' => 'Treatment and clinical notes', 'items' => [
            'clinical.view' => ['See clinical notes', 'Read consultations, diagnoses and treatment.'],
            'clinical.edit' => ['Write clinical notes', 'Write and sign consultations.'],
            'clinical.cross_branch' => ['See other branches\' notes', 'Read a patient\'s notes from another branch while they attend here today. Always recorded.'],
            'prescription.manage' => ['Prescribe', 'Write and print prescriptions.'],
            'treatment.start' => ['Start treatments', 'Begin a treatment plan for a patient.'],
            'treatment.execute' => ['Carry out treatments', 'Record stages and procedures done.'],
            'physio.session.confirm' => ['Confirm physio sessions', 'Confirm at the desk that a session took place.'],
        ]],
        'billing' => ['label' => 'Bills and money', 'items' => [
            'billing.view' => ['See the billing ledger', 'Every bill, payment and total of the clinic.'],
            'billing.view_own_fees' => ['See own fees only', 'Only the fees of the person\'s own patients.'],
            'billing.create' => ['Make bills', 'Raise and edit a bill.'],
            'payment.collect' => ['Take payments', 'Receive a payment against a bill.'],
            'package.sell' => ['Sell packages', 'Sell a session package.'],
            'discount.apply' => ['Give discounts', 'Reduce a bill.'],
            'refund.process' => ['Refund money', 'Return money to a patient.'],
            'reports.view' => ['See reports', 'The clinic\'s reports and summaries.'],
            'expense.manage' => ['Record expenses', 'Write down what the clinic spends.'],
            'expense.approve' => ['Approve expenses', 'Decide and pay expenses.'],
        ]],
        'team' => ['label' => 'Team, pay and leave', 'items' => [
            'staff.manage' => ['Manage staff', 'Add and edit staff and where they work.'],
            'practitioner.manage' => ['Manage doctors\' profiles', 'Registration, qualifications, services and hours.'],
            'staff.documents.verify' => ['Verify staff documents', 'Check licences and ID.'],
            'leave.request' => ['Ask for leave', 'Request one\'s own time off.'],
            'leave.approve' => ['Decide leave', 'Approve or reject time off.'],
            'payroll.view' => ['See salaries', 'Salaries and payslips.'],
            'payroll.manage' => ['Run payroll', 'Set salaries, pay and advances.'],
        ]],
        'clinic' => ['label' => 'Clinic setup', 'items' => [
            'catalog.manage' => ['Services and treatments', 'Set the price list and treatments.'],
            'branch.manage' => ['Branches and rooms', 'Add branches, departments, rooms and chairs.'],
            'settings.manage' => ['Clinic settings', 'Name, front desk, forms, consents and WhatsApp.'],
            'roles.manage' => ['Give roles to staff', 'Change what role someone has. Owner only.'],
            'specialty.configure' => ['Choose specialties', 'Which kinds of care the clinic offers. Owner only.'],
        ]],
    ];

    /** @var array<int, array<string, array<string, bool>>> clinic id => role => permission => granted */
    private array $loaded = [];

    /** @var array<string, list<string>> */
    private array $templates = [];

    public function __construct(protected SecurityAudit $audit) {}

    /** Forget what was read, so the next check reads the clinic's choices again. */
    public function forget(): void
    {
        $this->loaded = [];
        $this->templates = [];
    }

    public static function flush(): void
    {
        app(self::class)->forget();
    }

    /**
     * The permissions a standard role has before any clinic changes it.
     *
     * @return list<string>
     */
    public function template(string $roleName): array
    {
        if (isset($this->templates[$roleName])) {
            return $this->templates[$roleName];
        }
        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->whereNull('branch_id')->first();
        $names = $role ? $role->permissions()->pluck('name')->all() : (ClinicRole::tryFrom($roleName)?->permissions() ?? []);

        return $this->templates[$roleName] = array_values($names);
    }

    /**
     * @return array<string, array<string, bool>> role => permission => granted
     */
    public function overrides(int $clinicId): array
    {
        if (! isset($this->loaded[$clinicId])) {
            $map = [];
            foreach (ClinicRolePermissionOverride::query()->where('clinic_id', $clinicId)->get(['role_name', 'permission', 'granted']) as $row) {
                $map[$row->role_name][$row->permission] = (bool) $row->granted;
            }
            $this->loaded[$clinicId] = $map;
        }

        return $this->loaded[$clinicId];
    }

    public function effective(Clinic|int $clinic, string $roleName, string $permission): bool
    {
        $override = $this->overrides($clinic instanceof Clinic ? $clinic->id : $clinic)[$roleName][$permission] ?? null;

        return $override ?? in_array($permission, $this->template($roleName), true);
    }

    /**
     * What a person with these roles may do here, when this clinic has changed
     * any of their roles; null when it has not, so the standard role applies.
     *
     * @param  iterable<string>  $roleNames
     */
    public function decide(?Clinic $clinic, iterable $roleNames, string $permission): ?bool
    {
        if (! $clinic) {
            return null;
        }
        $overrides = $this->overrides($clinic->id);
        if ($overrides === []) {
            return null;
        }
        $roles = array_values(array_intersect(is_array($roleNames) ? $roleNames : iterator_to_array($roleNames, false), ClinicRole::values()));
        if ($roles === [] || array_intersect($roles, array_keys($overrides)) === []) {
            return null;
        }
        if (in_array($permission, self::LOCKED, true)) {
            return null;
        }
        foreach ($roles as $role) {
            if ($this->effective($clinic, $role, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole screen: every permission by area, and for each role whether it
     * has it, whether that is the standard, and what the standard is.
     *
     * @return array{roles: list<array<string, mixed>>, areas: list<array<string, mixed>>}
     */
    public function matrix(Clinic $clinic): array
    {
        $roles = collect(ClinicRole::cases());
        $overrides = $this->overrides($clinic->id);

        return [
            'roles' => $roles->map(fn (ClinicRole $role) => [
                'name' => $role->value,
                'changes' => count($overrides[$role->value] ?? []),
                'seat' => $role->seat(),
            ])->values()->all(),
            'areas' => collect(self::AREAS)->map(fn (array $area, string $key) => [
                'key' => $key,
                'label' => $area['label'],
                'items' => collect($area['items'])->map(fn (array $text, string $permission) => [
                    'permission' => $permission,
                    'label' => $text[0],
                    'description' => $text[1],
                    'sensitive' => in_array($permission, self::SENSITIVE, true),
                    'locked' => in_array($permission, self::LOCKED, true),
                    'roles' => $roles->mapWithKeys(function (ClinicRole $role) use ($clinic, $permission) {
                        $default = in_array($permission, $this->template($role->value), true);
                        $granted = $this->effective($clinic, $role->value, $permission);

                        return [$role->value => ['granted' => $granted, 'default' => $default, 'changed' => $granted !== $default]];
                    })->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Give a role a permission at this clinic, or take it away.
     *
     * @throws DomainException
     */
    public function set(Clinic $clinic, ClinicRole $role, ClinicPermission $permission, bool $granted, User $by): void
    {
        if (in_array($permission->value, self::LOCKED, true)) {
            throw new DomainException('Only the owner can hold this permission. It cannot be given to a role.');
        }
        $default = in_array($permission->value, $this->template($role->value), true);
        $before = $this->effective($clinic, $role->value, $permission->value);

        DB::transaction(function () use ($clinic, $role, $permission, $granted, $default, $by) {
            if ($granted === $default) {
                ClinicRolePermissionOverride::query()->where('clinic_id', $clinic->id)->where('role_name', $role->value)->where('permission', $permission->value)->delete();
            } else {
                ClinicRolePermissionOverride::query()->updateOrCreate(
                    ['clinic_id' => $clinic->id, 'role_name' => $role->value, 'permission' => $permission->value],
                    ['granted' => $granted, 'set_by' => $by->id],
                );
            }
        });
        $this->forget();

        if ($before !== $granted) {
            $this->audit->record('access.role_permission_changed', null, ['role' => $role->value, 'permission' => $permission->value, 'from' => $before, 'to' => $granted], ['clinic_id' => $clinic->id]);
        }
    }

    /** Put a role back to the standard at this clinic. Returns how many changes were undone. */
    public function reset(Clinic $clinic, ClinicRole $role, User $by): int
    {
        $count = ClinicRolePermissionOverride::query()->where('clinic_id', $clinic->id)->where('role_name', $role->value)->delete();
        $this->forget();
        if ($count > 0) {
            $this->audit->record('access.role_reset', null, ['role' => $role->value, 'changes_undone' => $count, 'by' => $by->id], ['clinic_id' => $clinic->id]);
        }

        return $count;
    }
}
