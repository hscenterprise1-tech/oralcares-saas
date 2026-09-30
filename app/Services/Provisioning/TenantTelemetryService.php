<?php

namespace App\Services\Provisioning;

use App\Models\Clinic;
use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The only way CRM learns anything about a customer's tenant.
 *
 * A vendor sales tool sitting on top of live clinics is a standing privacy
 * hazard: everything a rep might find useful is also somebody's medical record.
 * So the boundary is drawn here, once, and it is drawn at the query rather than
 * at the presentation layer — because a boundary you can walk around by writing
 * one more `select` is not a boundary.
 *
 * Two rules, both structural:
 *
 *   1. **Aggregates only.** Every query in this class is `count`, `sum` or
 *      `max` on a timestamp. No row is ever selected from a patient, clinical,
 *      document or message table, so there is no code path by which a name, a
 *      diagnosis or the body of a WhatsApp message can reach CRM. The snapshot
 *      table has no column that could store one either.
 *
 *   2. **One declared key per metric.** {@see self::TENANT_KEYS} is the whole
 *      map. There is no uniform tenant column in this database — patients hang
 *      off `clinic_id`, appointments off `organization_id`, attachments off a
 *      patient two joins away — and a metric that guesses wrong silently counts
 *      another customer's data. Writing the mapping down makes it testable, and
 *      it is tested.
 *
 * Nothing here is editable. These numbers are measured or they are absent; a
 * usage figure somebody typed in is worse than none, because it gets believed.
 */
class TenantTelemetryService
{
    /** How far back "recent activity" looks. */
    public const RECENT_DAYS = 7;

    /**
     * How each metric reaches its tenant.
     *
     * `via` is one of:
     *   - `clinic`        the table has `clinic_id`
     *   - `organization`  the table has `organization_id`
     *   - `patient`       the table reaches the tenant through `patients.clinic_id`
     *   - `branch`        the table reaches the tenant through `branches.clinic_id`
     *   - `user`          the table reaches the tenant through clinic membership
     *
     * @var array<string, array{table: string, via: string, through?: array{table: string, local: string, foreign: string}}>
     */
    public const TENANT_KEYS = [
        'patients' => ['table' => 'patients', 'via' => 'clinic'],
        'appointments' => ['table' => 'appointments', 'via' => 'organization'],
        'encounters' => ['table' => 'clinical_encounters', 'via' => 'organization'],
        'prescriptions' => ['table' => 'prescriptions', 'via' => 'organization'],
        'invoices' => ['table' => 'commercial_invoices', 'via' => 'clinic'],
        'branches' => ['table' => 'branches', 'via' => 'clinic'],
        'memberships' => ['table' => 'clinic_memberships', 'via' => 'clinic'],
        'whatsapp_messages' => ['table' => 'marketing_whatsapp_messages', 'via' => 'organization'],

        /* Storage. Three of the four reach the tenant only through a patient. */
        'storage_clinical' => ['table' => 'clinical_attachments', 'via' => 'organization'],
        'storage_documents' => ['table' => 'attached_documents', 'via' => 'patient', 'through' => ['table' => 'patients', 'local' => 'patient_id', 'foreign' => 'id']],
        'storage_xray' => ['table' => 'xray_attachments', 'via' => 'patient', 'through' => ['table' => 'xray_records', 'local' => 'xray_record_id', 'foreign' => 'id']],
        'storage_lab' => ['table' => 'lab_order_attachments', 'via' => 'patient', 'through' => ['table' => 'lab_orders', 'local' => 'lab_order_id', 'foreign' => 'id']],
    ];

    /**
     * Columns this service must never read, on any table.
     *
     * Not load-bearing at runtime — the queries are aggregates, so none of these
     * could be returned anyway. It exists so the rule is written down next to
     * the code it governs, and so the test that greps for it has something to
     * grep for.
     *
     * @var list<string>
     */
    public const FORBIDDEN_COLUMNS = [
        'body', 'message_body', 'chief_complaint', 'diagnosis', 'notes',
        'description', 'first_name', 'last_name', 'phone', 'email',
        'password', 'access_token', 'access_token_encrypted',
    ];

    public function __construct(protected EntitlementResolver $entitlements) {}

