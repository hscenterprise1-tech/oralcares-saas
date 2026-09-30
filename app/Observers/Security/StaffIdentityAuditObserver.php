<?php

namespace App\Observers\Security;

use App\Models\ClinicEmployee;
use App\Models\Practitioner;
use App\Models\PractitionerSchedule;
use App\Services\Security\SecurityAudit;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff identity is access: who a login is inside a clinic, whether they are
 * a clinician, and when they work. Every change to it goes into the security
 * trail, whichever screen, job or command made it.
 *
 * Only field names, ids and statuses are recorded, never names, emails,
 * phone numbers or registration details.
 */
class StaffIdentityAuditObserver
{
    /** @var array<class-string<Model>, string> */
    public const SUBJECTS = [
        ClinicEmployee::class => 'staff.employee',
        Practitioner::class => 'staff.practitioner_profile',
        PractitionerSchedule::class => 'staff.schedule',
    ];

    public function __construct(private readonly SecurityAudit $audit) {}

    public function created(Model $model): void
    {
        $this->record($model, 'created', $this->identity($model));
    }

    public function updated(Model $model): void
    {
        $changed = array_values(array_diff(array_keys($model->getChanges()), ['updated_at']));

        if ($changed === []) {
            return;
        }

        if ($model instanceof ClinicEmployee && $model->wasChanged('user_id')) {
            $this->record($model, $model->user_id ? 'linked' : 'unlinked', [
                'employee_id' => $model->id,
                'from_user_id' => $model->getOriginal('user_id'),
                'to_user_id' => $model->user_id,
            ]);
            $changed = array_values(array_diff($changed, ['user_id']));
        }

        if ($changed === []) {
            return;
        }

        $context = [...$this->identity($model), 'changed_fields' => $changed];

        foreach (['status', 'is_active', 'is_available', 'branch_id', 'day_of_week'] as $field) {
            if ($model->wasChanged($field)) {
                $context['from'][$field] = $this->plain($model->getOriginal($field));
                $context['to'][$field] = $this->plain($model->getAttribute($field));
            }
        }

        $this->record($model, 'updated', $context);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', $this->identity($model));
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(Model $model): array
    {
        return match (true) {
            $model instanceof ClinicEmployee => ['employee_id' => $model->id, 'user_id' => $model->user_id, 'status' => $this->plain($model->status)],
            $model instanceof Practitioner => ['employee_id' => $model->clinic_employee_id, 'profile_id' => $model->id],
            $model instanceof PractitionerSchedule => ['employee_id' => $model->clinic_employee_id, 'branch_id' => $model->branch_id, 'day_of_week' => $model->day_of_week],
            default => [],
        };
    }

    private function record(Model $model, string $action, array $context): void
    {
        $prefix = collect(self::SUBJECTS)->first(fn (string $prefix, string $class) => $model instanceof $class);

        $this->audit->record("{$prefix}.{$action}", $model, $context, array_filter([
            'clinic_id' => $model->getAttribute('clinic_id'),
            'branch_id' => $model instanceof PractitionerSchedule ? $model->branch_id : null,
        ], fn ($value) => $value !== null));
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
