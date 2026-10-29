<?php

namespace App\Services\Patient;

use App\ClinicPermission;
use App\Models\User;
use App\Services\ClinicContext;
use App\Support\ClinicAccess\ClinicAccess;

/**
 * Who is looking at the patient directory, and which views they get.
 *
 * - Owner: the owner with the owner's privileges (always for an owner who
 *   does not treat patients, a doctor-owner only in Owner Mode), or a member
 *   who manages staff. Everyone at the clinic, by default.
 * - Doctor: a practitioner here. Their assigned patients by default, with
 *   today's visits to them and everyone they may open one tap away.
 * - Reception: the front desk. Today's patients by default.
 * - Staff: anyone else who may view patients (billing, assistants).
 *
 * The views only narrow the directory; who may be seen at all is still
 * `PatientPolicy` and `PatientBranchAccess`, applied by `PatientDirectory`.
 */
class PatientDirectoryAudience
{
    public const OWNER = 'owner';

    public const DOCTOR = 'doctor';

    public const RECEPTION = 'reception';

    public const STAFF = 'staff';

    /** Patients assigned to the doctor (`current_practitioner_id`). */
    public const VIEW_MINE = 'mine';

    /** Patients who attended today: a visit on the clinic's local date (`PatientDirectory::ATTENDED`), new or returning. */
    public const VIEW_TODAY = 'today';

    public const VIEW_ALL = 'all';

    /**
     * Permissions that make contacting patients part of the member's work.
     * Everyone else gets the last digits of the mobile number only.
     */
    public const CONTACT_PERMISSIONS = [
        ClinicPermission::PatientEdit,
        ClinicPermission::AppointmentCreate,
        ClinicPermission::ClinicalEdit,
    ];

    public function __construct(
        protected ClinicAccess $access,
        protected ClinicContext $context,
    ) {}

    public function audience(): string
    {
        if ($this->access->ownerModeActive()) {
            return self::OWNER;
        }
        if ($this->context->currentEmployee()?->practitionerProfile !== null) {
            return self::DOCTOR;
        }

        $workspaces = $this->access->workspaces();

        return match (true) {
            in_array('command_center', $workspaces, true) => self::OWNER,
            in_array('front_desk', $workspaces, true) => self::RECEPTION,
            default => self::STAFF,
        };
    }

    /**
     * The views offered to this audience, the default first.
     *
     * @return list<string>
     */
    public function views(): array
    {
        return match ($this->audience()) {
            self::DOCTOR => [self::VIEW_MINE, self::VIEW_TODAY, self::VIEW_ALL],
            self::RECEPTION => [self::VIEW_TODAY, self::VIEW_ALL],
            default => [self::VIEW_ALL, self::VIEW_TODAY],
        };
    }

    /** The requested view if it is offered, else the audience's default. */
    public function resolveView(?string $requested): string
    {
        $views = $this->views();

        return in_array($requested, $views, true) ? $requested : $views[0];
    }

    /**
     * The directory filters a view adds: the doctor's own patients, or (for
     * a doctor's Today) the visits to them.
     *
     * @return array{view: string, practitioner_id?: int, today_doctor_id?: int}
     */
    public function filtersFor(string $view): array
    {
        $employeeId = $this->audience() === self::DOCTOR ? $this->context->currentEmployee()?->id : null;

        return match (true) {
            $view === self::VIEW_MINE && $employeeId !== null => ['view' => $view, 'practitioner_id' => $employeeId],
            $view === self::VIEW_TODAY && $employeeId !== null => ['view' => $view, 'today_doctor_id' => $employeeId],
            default => ['view' => $view],
        };
    }

    public function canSeeContact(User $user): bool
    {
        foreach (self::CONTACT_PERMISSIONS as $permission) {
            if ($user->hasClinicPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