    /**
     * Count what this tenant has done.
     *
     * @return array<string, mixed>
     */
    public function capture(Clinic $clinic, ?Organization $organization = null, ?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();
        $organization ??= $clinic->organization;
        $since = $now->copy()->subDays(self::RECENT_DAYS);

        $clinicId = $clinic->id;
        $organizationId = $organization?->id;

        $entitlement = $this->entitlements->forClinic($clinic);
        $userIds = $this->tenantUserIds($clinicId);

        $counts = [
            'patients_count' => $this->countFor('patients', $clinicId, $organizationId),
            'appointments_count' => $this->countFor('appointments', $clinicId, $organizationId),
            'appointments_7d' => $this->countFor('appointments', $clinicId, $organizationId, $since),
            'encounters_count' => $this->countFor('encounters', $clinicId, $organizationId),
            'prescriptions_count' => $this->countFor('prescriptions', $clinicId, $organizationId),
            'invoices_count' => $this->countFor('invoices', $clinicId, $organizationId),
            'branches_count' => $this->countFor('branches', $clinicId, $organizationId),
        ];

        $logins = $this->loginActivity($userIds, $since);
        $storageBytes = $this->storageBytes($clinicId, $organizationId);

        return array_merge($counts, $logins, [
            'clinic_id' => $clinicId,
            'organization_id' => $organizationId,
            'captured_at' => $now,

            'users_total' => count($userIds),

            'storage_bytes' => $storageBytes,
            'storage_limit_mb' => $entitlement['limits']['storage_mb'] ?? null,

            'modules_enabled' => $entitlement['modules'],
            'modules_used' => $this->modulesUsed($entitlement['modules'], $counts, $clinicId, $organizationId),

            'whatsapp_sent_count' => $this->whatsAppSent($organizationId),

            /*
             * Deliberately null. No plan, account or licence column defines an
             * allowance, so reporting a quota would mean inventing one. The raw
             * sent count above is what we can honestly show.
             */
            'whatsapp_quota' => $entitlement['whatsapp_quota'],
        ], $this->onboardingReadiness($clinicId, $organizationId, $counts));
    }

    /**
     * Count one metric for one tenant, through its declared key.
     *
     * Everything routes through here rather than each metric writing its own
     * `where` — that is what makes {@see self::TENANT_KEYS} the actual mapping
     * and not merely documentation of one.
     */
    public function countFor(string $metric, int $clinicId, ?int $organizationId, ?Carbon $since = null): int
    {
        $map = self::TENANT_KEYS[$metric] ?? null;

        if (! $map || ! $this->tableExists($map['table'])) {
            return 0;
        }

        $query = $this->scopedQuery($metric, $clinicId, $organizationId);

        if (! $query) {
            return 0;
        }

        if ($since) {
            $query->where($map['table'].'.created_at', '>=', $since);
        }

        return (int) $query->count();
    }

    /**
     * A query already narrowed to one tenant, or null when it cannot be.
     *
     * Returning null rather than an unscoped query is the important part: a
     * metric whose tenant key is missing must count nothing, never everything.
     */
    protected function scopedQuery(string $metric, int $clinicId, ?int $organizationId): ?Builder
    {
        $map = self::TENANT_KEYS[$metric];
        $table = $map['table'];
        $query = DB::table($table);

        switch ($map['via']) {
            case 'clinic':
                return $query->where($table.'.clinic_id', $clinicId);

            case 'organization':
                return $organizationId === null ? null : $query->where($table.'.organization_id', $organizationId);

            case 'patient':
                $through = $map['through'];

                if (! $this->tableExists($through['table'])) {
                    return null;
                }

                $query->join($through['table'], $table.'.'.$through['local'], '=', $through['table'].'.'.$through['foreign']);

                /*
                 * `patients` is itself the tenant boundary for this branch, so
                 * a table that hangs off patients directly needs no second
                 * join; anything further out reaches patients first.
                 */
                if ($through['table'] === 'patients') {
                    return $query->where('patients.clinic_id', $clinicId);
                }

                return $query
                    ->join('patients', $through['table'].'.patient_id', '=', 'patients.id')
                    ->where('patients.clinic_id', $clinicId);

            default:
                return null;
        }
    }

