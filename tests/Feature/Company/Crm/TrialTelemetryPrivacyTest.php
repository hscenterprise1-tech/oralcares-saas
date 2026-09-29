<?php

use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\Lead;
use App\Models\Trial;
use App\Models\User;
use App\Services\Provisioning\TenantTelemetryService;
use App\Services\Provisioning\TrialProvisioningService;
use App\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase 4 — the privacy boundary, checked at the query.
 *
 * A vendor sales tool sitting on top of live clinics is a standing hazard:
 * everything a rep might find useful is also somebody's medical record. Asserting
 * "the payload has no patient names in it" would only prove that today's payload
 * shape happens to be safe.
 *
 * So these tests watch the SQL. Every statement the telemetry service issues is
 * captured and inspected: it must be an aggregate, and it must never name a
 * content column. That holds whatever anybody adds to the service later.
 */
function telemetryTrial(): Trial
{
    $plan = CompanyPlan::firstWhere('key', 'growth') ?? CompanyPlan::factory()->create([
        'key' => 'growth',
        'name' => 'Growth',
        'max_users' => 20,
        'max_doctors' => 5,
        'max_branches' => 3,
        'storage_limit_gb' => 50,
        'trial_duration_days' => 14,
    ]);

    $lead = Lead::factory()->create([
        'company_name' => 'Narayan Health',
        'contact_name' => 'Priya Narayan',
        'email' => 'priya-'.uniqid().'@narayan.test',
        'phone' => '919400000000',
        'assigned_user_id' => User::factory()->companyUser()->create()->id,
    ]);

    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'assigned_user_id' => $lead->assigned_user_id,
        'company_plan_id' => $plan->id,
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
        'duration_days' => 14,
    ]);

    app(TrialProvisioningService::class)->provision($trial);

    return $trial->fresh();
}

/**
 * Run a capture and return every SQL statement it issued.
 *
 * @return array{metrics: array<string, mixed>, queries: list<string>}
 */
function captureWithSql(Clinic $clinic): array
{
    $queries = [];

    DB::listen(function ($query) use (&$queries) {
        $sql = strtolower($query->sql);

        /*
         * `Schema::hasTable()` names a table without reading a row from it.
         * That is introspection, not data access, so it is filtered out here
         * rather than being allowed to weaken the assertions below.
         */
        if (str_contains($sql, 'sqlite_master') || str_contains($sql, 'information_schema') || str_starts_with($sql, 'pragma')) {
            return;
        }

        $queries[] = $query->sql;
    });

    $metrics = app(TenantTelemetryService::class)->capture($clinic, $clinic->organization);

    return ['metrics' => $metrics, 'queries' => $queries];
}

it('never names a patient, clinical or message content column', function () {
    $trial = telemetryTrial();
    $clinic = Clinic::find($trial->clinic_id);

    ['queries' => $queries] = captureWithSql($clinic);

    expect($queries)->not->toBeEmpty();

    /*
     * The columns that carry what a patient told a doctor, what a doctor wrote
     * down, and what anybody sent anybody. None may appear in any statement.
     */
    $forbidden = ['message_body', 'chief_complaint', '`body`', 'first_name', 'last_name', 'date_of_birth', 'medical_history'];

    foreach ($queries as $sql) {
        foreach ($forbidden as $column) {
            expect(str_contains(strtolower($sql), strtolower($column)))
                ->toBeFalse("telemetry SQL referenced `{$column}`: {$sql}");
        }
    }
});

it('only ever aggregates the tables that hold clinical content', function () {
    /*
     * A `select *` from `patients` would return names and dates of birth even
     * if the caller ignored them. The rule is that rows are never fetched from
     * these tables at all — only counted and summed.
     */
    $trial = telemetryTrial();
    $clinic = Clinic::find($trial->clinic_id);

    ['queries' => $queries] = captureWithSql($clinic);

    $sensitive = ['patients', 'clinical_encounters', 'prescriptions', 'clinical_attachments', 'attached_documents', 'marketing_whatsapp_messages'];

    foreach ($queries as $sql) {
        $lower = strtolower($sql);

        foreach ($sensitive as $table) {
            if (! str_contains($lower, $table)) {
                continue;
            }

            expect($lower)->toMatch('/select (count\(|sum\(|max\(|min\()/', "non-aggregate query against `{$table}`: {$sql}");
        }
    }
});

