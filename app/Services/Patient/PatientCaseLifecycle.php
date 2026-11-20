<?php

namespace App\Services\Patient;

use App\ClinicPermission;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientCase;
use App\Models\PatientTimelineEvent;
use App\Models\PatientTreatment;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Security\SecurityAudit;
use App\Support\ClinicClock;
use App\Support\Specialty\SpecialtyRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A case's controlled lifecycle (D3): active → completion recommended →
 * closed, and closed → active again on reopening. Nothing closes a case on
 * its own: not a procedure completed, not a package's last session.
 *
 * - Recommend completion: a practitioner who treats the case (its lead, or
 *   the doctor of one of its visits) with `clinical.edit`. A physiotherapy
 *   case is recommended only once its plans of care are discharged — no
 *   physiotherapy treatment of the case is still open.
 * - Close: only a recommended case, by the case's lead practitioner, or by
 *   whoever runs the clinic (the owner with the owner's privileges, or a
 *   member who manages staff).
 * - Reopen: a closed case, by the same authority, with a reason.
 *
 * Every step keeps the case's visits, treatments, packages and sessions as
 * they are, and is written to the patient's timeline and the security log
 * with who did it; the case row keeps its discharge summary.
 */
class PatientCaseLifecycle
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected OwnerMode $ownerMode,
        protected SecurityAudit $audit,
        protected SpecialtyRegistry $specialties,
        protected PatientBranchAccess $branchAccess,
    ) {}

    /** @return array{recommend: bool, close: bool, reopen: bool} */
    public function abilities(PatientCase $case, User $user): array
    {
        if (! $this->inReach($case)) {
            return ['recommend' => false, 'close' => false, 'reopen' => false];
        }

        return [
            'recommend' => $case->status === PatientCase::ACTIVE && $this->treats($case, $user) && $this->physioDischarged($case),
            'close' => $case->status === PatientCase::COMPLETION_RECOMMENDED && $this->mayClose($case, $user),
            'reopen' => $case->status === PatientCase::CLOSED && $this->mayClose($case, $user),
        ];
    }

    public function recommend(PatientCase $case, User $user, ?string $note): PatientCase
    {
        return DB::transaction(function () use ($case, $user, $note) {
            $case = $this->lock($case);
            if ($case->status !== PatientCase::ACTIVE) {
                throw ValidationException::withMessages(['case' => 'Only an active case can be recommended for completion.']);
            }
            if (! $this->inReach($case) || ! $this->treats($case, $user)) {
                throw new AuthorizationException('Only a practitioner treating this case can recommend completing it.');
            }
            if (! $this->physioDischarged($case)) {
                throw ValidationException::withMessages(['case' => 'Discharge the physiotherapy plan of care first; its reassessment decides whether the case is complete.']);
            }

            $case->update(['status' => PatientCase::COMPLETION_RECOMMENDED]);
            $this->record($case, 'case_completion_recommended', $user, ['note' => $this->clean($note)]);

            return $case;
        });
    }

    public function close(PatientCase $case, User $user, ?string $summary): PatientCase
    {
        return DB::transaction(function () use ($case, $user, $summary) {
            $case = $this->lock($case);
            if ($case->status !== PatientCase::COMPLETION_RECOMMENDED) {
                throw ValidationException::withMessages(['case' => 'A case is closed once its treating practitioner has recommended completing it.']);
            }
            if (! $this->inReach($case) || ! $this->mayClose($case, $user)) {
                throw new AuthorizationException('Only the case\'s lead practitioner or the clinic\'s management can close it.');
            }

            $summary = $this->clean($summary);
            $case->update(['status' => PatientCase::CLOSED, 'closed_at' => ClinicClock::now(), 'discharge_summary' => $summary ?? $case->discharge_summary]);
            $this->record($case, 'case_closed', $user, ['summary' => $summary]);

            return $case;
        });
    }

    public function reopen(PatientCase $case, User $user, string $reason): PatientCase
    {
        $reason = $this->clean($reason);
        if ($reason === null || mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Say why the case is reopened.']);
        }

        return DB::transaction(function () use ($case, $user, $reason) {
            $case = $this->lock($case);
            if ($case->status !== PatientCase::CLOSED) {
                throw ValidationException::withMessages(['case' => 'Only a closed case can be reopened.']);
            }
            if (! $this->inReach($case) || ! $this->mayClose($case, $user)) {
                throw new AuthorizationException('Only the case\'s lead practitioner or the clinic\'s management can reopen it.');
            }

            // The closing date and summary stay in the timeline; the case is open again.
            $case->update(['status' => PatientCase::ACTIVE, 'closed_at' => null]);
            $this->record($case, 'case_reopened', $user, ['reason' => $reason]);

            return $case;
        });
    }

    /**
     * The patient treatments that belong to the case: those started in a
     * consultation of one of its visits. Treatments started elsewhere are not
     * guessed into a case.
     *
     * @param  Collection<int, PatientCase>  $cases
     * @return Collection<int, Collection<int, int>> case id => treatment ids
     */
    public function treatmentIdsByCase(Collection $cases): Collection
    {
        if ($cases->isEmpty()) {
            return collect();
        }
        $visitCase = PatientVisit::query()->withoutGlobalScopes()->whereIn('case_id', $cases->pluck('id'))->pluck('case_id', 'id');
        $encounterCase = $visitCase->isEmpty() ? collect() : ClinicalEncounter::query()->withoutGlobalScopes()
            ->whereIn('patient_visit_id', $visitCase->keys())->pluck('patient_visit_id', 'id')
            ->map(fn ($visitId) => $visitCase[$visitId]);

        return $encounterCase->isEmpty() ? collect() : PatientTreatment::query()->withoutGlobalScopes()
            ->whereIn('encounter_id', $encounterCase->keys())->get(['id', 'encounter_id'])
            ->groupBy(fn (PatientTreatment $treatment) => $encounterCase[$treatment->encounter_id])
            ->map(fn (Collection $rows) => $rows->pluck('id')->values());
    }

    public function isPhysio(PatientCase $case): bool
    {
        return in_array('soap_notes', $this->specialties->get((string) $case->specialty)['extensions'] ?? [], true);
    }

    /** A physiotherapy case is ready only when none of its treatments is still under way; any other case always is. */
    private function physioDischarged(PatientCase $case): bool
    {
        if (! $this->isPhysio($case)) {
            return true;
        }
        $ids = $this->treatmentIdsByCase(collect([$case]))->get($case->id, collect());

        return $ids->isEmpty() || ! PatientTreatment::query()->withoutGlobalScopes()->whereIn('id', $ids)
            ->whereIn('status', [PatientTreatment::PLANNED, PatientTreatment::IN_PROGRESS, PatientTreatment::PAUSED])->exists();
    }

    private function treats(PatientCase $case, User $user): bool
    {
        $me = $this->me();
        if ($me === null || $me->practitionerProfile === null || ! $user->hasClinicPermission(ClinicPermission::ClinicalEdit)) {
            return false;
        }

        return $case->lead_practitioner_id === $me->id
            || PatientVisit::query()->withoutGlobalScopes()->where('case_id', $case->id)->where('doctor_id', $me->id)->exists();
    }

    private function mayClose(PatientCase $case, User $user): bool
    {
        if (($this->ownerMode->isOwner() && $this->ownerMode->authorized()) || $user->hasClinicPermission(ClinicPermission::StaffManage)) {
            return true;
        }
        $me = $this->me();

        return $me !== null && $case->lead_practitioner_id === $me->id && $user->hasClinicPermission(ClinicPermission::ClinicalEdit);
    }

    /** The case is this clinic's and at a branch this member works in. */
    private function inReach(PatientCase $case): bool
    {
        $branchIds = $this->branchAccess->branchIds();

        return $case->clinic_id === $this->clinicContext->clinic()?->id && ($branchIds === null || in_array($case->branch_id, $branchIds, true));
    }

    private function me(): ?ClinicEmployee
    {
        $me = $this->clinicContext->currentEmployee();
        $me?->loadMissing(['practitionerProfile' => fn ($query) => $query->withoutGlobalScopes()]);

        return $me;
    }

    private function lock(PatientCase $case): PatientCase
    {
        return PatientCase::query()->withoutGlobalScopes()->whereKey($case->id)->lockForUpdate()->firstOrFail();
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, 2000);
    }

    /** @param  array<string, mixed>  $context */
    private function record(PatientCase $case, string $event, User $user, array $context): void
    {
        $patient = Patient::query()->withoutGlobalScopes()->findOrFail($case->patient_id);
        PatientTimelineEvent::create([
            'organization_id' => $this->clinicContext->clinic()?->organization_id,
            'clinic_id' => $case->clinic_id,
            'patient_id' => $patient->id,
            'actor_id' => $user->id,
            'event_type' => $event,
            'context' => array_filter(['case_id' => $case->id, 'title' => $case->title, 'specialty' => $case->specialty, 'by' => $user->name, ...$context], fn ($value) => $value !== null),
        ]);
        $this->audit->record("patient.{$event}", $case, ['patient_id' => $patient->id, ...array_filter($context, fn ($value) => $value !== null)]);
    }
}