    /**
     * The users who belong to this tenant.
     *
     * Membership, not `users.organization_id`: a clinic user reaches the portal
     * through a `ClinicMembership`, and the organisation column is set
     * inconsistently on older rows.
     *
     * @return list<int>
     */
    public function tenantUserIds(int $clinicId): array
    {
        if (! $this->tableExists('clinic_memberships')) {
            return [];
        }

        return DB::table('clinic_memberships')
            ->where('clinic_id', $clinicId)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Whether anybody has actually logged in, and when.
     *
     * The single most useful fact about a trial, and until `login_histories`
     * gained a writer it could not be answered at all.
     *
     * @param  list<int>  $userIds
     * @return array{users_active_7d: int, logins_7d: int, last_login_at: ?string}
     */
    public function loginActivity(array $userIds, Carbon $since): array
    {
        if ($userIds === [] || ! $this->tableExists('login_histories')) {
            return ['users_active_7d' => 0, 'logins_7d' => 0, 'last_login_at' => null];
        }

        $recent = DB::table('login_histories')
            ->whereIn('user_id', $userIds)
            ->where('login_time', '>=', $since);

        return [
            'users_active_7d' => (int) $recent->distinct()->count('user_id'),
            'logins_7d' => (int) DB::table('login_histories')
                ->whereIn('user_id', $userIds)
                ->where('login_time', '>=', $since)
                ->count(),
            'last_login_at' => DB::table('login_histories')
                ->whereIn('user_id', $userIds)
                ->max('login_time'),
        ];
    }

    /**
     * Bytes stored, summed across every attachment table.
     *
     * All four are included because a figure that quietly omitted clinical and
     * lab files would be wrong in exactly the direction that matters when it is
     * compared against a sold allowance. Rows with no recorded size contribute
     * nothing rather than being guessed at.
     */
    public function storageBytes(int $clinicId, ?int $organizationId): int
    {
        $total = 0;

        foreach (['storage_clinical', 'storage_documents', 'storage_xray', 'storage_lab'] as $metric) {
            $map = self::TENANT_KEYS[$metric];

            if (! $this->tableExists($map['table'])) {
                continue;
            }

            $query = $this->scopedQuery($metric, $clinicId, $organizationId);

            if (! $query) {
                continue;
            }

            $total += (int) $query->sum($map['table'].'.file_size_bytes');
        }

        return $total;
    }

    /**
     * How many messages this tenant has sent from its own WhatsApp account.
     *
     * A count. The `body` column on that table is never read, here or anywhere
     * else in this class — that is the whole point of the boundary.
     */
    public function whatsAppSent(?int $organizationId): int
    {
        if ($organizationId === null || ! $this->tableExists('marketing_whatsapp_messages')) {
            return 0;
        }

        return (int) DB::table('marketing_whatsapp_messages')
            ->where('organization_id', $organizationId)
            ->where('direction', 'outbound')
            ->count();
    }

    /**
     * Which granted modules show any sign of use.
     *
     * The gap between granted and used is the most commercially useful thing
     * here: it is what the customer is paying for and has not touched.
     *
     * @param  list<string>  $granted
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    public function modulesUsed(array $granted, array $counts, int $clinicId, ?int $organizationId): array
    {
        $evidence = [
            'patients' => $counts['patients_count'] ?? 0,
            'appointments' => $counts['appointments_count'] ?? 0,
            'emr' => ($counts['encounters_count'] ?? 0) + ($counts['prescriptions_count'] ?? 0),
            'billing' => $counts['invoices_count'] ?? 0,
            'whatsapp' => $this->whatsAppSent($organizationId),
        ];

        return array_values(array_filter(
            $granted,
            /*
             * A module with no measurable signal is reported as unused rather
             * than assumed used. Inventory, lab, x-ray and analytics have no
             * counter wired up yet, and saying "we cannot tell" by omission is
             * better than claiming activity we did not observe.
             */
            fn (string $module) => ($evidence[$module] ?? 0) > 0,
        ));
    }

    /**
     * How ready this tenant is to be used in earnest.
     *
     * Measured from the tenant's own tables, not from a checklist somebody
     * ticked. The checks are the things without which the product does not
     * function: hours to book inside, a branch, somebody who has logged in, and
     * evidence of real work.
     *
     * @param  array<string, int>  $counts
     * @return array{onboarding_readiness: int, onboarding_checks: array<string, bool>}
     */
    public function onboardingReadiness(int $clinicId, ?int $organizationId, array $counts): array
    {
        $checks = [
            'operating_hours' => $this->tableExists('clinic_operating_hours')
                && DB::table('clinic_operating_hours')->where('clinic_id', $clinicId)->exists(),
            'departments' => $this->tableExists('clinic_departments')
                && DB::table('clinic_departments')->where('clinic_id', $clinicId)->exists(),
            'branch' => ($counts['branches_count'] ?? 0) > 0,
            'first_patient' => ($counts['patients_count'] ?? 0) > 0,
            'first_appointment' => ($counts['appointments_count'] ?? 0) > 0,
            'first_invoice' => ($counts['invoices_count'] ?? 0) > 0,
        ];

        $passed = count(array_filter($checks));

        return [
            'onboarding_readiness' => (int) round($passed / max(1, count($checks)) * 100),
            'onboarding_checks' => $checks,
        ];
    }

    protected function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }
}
