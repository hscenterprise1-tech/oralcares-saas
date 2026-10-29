<?php

namespace App\Services\Physio;

use App\ClinicPermission;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientSessionPackage;
use App\Models\PatientTimelineEvent;
use App\Models\PatientTreatment;
use App\Models\PhysioSession;
use App\Models\SessionPackageUsage;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Patient\PatientCareGuard;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Prepaid session packages and their entitlement ledger.
 *
 * Every package belongs to exactly one patient treatment: a Knee package can
 * only ever pay for Knee sessions. The balance is the ledger, never a
 * number someone typed:
 *
 *     remaining = sessions sold + Σ entries
 *     (consume -1 · reversal +1 · adjustment ±n)
 *
 * `sessions_used` / `sessions_adjusted` on the package are caches of the
 * ledger, rewritten only here. Each consumption and reversal carries an
 * idempotency key, so retrying a completion never consumes twice.
 *
 * Extension points (no policy yet): pause (a status that `eligible()`
 * excludes), validity extension and transfer/refund (adjustment entries plus
 * an audited `valid_until` change).
 */
class SessionPackages
{
    public function __construct(
        private BillingService $billing,
        private SecurityAudit $audit,
    ) {}

    /**
     * Sell a package for one treatment. With a price it is raised on the
     * canonical clinic bill, against that treatment. A renewal names the
     * package it follows; the old one, its usage and its bill stay as they are.
     *
     * @param  array{name: string, sessions_total: int, valid_until?: ?string, clinical_procedure_id?: ?int, price?: ?float, renews_package_id?: ?int}  $data
     */
    public function sell(PatientTreatment $treatment, Branch $branch, array $data, ClinicEmployee $seller): PatientSessionPackage
    {
        if ($treatment->clinic_id !== $branch->clinic_id || $treatment->clinic_id !== $seller->clinic_id) {
            throw new InvalidArgumentException('The treatment, branch and seller must belong to one clinic.');
        }
        if (in_array($treatment->status, [PatientTreatment::COMPLETED, PatientTreatment::CANCELLED], true)) {
            throw new DomainException('This treatment is closed; start or reopen a treatment before selling a package for it.');
        }
        if ($patient = Patient::query()->withoutGlobalScopes()->where('clinic_id', $treatment->clinic_id)->find($treatment->patient_id)) {
            app(PatientCareGuard::class)->assertCanReceiveCare($patient);
        }
        $sessions = (int) ($data['sessions_total'] ?? 0);
        if ($sessions < 1 || $sessions > 200) {
            throw new InvalidArgumentException('A package has between 1 and 200 sessions.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Name the package.');
        }

        return DB::transaction(function () use ($treatment, $branch, $data, $sessions, $name, $seller) {
            $renews = null;
            if (! empty($data['renews_package_id'])) {
                $renews = PatientSessionPackage::query()->withoutGlobalScopes()->whereKey($data['renews_package_id'])->lockForUpdate()->first();
                if (! $renews || $renews->clinic_id !== $treatment->clinic_id || $renews->patient_treatment_id !== $treatment->id) {
                    throw new InvalidArgumentException('A renewal follows a package of the same treatment.');
                }
            }

            $package = PatientSessionPackage::query()->withoutGlobalScopes()->create([
                'clinic_id' => $treatment->clinic_id,
                'branch_id' => $branch->id,
                'patient_id' => $treatment->patient_id,
                'patient_treatment_id' => $treatment->id,
                'renews_package_id' => $renews?->id,
                'clinical_procedure_id' => $data['clinical_procedure_id'] ?? null,
                'name' => mb_substr($name, 0, 150),
                'sessions_total' => $sessions,
                'valid_until' => $data['valid_until'] ?? null,
                'status' => PatientSessionPackage::ACTIVE,
                'sold_by' => $seller->id,
            ]);

            $price = (float) ($data['price'] ?? 0);
            if ($price > 0) {
                $patient = Patient::query()->withoutGlobalScopes()->findOrFail($treatment->patient_id);
                $bill = $this->billing->createBill(Clinic::query()->findOrFail($patient->clinic_id), $patient, $branch, [[
                    'description' => "{$package->name} ({$sessions} sessions)",
                    'quantity' => 1,
                    'unit_price' => $price,
                    'clinical_procedure_id' => $package->clinical_procedure_id,
                    'patient_treatment_id' => $treatment->id,
                ]], creator: $seller);
                $package->update(['patient_billing_id' => $bill->id]);
            }

            $this->timeline($package, 'session_package_sold', ['package_id' => $package->id, 'name' => $package->name, 'sessions' => $sessions, 'renews_package_id' => $renews?->id]);
            $this->audit->record('package.sold', $package, ['patient_treatment_id' => $treatment->id, 'sessions' => $sessions, 'renews_package_id' => $renews?->id], ['clinic_id' => $package->clinic_id]);

            return $package;
        });
    }

