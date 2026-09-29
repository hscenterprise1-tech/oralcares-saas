<?php

use App\Jobs\TrialUsageSnapshotJob;
use App\Models\Clinic;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\Lead;
use App\Models\LoginHistory;
use App\Models\Trial;
use App\Models\TrialUsageSnapshot;
use App\Models\User;
use App\Services\CRM\TrialLifecycleService;
use App\Services\Provisioning\TenantTelemetryService;
use App\Services\Provisioning\TrialProvisioningService;
use App\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase 4 — the numbers, and the keys they are counted through.
 *
 * There is no uniform tenant column in this database. Patients hang off
 * `clinic_id`, appointments off `organization_id`, attachments off a patient two
 * joins away. A metric that guesses wrong silently counts another customer's
 * data, which is both a wrong number and a privacy failure — so the mapping is
 * written down and tested against the real schema.
 */
function measuredTrial(string $company = 'Deshmukh Clinic'): Trial
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

    /*
     * Modules are read from `company_plan_features` now, not a hardcoded map
     * keyed by string — a plan built fresh by the factory branch above has no
     * pivot rows of its own until attached here.
     */
    if ($plan->features()->count() === 0) {
        $plan->features()->attach(
            CompanyFeature::whereIn('key', ['appointments', 'patients', 'billing', 'emr', 'whatsapp', 'inventory'])->pluck('id')
        );
    }

    $lead = Lead::factory()->create([
        'company_name' => $company,
        'contact_name' => 'Owner '.$company,
        'email' => strtolower(str_replace(' ', '', $company)).'-'.uniqid().'@example.test',
        'phone' => '9193'.random_int(10000000, 99999999),
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

/* ------------------------------------------------ canonical tenant mapping */

it('declares a tenant key for every metric it counts', function () {
    /*
     * The map is the mapping, not documentation of one — `countFor()` routes
     * every metric through it, so an entry that does not match the real schema
     * would count nothing or, worse, everything.
     */
    foreach (TenantTelemetryService::TENANT_KEYS as $metric => $map) {
        expect($map)->toHaveKey('table')->toHaveKey('via');

        if (! Schema::hasTable($map['table'])) {
            continue;
        }

        if ($map['via'] === 'clinic') {
            expect(Schema::hasColumn($map['table'], 'clinic_id'))
                ->toBeTrue("{$metric} claims a clinic_id that {$map['table']} does not have");
        }

        if ($map['via'] === 'organization') {
            expect(Schema::hasColumn($map['table'], 'organization_id'))
                ->toBeTrue("{$metric} claims an organization_id that {$map['table']} does not have");
        }

        if ($map['via'] === 'patient') {
            expect($map)->toHaveKey('through');
            expect(Schema::hasColumn($map['table'], $map['through']['local']))
                ->toBeTrue("{$metric} claims {$map['through']['local']} on {$map['table']}");
        }
    }
});

it('counts one tenant and never another', function () {
    /*
     * The failure this guards against is silent: a metric with the wrong key
     * returns a plausible number made of somebody else's data.
     */
    $mine = measuredTrial('Deshmukh Clinic');
    $theirs = measuredTrial('Iyer Hospital');

    DB::table('patients')->insert([
        ['clinic_id' => $mine->clinic_id, 'uhid' => 'OC-T-A', 'name' => 'Patient A', 'created_at' => now(), 'updated_at' => now()],
        ['clinic_id' => $mine->clinic_id, 'uhid' => 'OC-T-B', 'name' => 'Patient B', 'created_at' => now(), 'updated_at' => now()],
        ['clinic_id' => $theirs->clinic_id, 'uhid' => 'OC-T-C', 'name' => 'Patient C', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $telemetry = app(TenantTelemetryService::class);

    expect($telemetry->countFor('patients', $mine->clinic_id, $mine->customer_organization_id))->toBe(2)
        ->and($telemetry->countFor('patients', $theirs->clinic_id, $theirs->customer_organization_id))->toBe(1);
});

it('counts nothing rather than everything when a tenant key is unavailable', function () {
    /*
     * An organisation-keyed metric with no organisation must return zero. The
     * dangerous failure is an unscoped query, which would count every customer
     * in the database and report it as one tenant's usage.
     */
    $trial = measuredTrial();

    expect(app(TenantTelemetryService::class)->countFor('appointments', $trial->clinic_id, null))->toBe(0);
});

/* -------------------------------------------------------------- measuring */

it('measures the operational floor provisioning created', function () {
    $trial = measuredTrial();
    $clinic = Clinic::find($trial->clinic_id);

    $metrics = app(TenantTelemetryService::class)->capture($clinic, $clinic->organization);

    expect($metrics['branches_count'])->toBe(1)
        ->and($metrics['users_total'])->toBe(1)
        ->and($metrics['patients_count'])->toBe(0)
        ->and($metrics['storage_limit_mb'])->toBe(51200)
        ->and($metrics['modules_enabled'])->toContain('emr');
});

it('reports logins from login history rather than from a typed-in figure', function () {
    $trial = measuredTrial();
    $clinic = Clinic::find($trial->clinic_id);
    $userId = DB::table('clinic_memberships')->where('clinic_id', $clinic->id)->value('user_id');

    LoginHistory::create(['user_id' => $userId, 'login_time' => now()->subDay(), 'status' => 'success']);
    LoginHistory::create(['user_id' => $userId, 'login_time' => now()->subHours(2), 'status' => 'success']);
    /* Outside the window: counted in `last_login_at`, not in the 7-day figures. */
    LoginHistory::create(['user_id' => $userId, 'login_time' => now()->subDays(30), 'status' => 'success']);

    $metrics = app(TenantTelemetryService::class)->capture($clinic, $clinic->organization);

    expect($metrics['logins_7d'])->toBe(2)
        ->and($metrics['users_active_7d'])->toBe(1)
        ->and($metrics['last_login_at'])->not->toBeNull();
});

it('sums storage across every attachment table, through each one own key', function () {
    /*
     * Three of the four reach the tenant only through a patient. A figure that
     * quietly omitted them would be wrong in exactly the direction that matters
     * when it is compared against a sold allowance.
     */
    $trial = measuredTrial();
    $clinic = Clinic::find($trial->clinic_id);

    $patientId = DB::table('patients')->insertGetId([
        'clinic_id' => $clinic->id, 'uhid' => 'OC-T-S', 'name' => 'Storage Patient', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('clinical_attachments')->insert([
        'organization_id' => $trial->customer_organization_id,
        'attachable_type' => 'patient', 'attachable_id' => $patientId,
        'file_path' => 'x.pdf', 'file_type' => 'application/pdf', 'title' => 'Scan', 'file_size_bytes' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('attached_documents')->insert([
        'patient_id' => $patientId, 'file_name' => 'y.pdf', 'file_path' => 'y.pdf',
        'document_type' => 'report', 'file_size_bytes' => 2500, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(TenantTelemetryService::class)->storageBytes($clinic->id, $trial->customer_organization_id))
        ->toBe(3500);
});

it('reports a module as used only where there is evidence', function () {
    $trial = measuredTrial();
    $clinic = Clinic::find($trial->clinic_id);

    $before = app(TenantTelemetryService::class)->capture($clinic, $clinic->organization);
    expect($before['modules_used'])->toBe([]);

    DB::table('patients')->insert(['clinic_id' => $clinic->id, 'uhid' => 'OC-T-M', 'name' => 'Module Patient', 'created_at' => now(), 'updated_at' => now()]);

    $after = app(TenantTelemetryService::class)->capture($clinic, $clinic->organization);

    expect($after['modules_used'])->toContain('patients')
        /* Granted but untouched — the commercially useful gap. */
        ->and($after['modules_used'])->not->toContain('emr')
        ->and($after['modules_enabled'])->toContain('emr');
});

it('measures onboarding readiness from the tenant own tables', function () {
    /*
     * Not from a checklist somebody ticked. Provisioning creates hours,
     * departments and a branch, so a fresh tenant is genuinely half ready.
     */
    $trial = measuredTrial();
    $clinic = Clinic::find($trial->clinic_id);

    $metrics = app(TenantTelemetryService::class)->capture($clinic, $clinic->organization);

    expect($metrics['onboarding_checks']['operating_hours'])->toBeTrue()
        ->and($metrics['onboarding_checks']['departments'])->toBeTrue()
        ->and($metrics['onboarding_checks']['branch'])->toBeTrue()
        ->and($metrics['onboarding_checks']['first_patient'])->toBeFalse()
        ->and($metrics['onboarding_readiness'])->toBe(50);
});

it('reports the whatsapp quota as unavailable rather than as zero', function () {
    $trial = measuredTrial();
    $clinic = Clinic::find($trial->clinic_id);

    $metrics = app(TenantTelemetryService::class)->capture($clinic, $clinic->organization);

    /* No plan, account or licence column defines an allowance. */
    expect($metrics['whatsapp_quota'])->toBeNull()
        ->and($metrics['whatsapp_sent_count'])->toBe(0);
});

/* ------------------------------------------------------------ the job */

it('writes one snapshot per trial per day and replaces it on a re-run', function () {
    $trial = measuredTrial();

    app(TrialUsageSnapshotJob::class)->handle(app(TenantTelemetryService::class), app(TrialLifecycleService::class));
    app(TrialUsageSnapshotJob::class)->handle(app(TenantTelemetryService::class), app(TrialLifecycleService::class));

    expect(TrialUsageSnapshot::where('trial_id', $trial->id)->count())->toBe(1);
});

it('does not measure a tenant whose access has ended', function () {
    /*
     * A lapsed trial generates no usage, and re-counting a cancelled tenant's
     * tables every night is a privacy cost with no reader.
     */
    $trial = measuredTrial();
    app(TrialLifecycleService::class)->cancel($trial, 'Duplicate account.');

    app(TrialUsageSnapshotJob::class)->handle(app(TenantTelemetryService::class), app(TrialLifecycleService::class));

    expect(TrialUsageSnapshot::where('trial_id', $trial->id)->count())->toBe(0);
});

it('skips a trial that never provisioned', function () {
    $lead = Lead::factory()->create();
    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
    ]);

    app(TrialUsageSnapshotJob::class)->handle(app(TenantTelemetryService::class), app(TrialLifecycleService::class));

    expect(TrialUsageSnapshot::where('trial_id', $trial->id)->count())->toBe(0);
});

it('keeps one tenant failure from stopping the rest of the run', function () {
    $healthy = measuredTrial('Deshmukh Clinic');
    $broken = measuredTrial('Iyer Hospital');

    /*
     * Its clinic row is gone, so loading it returns null and that trial cannot
     * be measured. The run must still finish the others.
     */
    DB::table('clinics')->where('id', $broken->clinic_id)->delete();

    app(TrialUsageSnapshotJob::class)->handle(app(TenantTelemetryService::class), app(TrialLifecycleService::class));

    expect(TrialUsageSnapshot::where('trial_id', $healthy->id)->count())->toBe(1);
});

it('records storage as a share of what the plan sells', function () {
    $trial = measuredTrial();

    app(TrialUsageSnapshotJob::class)->handle(app(TenantTelemetryService::class), app(TrialLifecycleService::class));

    $snapshot = TrialUsageSnapshot::where('trial_id', $trial->id)->first();

    expect($snapshot->storage_limit_mb)->toBe(51200)
        ->and($snapshot->storagePercent())->toBe(0.0);
});

it('reports an unknown storage share as null rather than nil', function () {
    /* An unknown proportion and a zero one are not the same claim. */
    $snapshot = new TrialUsageSnapshot(['storage_bytes' => 500, 'storage_limit_mb' => null]);

    expect($snapshot->storagePercent())->toBeNull();
});
