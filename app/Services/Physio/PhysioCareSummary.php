<?php

namespace App\Services\Physio;

use App\BillingStatus;
use App\ClinicPermission;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Models\SessionPackageUsage;
use App\Models\User;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\PatientBranchAccess;
use App\Services\QueueStateMachine;
use App\Services\Reception\DoctorRoster;
use App\Services\Treatment\TreatmentReadModel;
use App\Support\ClinicClock;

/**
 * A patient's physiotherapy at a glance, read-only, for the front desk:
 * each open physiotherapy treatment with the sessions planned and completed
 * (the treatment's own count of completed sessions — never the queue), the
 * packages that can still pay for one of its sessions with what each has
 * left (the package ledger), what the patient owes on live bills (only to
 * members who see billing), and — when the patient is in today's queue here
 * — where the desk confirms the session (only for members who may).
 *
 * Null when the clinic does not practise physiotherapy. Everything is read
 * in the patient's clinic and the member's branches; a handful of queries
 * per patient, never per treatment.
 */
class PhysioCareSummary
{
    public const SPECIALTY = 'physiotherapy';

    public function __construct(
        private ClinicSpecialties $specialties,
        private PatientBranchAccess $branchAccess,
        private TreatmentReadModel $treatments,
        private DoctorRoster $roster,
    ) {}

    /**
     * @return array{treatments: list<array<string, mixed>>, amount_due: ?float, confirm: ?array<string, mixed>, therapists: list<array{id: int, name: string}>, start_url: ?string}|null
     */
    public function forPatient(Patient $patient, User $user, ?Branch $branch): ?array
    {
        $clinic = Clinic::query()->findOrFail($patient->clinic_id);
        if ($this->specialties->active($clinic, self::SPECIALTY) === null) {
            return null;
        }
        $branchIds = $this->branchAccess->branchIds();

        $treatments = $this->treatments->query($clinic->id, $branchIds)->where('patient_id', $patient->id)
            ->where('specialty_key', self::SPECIALTY)->whereIn('status', TreatmentReadModel::OPEN)
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'title', 'status', 'sessions_planned', 'sessions_completed']);

        $packages = $treatments->isEmpty() ? collect() : PatientSessionPackage::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)->where('patient_id', $patient->id)
            ->whereIn('patient_treatment_id', $treatments->pluck('id'))->where('status', PatientSessionPackage::ACTIVE)
            ->orderBy('valid_until')->orderBy('id')->get()
            ->reject(fn (PatientSessionPackage $package) => $package->isExpired());
        $ledger = $packages->isEmpty() ? collect() : SessionPackageUsage::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->whereIn('package_id', $packages->pluck('id'))->groupBy('package_id')->selectRaw('package_id, sum(quantity) as total')->pluck('total', 'package_id');

        return [
            'treatments' => $treatments->map(fn (PatientTreatment $treatment) => [
                'id' => $treatment->id,
                'title' => $treatment->title,
                'status' => $treatment->status,
                // Planned by the treatment (null when it does not set a number) and completed so far.
                'sessions_planned' => $treatment->sessions_planned ? (int) $treatment->sessions_planned : null,
                'sessions_completed' => (int) $treatment->sessions_completed,
                // What each package of this treatment can still pay for: the ledger, not the plan.
                'packages' => $packages->where('patient_treatment_id', $treatment->id)
                    ->map(fn (PatientSessionPackage $package) => [
                        'id' => $package->id,
                        'name' => $package->name,
                        'sessions_total' => (int) $package->sessions_total,
                        'remaining' => (int) $package->sessions_total + (int) ($ledger[$package->id] ?? 0),
                        'valid_until' => $package->valid_until?->toDateString(),
                    ])
                    ->filter(fn (array $package) => $package['remaining'] > 0)->values()->all(),
            ])->values()->all(),
            // What this patient owes: for the ledger's readers and for the desk that collects it (not clinic-wide figures).
            'amount_due' => $user->hasClinicPermission(ClinicPermission::BillingView) || $user->hasClinicPermission(ClinicPermission::PaymentCollect) ? $this->amountDue($patient, $branchIds) : null,
            'confirm' => $this->confirmation($patient, $user, $branch),
            // The desk runs physiotherapy for therapists who need not sign in: who can give it here, and where a programme starts.
            ...$this->desk($clinic, $patient, $user, $branch),
        ];
    }

    /**
     * @return array{therapists: list<array{id: int, name: string}>, start_url: ?string}
     */
    private function desk(Clinic $clinic, Patient $patient, User $user, ?Branch $branch): array
    {
        if ($branch === null || ! $user->hasClinicPermission(ClinicPermission::PhysioSessionConfirm)) {
            return ['therapists' => [], 'start_url' => null];
        }

        return [
            'therapists' => $this->roster->forBranch($clinic, $branch)
                ->filter(fn (array $entry) => $entry['shift'] !== 'on_leave' && in_array(self::SPECIALTY, $entry['employee']->practitionerProfile?->specialtyKeys() ?? [], true))
                ->map(fn (array $entry) => ['id' => $entry['employee']->id, 'name' => $entry['employee']->name])
                ->values()->all(),
            'start_url' => route('clinic.reception.physio-programme', $patient->id),
        ];
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    private function amountDue(Patient $patient, ?array $branchIds): float
    {
        return round((float) PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds ?: [0]))
            ->where('status', '!=', BillingStatus::Cancelled->value)->sum('outstanding_balance'), 2);
    }

    /**
     * Where the desk confirms today's session: the patient's entry in today's
     * queue at this branch, still waiting or with the therapist. Only for a
     * member who may confirm sessions and runs the queue.
     *
     * @return array<string, mixed>|null
     */
    private function confirmation(Patient $patient, User $user, ?Branch $branch): ?array
    {
        if ($branch === null || ! $user->hasClinicPermission(ClinicPermission::PhysioSessionConfirm) || ! $user->hasClinicPermission(ClinicPermission::QueueManage)) {
            return null;
        }
        $item = ClinicQueueItem::query()->withoutGlobalScopes()
            ->whereIn('status', QueueStateMachine::ACTIVE)
            ->whereHas('queue', fn ($queue) => $queue->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('branch_id', $branch->id)
                ->whereOnDate('queue_date', ClinicClock::now()->toDateString()))
            ->whereHas('patientVisit', fn ($visit) => $visit->withoutGlobalScopes()->where('patient_id', $patient->id))
            ->with(['doctor' => fn ($doctor) => $doctor->withoutGlobalScopes()->select(['id', 'name'])])
            ->latest('id')->first();

        return $item ? [
            'url' => route('clinic.reception.queue.physio-session', $item->id),
            'therapist' => $item->doctor?->name,
            'therapist_id' => $item->doctor_id,
        ] : null;
    }
}
