<?php

namespace App\Services\CRM;

use App\ActivityType;
use App\LeadStatus;
use App\LostReason;
use App\Models\Trial;
use App\Models\TrialUsageSnapshot;
use App\Models\User;
use App\Services\Provisioning\EntitlementResolver;
use App\Support\CrmScope;
use App\TrialStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Everything one trial page needs, assembled server-side.
 *
 * The contract lives here rather than being spread across a controller and a
 * component. Two reasons, both learned the hard way on the deal pages: a client
 * that keeps its own copy of the payload's shape typechecks perfectly and
 * renders blank when the server changes, and permissions computed in the
 * browser are decoration rather than authorisation.
 *
 * So the server decides what may be done — {@see self::abilities()} — and the
 * client is told. `trial.ts` mirrors this structure and nothing else does.
 *
 * Usage figures come from {@see TrialUsageSnapshot}, never from the trial row.
 * The columns on `trials` that look like usage were writable through the update
 * endpoint and written by nothing, so a number there was whatever somebody last
 * typed.
 */
class TrialPageService
{
    public function __construct(
        protected TrialLifecycleService $lifecycle,
        protected EntitlementResolver $entitlements,
        protected TrialHealthService $health,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(Trial $trial, ?User $user = null): array
    {
        $trial->loadMissing([
            'lead:id,company_name,contact_name,phone,email,status,organization_id',
            'assignedUser:id,name,email',
            'companyPlan:id,key,name,max_users,max_doctors,max_branches,storage_limit_gb',
            'clinic:id,name,slug,status',
        ]);

        $snapshot = $this->latestSnapshot($trial);

        return [
            'trial' => $this->trial($trial),
            'window' => $this->window($trial),
            'provisioning' => $this->provisioning($trial),
            'usage' => $this->usage($snapshot),
            'usageTrend' => $this->trend($trial),
            'history' => $this->history($trial),
            'can' => $this->abilities($trial, $user),
            'convertBlockers' => $this->convertBlockers($trial),
            /*
             * Sent with the page rather than hard-coded in the modal, for the
             * same reason the list sends it: the dropdown's options and what the
             * endpoint accepts have to be one list.
             */
            'lostReasons' => collect(LostReason::cases())
                ->map(fn (LostReason $reason) => ['value' => $reason->value, 'label' => $reason->value])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function trial(Trial $trial): array
    {
        $status = $this->lifecycle->resolveStatus($trial);

        return [
            'id' => $trial->id,
            /*
             * The derived status, not the stored one. They agree after a
             * scheduler pass; between passes the derived value is the true one,
             * and a page that showed "active" for a trial that ended on Sunday
             * is exactly the drift this replaces.
             */
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'statusColor' => $status->color(),
            'startDate' => $trial->start_date?->toDateString(),
            'endDate' => $trial->end_date?->toDateString(),
            'graceEndsAt' => $trial->grace_ends_at?->toISOString(),
            'durationDays' => $trial->duration_days,
            'extensionCount' => $trial->extension_count,
            'originalEndDate' => $trial->original_end_date?->toDateString(),
            'extensionReason' => $trial->extension_reason,
            'notes' => $trial->notes,
            'outcome' => $trial->outcome?->value,
            'feedback' => $trial->feedback,
            'cancelledAt' => $trial->cancelled_at?->toISOString(),
            'cancellationReason' => $trial->cancellation_reason,
            /* How the evaluation ended, when it ended badly. See row(). */
            'lost' => $trial->lost_at ? [
                'at' => $trial->lost_at->toDateString(),
                'reason' => $trial->lost_reason,
                'competitor' => $trial->lost_competitor,
            ] : null,
            /* The live cache, alongside the nightly history in `usage`. */
            'telemetry' => $trial->usage_telemetry ?: null,
            'plan' => $trial->companyPlan ? [
                'id' => $trial->companyPlan->id,
                'key' => $trial->companyPlan->key,
                'name' => $trial->companyPlan->name,
            ] : null,
            'lead' => $trial->lead ? [
                'id' => $trial->lead->id,
                'companyName' => $trial->lead->company_name,
                'contactName' => $trial->lead->contact_name,
                'status' => $trial->lead->status?->value,
            ] : null,
            'owner' => $trial->assignedUser ? [
                'id' => $trial->assignedUser->id,
                'name' => $trial->assignedUser->name,
            ] : null,
        ];
    }

    /**
     * How long this tenant may keep working, from the one authority on it.
     *
     * @return array<string, mixed>
     */
    protected function window(Trial $trial): array
    {
        if (! $trial->clinic) {
            return ['state' => 'none', 'daysRemaining' => null, 'grantsAccess' => false, 'limits' => null, 'modules' => [], 'whatsappQuota' => null];
        }

        $entitlement = $this->entitlements->forClinic($trial->clinic);

        return [
            'state' => $entitlement['window']['state'],
            'daysRemaining' => $entitlement['window']['days_remaining'],
            'grantsAccess' => $entitlement['grants_access'],
            'limits' => $entitlement['limits'],
            'modules' => $entitlement['modules'],
            /*
             * Always null today, and shown as unavailable rather than as zero.
             * No plan, account or licence column defines an allowance.
             */
            'whatsappQuota' => $entitlement['whatsapp_quota'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function provisioning(Trial $trial): array
    {
        $handover = $trial->onboarding_checklist['handover'] ?? null;

        return [
            'isProvisioned' => $trial->isProvisioned(),
            'provisionedAt' => $trial->provisioned_at?->toISOString(),
            'error' => $trial->provisioning_error,
            'clinic' => $trial->clinic ? [
                'id' => $trial->clinic->id,
                'name' => $trial->clinic->name,
                'slug' => $trial->clinic->slug,
            ] : null,
            'handover' => $handover ? [
                'delivered' => (bool) ($handover['delivered'] ?? false),
                'channel' => $handover['channel'] ?? null,
                'at' => $handover['at'] ?? null,
            ] : null,
        ];
    }

    /**
     * Measured usage, or an explicit absence of it.
     *
     * Null when no snapshot has been taken yet. A page that renders zeros for
     * "not measured" tells the rep the customer did nothing, which is a
     * different and much more damaging claim.
     *
     * @return array<string, mixed>|null
     */
    protected function usage(?TrialUsageSnapshot $snapshot): ?array
    {
        if (! $snapshot) {
            return null;
        }

        return [
            'capturedAt' => $snapshot->captured_at?->toISOString(),
            'users' => [
                'total' => $snapshot->users_total,
                'active7d' => $snapshot->users_active_7d,
                'logins7d' => $snapshot->logins_7d,
                'lastLoginAt' => $snapshot->last_login_at?->toISOString(),
            ],
            'records' => [
                'patients' => $snapshot->patients_count,
                'appointments' => $snapshot->appointments_count,
                'appointments7d' => $snapshot->appointments_7d,
                'encounters' => $snapshot->encounters_count,
                'prescriptions' => $snapshot->prescriptions_count,
                'invoices' => $snapshot->invoices_count,
            ],
            'branches' => $snapshot->branches_count,
            'storage' => [
                'bytes' => $snapshot->storage_bytes,
                'limitMb' => $snapshot->storage_limit_mb,
                'percent' => $snapshot->storagePercent(),
            ],
            'modules' => [
                'enabled' => $snapshot->modules_enabled ?: [],
                'used' => $snapshot->modules_used ?: [],
                /*
                 * The gap is the commercially useful part: what they are being
                 * given and have not touched.
                 */
                'unused' => array_values(array_diff($snapshot->modules_enabled ?: [], $snapshot->modules_used ?: [])),
            ],
            'onboarding' => [
                'readiness' => $snapshot->onboarding_readiness,
                'checks' => $snapshot->onboarding_checks ?: [],
            ],
            'whatsapp' => [
                'sent' => $snapshot->whatsapp_sent_count,
                'quota' => $snapshot->whatsapp_quota,
            ],
        ];
    }

    /**
     * The last fortnight of snapshots, oldest first.
     *
     * "They were busy in week one and stopped" is the most useful thing to know
     * about a trial, and it is invisible in a single figure.
     *
     * @return list<array<string, mixed>>
     */
    protected function trend(Trial $trial): array
    {
        return TrialUsageSnapshot::where('trial_id', $trial->id)
            ->orderByDesc('captured_at')
            ->limit(14)
            ->get()
            ->reverse()
            ->map(fn (TrialUsageSnapshot $s) => [
                'capturedAt' => $s->captured_at?->toDateString(),
                'activeUsers' => $s->users_active_7d,
                'logins' => $s->logins_7d,
                'appointments' => $s->appointments_7d,
                'patients' => $s->patients_count,
                'readiness' => $s->onboarding_readiness,
            ])
            ->values()
            ->all();
    }

    /**
     * What this user may actually do, decided here.
     *
     * Every flag is a real Gate check against the policy, so the buttons a page
     * renders and the actions the server will accept cannot disagree.
     *
     * @return array<string, bool>
     */
    protected function abilities(Trial $trial, ?User $user): array
    {
        if (! $user) {
            return [
                'update' => false, 'provision' => false, 'extend' => false, 'cancel' => false,
                'complete' => false, 'delete' => false, 'convert' => false,
                'markLost' => false, 'syncUsage' => false,
            ];
        }

        $provision = Gate::forUser($user)->allows('provision', $trial);

        return [
            'update' => Gate::forUser($user)->allows('update', $trial),
            'provision' => $provision,
            /* Extending and cancelling move a live tenant's access window. */
            'extend' => $provision,
            'cancel' => $provision,
            'complete' => Gate::forUser($user)->allows('complete', $trial),
            'delete' => Gate::forUser($user)->allows('delete', $trial),
            'convert' => $this->canConvert($user, $trial),
            // Once, and only while the evaluation is still open.
            'markLost' => $provision && $trial->lost_at === null,
            /*
             * Re-measuring is a read of the customer's own tenant, so it takes
             * the read permission — and only means anything once there is an
             * environment to measure.
             */
            'syncUsage' => Gate::forUser($user)->allows('view', $trial) && $trial->isProvisioned(),
        ];
    }

    /**
     * The deal's timeline, filtered to this trial.
     *
     * A deal's full history runs to dozens of entries across months; almost
     * none of it is about the trial. Filtering to entries that name this trial,
     * plus the trial-typed ones, is what makes the zone worth reading — an
     * unfiltered copy of the deal timeline would just be a second place to
     * scroll past the same thing.
     *
     * @return list<array<string, mixed>>
     */
    protected function history(Trial $trial): array
    {
        $lead = $trial->lead;

        if (! $lead) {
            return [];
        }

        return $lead->activities()
            ->with('user:id,name')
            ->where(function ($q) use ($trial) {
                $q->where('type', ActivityType::Trial)
                    ->orWhere('properties->trial_id', $trial->id);
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn ($activity) => [
                'id' => $activity->id,
                'type' => $activity->type?->value ?? (string) $activity->type,
                'description' => $activity->description,
                'at' => $activity->created_at?->toISOString(),
                'by' => $activity->user?->name,
            ])
            ->all();
    }

    protected function latestSnapshot(Trial $trial): ?TrialUsageSnapshot
    {
        return TrialUsageSnapshot::where('trial_id', $trial->id)
            ->orderByDesc('captured_at')
            ->first();
    }

    /**
     * Whether this user may close the deal behind the trial.
     *
     * Winning is a deal-side action with a deal-side policy, so the check is
     * against the lead rather than the trial. Whether the commercial
     * preconditions are met is a separate question — see
     * {@see self::convertBlockers()}, which the UI shows instead of letting
     * someone press a button that will only flash an error back.
     */
    protected function canConvert(User $user, Trial $trial): bool
    {
        $lead = $trial->lead;

        if (! $lead || $lead->status === LeadStatus::Won || $lead->status === LeadStatus::Lost) {
            return false;
        }

        return Gate::forUser($user)->allows('update', $lead);
    }

    /**
     * Why converting would be refused right now, in the words the rep needs.
     *
     * The convert endpoint requires an approved quotation settled in full.
     * Those are commercial facts, not permissions, and discovering them by
     * pressing a button and reading an error flash is a worse experience than
     * being told up front what is missing.
     *
     * This must state exactly what `LeadController::convert()` enforces —
     * listing a blocker the endpoint does not check would refuse a conversion
     * the endpoint would allow, which is the shape of bug this replaces.
     *
     * @return list<string>
     */
    public function convertBlockers(Trial $trial): array
    {
        $lead = $trial->lead;

        if (! $lead) {
            return ['This trial is not linked to a deal.'];
        }

        $blockers = [];

        /* Deals closed under the old flow still qualify on their agreement. */
        $legacyClosed = $lead->hasAcceptedAgreement() && $lead->hasSuccessfulPayment();

        if (! $lead->hasApprovedPaidQuotation() && ! $legacyClosed) {
            $blockers[] = $lead->hasSuccessfulPayment()
                ? 'No approved quotation settled in full yet.'
                : 'No approved quotation settled in full yet — approve a quotation and record payment against it.';
        }

        return $blockers;
    }

    /**
     * The trials list, ordered by how much attention each one needs.
     *
     * Urgency first, deliberately. A trials board sorted by date buries the
     * customer whose access runs out on Friday underneath twenty that end next
     * month; sorted by urgency, the ones that can still be saved are at the
     * top. Within a phase the nearest deadline leads.
     *
     * @return array<string, mixed>
     */
    public function listPayload(?User $user, array $filters = []): array
    {
        $trials = CrmScope::trials($user)
            ->with([
                'lead:id,company_name,contact_name,status,organization_id',
                'assignedUser:id,name',
                'companyPlan:id,key,name',
                'clinic:id,name,slug',
            ])
            ->get();

        $snapshots = $this->latestSnapshots($trials->pluck('id')->all());

        $rows = $trials
            ->map(fn (Trial $trial) => $this->row($trial, $user, $snapshots[$trial->id] ?? null))
            ->sortBy([
                fn (array $a, array $b) => $a['urgency'] <=> $b['urgency'],
                /* Nearest deadline first; trials with no deadline sink. */
                fn (array $a, array $b) => ($a['daysRemaining'] ?? PHP_INT_MAX) <=> ($b['daysRemaining'] ?? PHP_INT_MAX),
            ])
            ->values();

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $rows = $rows->filter(fn (array $row) => str_contains(
                strtolower((string) $row['company'].' '.(string) $row['owner']),
                strtolower($search),
            ))->values();
        }

        if (! empty($filters['phase'])) {
            $rows = $rows->filter(fn (array $row) => $row['phase'] === $filters['phase'])->values();
        }

        return [
            'rows' => $rows->all(),
            'counts' => [
                'grace' => $rows->where('phase', 'in_grace')->count(),
                'endingSoon' => $rows->where('phase', 'ending_soon')->count(),
                'running' => $rows->whereIn('phase', ['active', 'extended'])->count(),
                'preparing' => $rows->where('phase', 'preparing')->count(),
                'failed' => $rows->filter(fn ($r) => $r['provisioning']['error'] !== null)->count(),
                'closed' => $rows->whereIn('phase', TrialHealthService::CLOSED_PHASES)->count(),
                // Counted separately from `closed`: every lost trial is closed,
                // but the interesting number is how many we actually lost.
                'lost' => $rows->filter(fn ($r) => $r['lost'] !== null)->count(),
            ],
            /*
             * The watchtower's four headline figures.
             *
             * Deliberately not the same cut as `counts` above, which describes
             * lifecycle phase. These describe *engagement* — the question a
             * renewals conversation actually turns on — and they are computed
             * from the same rows the list renders, so a card and the rows
             * beneath it cannot disagree.
             */
            'health' => $this->healthCounts($rows),
            'filters' => ['search' => $search, 'phase' => $filters['phase'] ?? null],
            /*
             * The loss vocabulary, from the same enum the deal uses, so the
             * modal's dropdown and what the endpoint accepts cannot drift.
             */
            'lostReasons' => collect(LostReason::cases())
                ->map(fn (LostReason $reason) => ['value' => $reason->value, 'label' => $reason->value])
                ->values()
                ->all(),
        ];
    }

    /**
     * One row of the list.
     *
     * @return array<string, mixed>
     */
    protected function row(Trial $trial, ?User $user, ?TrialUsageSnapshot $snapshot): array
    {
        $status = $this->lifecycle->resolveStatus($trial);
        $window = $trial->clinic ? $this->entitlements->forClinic($trial->clinic) : null;

        return [
            'id' => $trial->id,
            'company' => $trial->lead?->company_name ?: $trial->lead?->contact_name ?: 'Unnamed',
            'leadId' => $trial->lead_id,
            'phase' => $status->value,
            'phaseLabel' => $status->label(),
            'urgency' => $this->urgency($status),
            /*
             * The window's own state travels with the day count, because after
             * a deal is won that count is a paid term rather than trial days
             * and the two read very differently.
             */
            'windowState' => $window['window']['state'] ?? 'none',
            'daysRemaining' => $window['window']['days_remaining'] ?? null,
            'endDate' => $trial->end_date?->toDateString(),
            'graceEndsAt' => $trial->grace_ends_at?->toISOString(),
            'grantsAccess' => $window['grants_access'] ?? false,
            /*
             * The package under evaluation, as a badge.
             *
             * Falls back to the free-text `plan` only when no catalogue package
             * is linked — a trial started before packages existed still says
             * what it was sold as, rather than showing nothing.
             */
            'package' => $trial->companyPlan ? [
                'id' => $trial->companyPlan->id,
                'key' => $trial->companyPlan->key,
                'name' => $trial->companyPlan->name,
            ] : null,
            'planName' => $trial->companyPlan?->name ?: $trial->plan,
            'owner' => $trial->assignedUser?->name,
            'extensionCount' => $trial->extension_count,

            /*
             * How the evaluation ended, when it ended badly. Separate from
             * `phase` because a lost trial is `completed` — the run finished —
             * and "finished" and "they said no" are not the same fact.
             */
            'lost' => $trial->lost_at ? [
                'at' => $trial->lost_at->toDateString(),
                'reason' => $trial->lost_reason,
                'competitor' => $trial->lost_competitor,
            ] : null,

            'provisioning' => [
                'isProvisioned' => $trial->isProvisioned(),
                'error' => $trial->provisioning_error,
            ],

            /*
             * Live activity, cached on the row by `TrialService::syncTrialUsage()`.
             *
             * Read straight off the trial rather than from the snapshot table,
             * which is what makes a list of two hundred trials show current
             * activity without a query per row. Null until first measured.
             */
            'telemetry' => $trial->usage_telemetry ?: null,

            /*
             * Null until the nightly job has measured this tenant. The list
             * renders that as "not measured yet" rather than as zeros, which
             * would tell the rep the customer did nothing.
             */
            'usage' => $snapshot ? [
                'capturedAt' => $snapshot->captured_at?->toDateString(),
                'activeUsers' => $snapshot->users_active_7d,
                'totalUsers' => $snapshot->users_total,
                'patients' => $snapshot->patients_count,
                'appointments' => $snapshot->appointments_count,
                'lastLoginAt' => $snapshot->last_login_at?->toISOString(),
                'readiness' => $snapshot->onboarding_readiness,
            ] : null,

            'can' => [
                'extend' => $user ? Gate::forUser($user)->allows('provision', $trial) : false,
                'convert' => $user ? $this->canConvert($user, $trial) : false,
                /*
                 * Same permission as extend — both end or alter a customer's
                 * evaluation — but refused outright once it is already lost, so
                 * the button disappears rather than silently doing nothing.
                 */
                'markLost' => $user && $trial->lost_at === null
                    ? Gate::forUser($user)->allows('provision', $trial)
                    : false,
            ],
            'convertBlockers' => $this->convertBlockers($trial),
        ];
    }

    /**
     * How many live trials are thriving, drifting, or about to run out.
     *
     * Delegates to {@see TrialHealthService}, the canonical definition shared
     * with the Dashboard's KPI cards, so the two surfaces cannot disagree.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    protected function healthCounts(Collection $rows): array
    {
        return $this->health->aggregateFromRows($rows);
    }

    /**
     * Ordering weight. Lower is more urgent.
     *
     * Grace leads because it is the only phase where the customer is still
     * working and the clock is running out — the days when a decision is
     * actually made, and the ones a rep most needs put in front of them. A
     * failed provision sits alongside it: nobody is evaluating anything.
     */
    protected function urgency(TrialStatus $status): int
    {
        return match ($status) {
            TrialStatus::InGrace => 0,
            TrialStatus::EndingSoon => 1,
            TrialStatus::Preparing => 2,
            TrialStatus::Extended => 3,
            TrialStatus::Active => 4,
            TrialStatus::Expired => 5,
            TrialStatus::QuotationRequested => 6,
            TrialStatus::Completed => 7,
            TrialStatus::Cancelled => 8,
        };
    }

    /**
     * The newest snapshot for each of these trials, keyed by trial.
     *
     * @param  list<int>  $trialIds
     * @return array<int, TrialUsageSnapshot>
     */
    protected function latestSnapshots(array $trialIds): array
    {
        if ($trialIds === []) {
            return [];
        }

        return TrialUsageSnapshot::whereIn('trial_id', $trialIds)
            ->orderBy('captured_at')
            ->get()
            /* Ordered ascending, so keying by trial leaves the newest standing. */
            ->keyBy('trial_id')
            ->all();
    }
}