    /** The package's authoritative remaining sessions, from the ledger. */
    public function balance(PatientSessionPackage $package): int
    {
        return $package->sessions_total + (int) SessionPackageUsage::query()->withoutGlobalScopes()
            ->where('clinic_id', $package->clinic_id)->where('package_id', $package->id)->sum('quantity');
    }

    /**
     * Packages that may pay for a session of this treatment now: the
     * treatment's own, active, in date, with sessions left. Never another
     * treatment's. One query for the packages, one for their ledgers.
     *
     * @return Collection<int, array{package: PatientSessionPackage, remaining: int}>
     */
    public function eligible(PatientTreatment $treatment): Collection
    {
        $packages = PatientSessionPackage::query()->withoutGlobalScopes()
            ->where('clinic_id', $treatment->clinic_id)->where('patient_treatment_id', $treatment->id)
            ->where('status', PatientSessionPackage::ACTIVE)->orderBy('valid_until')->orderBy('id')->get();
        $sums = SessionPackageUsage::query()->withoutGlobalScopes()->where('clinic_id', $treatment->clinic_id)
            ->whereIn('package_id', $packages->pluck('id'))->groupBy('package_id')->selectRaw('package_id, sum(quantity) as total')->pluck('total', 'package_id');

        return $packages
            ->reject(fn (PatientSessionPackage $package) => $package->isExpired())
            ->map(fn (PatientSessionPackage $package) => ['package' => $package, 'remaining' => $package->sessions_total + (int) ($sums[$package->id] ?? 0)])
            ->filter(fn (array $row) => $row['remaining'] > 0)
            ->values();
    }

    /**
     * Whether a session package paid for this consultation: a session in it
     * was completed against a package of that session's own treatment, and
     * the completion still stands (a reversed session is no longer
     * completed). The one rule for a package waiving the consultation fee —
     * holding a package, or one for another treatment, waives nothing, and a
     * visit whose treatment is not known yet (at check-in) is never waived.
     */
    public function paidFor(ClinicalEncounter $encounter): bool
    {
        return PhysioSession::query()->withoutGlobalScopes()
            ->where('physio_sessions.clinic_id', $encounter->clinic_id)
            ->where('physio_sessions.encounter_id', $encounter->id)
            ->where('physio_sessions.status', PhysioSession::COMPLETED)
            ->whereExists(fn ($package) => $package->selectRaw('1')->from('patient_session_packages')
                ->whereColumn('patient_session_packages.id', 'physio_sessions.package_id')
                ->whereColumn('patient_session_packages.clinic_id', 'physio_sessions.clinic_id')
                ->whereColumn('patient_session_packages.patient_treatment_id', 'physio_sessions.patient_treatment_id'))
            ->exists();
    }