it('reads nothing but ids from the tenant user list', function () {
    /*
     * The one place the service selects a column rather than an aggregate. It
     * must stay `user_id` and nothing else — names and emails are not CRM's.
     */
    $trial = telemetryTrial();
    $clinic = Clinic::find($trial->clinic_id);

    ['queries' => $queries] = captureWithSql($clinic);

    $membershipQueries = array_filter($queries, fn ($sql) => str_contains(strtolower($sql), 'clinic_memberships'));

    expect($membershipQueries)->not->toBeEmpty();

    foreach ($membershipQueries as $sql) {
        expect(strtolower($sql))->toContain('user_id')
            ->and(strtolower($sql))->not->toContain('select *');
    }
});

it('produces a payload with no free text in it at all', function () {
    $trial = telemetryTrial();
    $clinic = Clinic::find($trial->clinic_id);

    ['metrics' => $metrics] = captureWithSql($clinic);

    /*
     * Belt and braces on top of the SQL checks: every value that reaches CRM is
     * a number, a date, a boolean, or a list of module keys. Nothing else.
     */
    $walk = function ($value, string $path) use (&$walk) {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $walk($child, $path.'.'.$key);
            }

            return;
        }

        if (is_null($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return;
        }

        if ($value instanceof DateTimeInterface) {
            return;
        }

        /* The only strings permitted are module keys and check names. */
        expect($value)->toMatch('/^[a-z0-9_\-:\. T]+$/i', "unexpected free text at {$path}: {$value}");
        expect(strlen((string) $value))->toBeLessThan(64, "suspiciously long string at {$path}");
    };

    foreach ($metrics as $key => $value) {
        $walk($value, $key);
    }

    expect($metrics)->toHaveKey('patients_count');
});

it('keeps the forbidden column list next to the code it governs', function () {
    /*
     * The constant is not load-bearing at runtime — the queries are aggregates,
     * so none of these could be returned anyway. It is here so the rule is
     * written down, and this pins that it stays written down.
     */
    expect(TenantTelemetryService::FORBIDDEN_COLUMNS)
        ->toContain('message_body')
        ->toContain('chief_complaint')
        ->toContain('body')
        ->toContain('first_name');
});

it('counts a tenant whatsapp messages without reading one', function () {
    $trial = telemetryTrial();
    $clinic = Clinic::find($trial->clinic_id);
    $orgId = $trial->customer_organization_id;

    $accountId = DB::table('marketing_whatsapp_accounts')->insertGetId([
        'organization_id' => $orgId,
        'provider' => 'meta',
        'purpose' => 'clinic',
        'label' => 'Clinic WhatsApp',
        'status' => 'connected',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $conversationId = DB::table('marketing_whatsapp_conversations')->insertGetId([
        'organization_id' => $orgId,
        'whatsapp_account_id' => $accountId,
        'phone' => '919400000001',
        'normalized_phone' => '919400000001',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (['Patient is diabetic, please advise', 'Appointment confirmed'] as $body) {
        DB::table('marketing_whatsapp_messages')->insert([
            'organization_id' => $orgId,
            'whatsapp_account_id' => $accountId,
            'conversation_id' => $conversationId,
            'provider_message_id' => 'wamid-'.uniqid(),
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => $body,
            'status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    ['metrics' => $metrics, 'queries' => $queries] = captureWithSql($clinic);

    expect($metrics['whatsapp_sent_count'])->toBe(2);

    /* The clinical detail in that first message must appear nowhere. */
    foreach ($queries as $sql) {
        expect($sql)->not->toContain('diabetic');
    }

    expect(json_encode($metrics))->not->toContain('diabetic');
});