    /**
     * Consume one session from a package, for a session being completed.
     * Called inside the completion's transaction with the session locked.
     * Idempotent: the same completion (keyed by session and cycle) never
     * writes a second entry.
     */
    public function consume(PhysioSession $session, int $packageId, ?ClinicEmployee $performer, ?User $by): PatientSessionPackage
    {
        $package = PatientSessionPackage::query()->withoutGlobalScopes()->whereKey($packageId)->lockForUpdate()->first();
        if (! $package || $package->clinic_id !== $session->clinic_id || $package->patient_id !== $session->patient_id) {
            throw new InvalidArgumentException('That package is not this patient\'s.');
        }
        if ($session->patient_treatment_id === null || $package->patient_treatment_id !== $session->patient_treatment_id) {
            throw new InvalidArgumentException('That package belongs to a different treatment.');
        }
        if ($package->status !== PatientSessionPackage::ACTIVE) {
            throw new DomainException($package->status === PatientSessionPackage::EXHAUSTED ? 'That package has no sessions left.' : 'That package cannot be used.');
        }
        if ($package->isExpired()) {
            throw new DomainException('That package has expired.');
        }

        $cycle = $this->cycle($session);
        $key = "session:{$session->id}:consume:{$cycle}";
        if (SessionPackageUsage::query()->withoutGlobalScopes()->where('entry_key', $key)->exists()) {
            return $package;
        }
        if ($this->balance($package) < 1) {
            throw new DomainException('That package has no sessions left.');
        }

        SessionPackageUsage::query()->withoutGlobalScopes()->create([
            'clinic_id' => $package->clinic_id,
            'package_id' => $package->id,
            'physio_session_id' => $session->id,
            'entry_type' => SessionPackageUsage::CONSUME,
            'quantity' => -1,
            'entry_key' => $key,
            'used_by' => $performer?->id,
            'created_by' => $by?->id,
        ]);

        return $this->refresh($package);
    }

    /** Give back the session's consumption, if it has one not yet reversed. */
    public function reverseFor(PhysioSession $session, string $reason, ?ClinicEmployee $performer, ?User $by): ?PatientSessionPackage
    {
        $entries = SessionPackageUsage::query()->withoutGlobalScopes()->where('clinic_id', $session->clinic_id)
            ->where('physio_session_id', $session->id)->whereIn('entry_type', [SessionPackageUsage::CONSUME, SessionPackageUsage::REVERSAL])->get();
        $net = (int) $entries->sum('quantity');
        if ($net >= 0) {
            return null; // nothing consumed, or already given back
        }
        $consumed = $entries->where('entry_type', SessionPackageUsage::CONSUME)->sortByDesc('id')->first();
        $package = PatientSessionPackage::query()->withoutGlobalScopes()->whereKey($consumed->package_id)->lockForUpdate()->firstOrFail();

        SessionPackageUsage::query()->withoutGlobalScopes()->create([
            'clinic_id' => $package->clinic_id,
            'package_id' => $package->id,
            'physio_session_id' => $session->id,
            'entry_type' => SessionPackageUsage::REVERSAL,
            'quantity' => 1,
            'reason' => mb_substr($reason, 0, 500),
            'entry_key' => "session:{$session->id}:reversal:".$this->cycle($session),
            'used_by' => $performer?->id,
            'created_by' => $by?->id,
        ]);

        return $this->refresh($package);
    }

    /**
     * An audited manual change to a package's entitlement (goodwill session,
     * correction). Needs billing rights and a reason; never takes the balance
     * below zero.
     */
    public function adjust(PatientSessionPackage $package, int $quantity, string $reason, User $by): PatientSessionPackage
    {
        if (! $by->hasClinicPermission(ClinicPermission::BillingCreate)) {
            throw new AuthorizationException('Adjusting a package needs billing rights.');
        }
        $reason = trim($reason);
        if ($quantity === 0 || abs($quantity) > 200) {
            throw new InvalidArgumentException('Adjust by between 1 and 200 sessions, up or down.');
        }
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('Give a reason for the adjustment.');
        }

        return DB::transaction(function () use ($package, $quantity, $reason, $by) {
            $locked = PatientSessionPackage::query()->withoutGlobalScopes()->whereKey($package->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PatientSessionPackage::CANCELLED) {
                throw new DomainException('A cancelled package cannot be adjusted.');
            }
            if ($this->balance($locked) + $quantity < 0) {
                throw new DomainException('That would leave the package below zero sessions.');
            }

            SessionPackageUsage::query()->withoutGlobalScopes()->create([
                'clinic_id' => $locked->clinic_id,
                'package_id' => $locked->id,
                'entry_type' => SessionPackageUsage::ADJUSTMENT,
                'quantity' => $quantity,
                'reason' => mb_substr($reason, 0, 500),
                'created_by' => $by->id,
            ]);
            $locked = $this->refresh($locked);
            $this->audit->record('package.adjusted', $locked, ['quantity' => $quantity, 'reason' => $reason, 'remaining' => $locked->remaining()], ['clinic_id' => $locked->clinic_id]);

            return $locked;
        });
    }

    /** Cancel a package nothing has been taken from. */
    public function cancel(PatientSessionPackage $package): PatientSessionPackage
    {
        return DB::transaction(function () use ($package) {
            $locked = PatientSessionPackage::query()->withoutGlobalScopes()->whereKey($package->id)->lockForUpdate()->firstOrFail();
            $consumed = SessionPackageUsage::query()->withoutGlobalScopes()->where('package_id', $locked->id)
                ->whereIn('entry_type', [SessionPackageUsage::CONSUME, SessionPackageUsage::REVERSAL])->sum('quantity');
            if ((int) $consumed < 0) {
                throw new DomainException('A package with sessions used cannot be cancelled.');
            }
            $locked->update(['status' => PatientSessionPackage::CANCELLED]);

            return $locked;
        });
    }

    /** Rewrite the package's cached totals and status from its ledger. */
    private function refresh(PatientSessionPackage $package): PatientSessionPackage
    {
        $rows = SessionPackageUsage::query()->withoutGlobalScopes()->where('clinic_id', $package->clinic_id)->where('package_id', $package->id)
            ->groupBy('entry_type')->selectRaw('entry_type, sum(quantity) as total')->pluck('total', 'entry_type');
        $used = -((int) ($rows[SessionPackageUsage::CONSUME] ?? 0) + (int) ($rows[SessionPackageUsage::REVERSAL] ?? 0));
        $adjusted = (int) ($rows[SessionPackageUsage::ADJUSTMENT] ?? 0);
        $remaining = $package->sessions_total + $adjusted - $used;

        $status = $package->status;
        if (in_array($status, [PatientSessionPackage::ACTIVE, PatientSessionPackage::EXHAUSTED], true)) {
            $status = $remaining > 0 ? PatientSessionPackage::ACTIVE : PatientSessionPackage::EXHAUSTED;
        }
        $package->forceFill(['sessions_used' => $used, 'sessions_adjusted' => $adjusted, 'status' => $status])->save();

        return $package;
    }

    /** Which completion of the session this is: one more than its reversals. */
    private function cycle(PhysioSession $session): int
    {
        return 1 + SessionPackageUsage::query()->withoutGlobalScopes()->where('clinic_id', $session->clinic_id)
            ->where('physio_session_id', $session->id)->where('entry_type', SessionPackageUsage::REVERSAL)->count();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function timeline(PatientSessionPackage $package, string $event, array $context): void
    {
        PatientTimelineEvent::query()->withoutGlobalScopes()->create([
            'organization_id' => Patient::query()->withoutGlobalScopes()->whereKey($package->patient_id)->value('organization_id'),
            'clinic_id' => $package->clinic_id,
            'patient_id' => $package->patient_id,
            'actor_id' => auth()->id(),
            'event_type' => $event,
            'context' => $context,
        ]);
    }
}
