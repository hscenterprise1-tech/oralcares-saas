<?php

use App\Contracts\TenantConnectionResolver;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Services\Platform\ReportingDatabase;
use App\Support\TenantCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * The scale foundation: readiness probe, tenant cache keys, tenant routing,
 * reporting connection and slow-query telemetry.
 */

it('reports ready when every dependency answers, without starting a session', function () {
    Storage::fake('private');

    $this->getJson('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'cache' => 'ok', 'queue' => 'ok', 'storage' => 'ok']])
        ->assertCookieMissing(config('session.cookie'));
});

it('reports degraded with the failing dependency named and nothing else', function () {
    Storage::fake('private');
    Cache::shouldReceive('put')->andThrow(new RuntimeException('redis://secret-host:6379 refused'));

    $response = $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.cache', 'failed')
        ->assertJsonPath('checks.database', 'ok');

    expect($response->getContent())->not->toContain('secret-host');
});

it('keeps one clinic\'s cached values out of another\'s reach', function () {
    $mine = Clinic::factory()->create();
    $theirs = Clinic::factory()->create();

    TenantCache::remember($mine, 'dashboard', 'today', 60, fn () => 'mine');

    expect(TenantCache::remember($theirs, 'dashboard', 'today', 60, fn () => 'theirs'))->toBe('theirs')
        ->and(TenantCache::key($mine, 'dashboard', 'today'))->toStartWith("t:{$mine->id}:");
});

it('retires every cached value of a clinic at once, and only that clinic\'s', function () {
    $mine = Clinic::factory()->create();
    $theirs = Clinic::factory()->create();
    TenantCache::remember($mine, 'dashboard', 'today', 60, fn () => 'stale');
    TenantCache::remember($theirs, 'dashboard', 'today', 60, fn () => 'kept');

    TenantCache::flush($mine);

    expect(TenantCache::remember($mine, 'dashboard', 'today', 60, fn () => 'fresh'))->toBe('fresh')
        ->and(TenantCache::remember($theirs, 'dashboard', 'today', 60, fn () => 'recomputed'))->toBe('kept');
});

it('refuses to build a tenant cache key without a clinic', function () {
    expect(fn () => TenantCache::key(0, 'dashboard', 'today'))->toThrow(InvalidArgumentException::class);
});

it('routes every clinic to the primary database until sharding is introduced', function () {
    $resolver = app(TenantConnectionResolver::class);

    expect($resolver->connectionFor(42))->toBe(config('database.default'))
        ->and($resolver->centralConnection())->toBe(config('database.default'))
        ->and(fn () => $resolver->connectionFor(0))->toThrow(InvalidArgumentException::class);
});

it('sends report reads to the configured reporting connection', function () {
    expect(app(ReportingDatabase::class)->connectionName())->toBe(config('database.default'));

    config(['database.reporting_connection' => 'reporting']);

    expect(app(ReportingDatabase::class)->connectionName())->toBe('reporting');
});

it('filters a day with a range an index can serve, from the first second to the last', function () {
    $patient = Patient::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $branch = Branch::factory()->create(['clinic_id' => $patient->clinic_id]);
    PatientVisit::factory()->count(4)->sequence(
        ['visit_date' => '2026-10-01 23:59:59'],
        ['visit_date' => '2026-10-02 00:00:00'],
        ['visit_date' => '2026-10-02 23:59:59'],
        ['visit_date' => '2026-10-03 00:00:00'],
    )->create(['clinic_id' => $patient->clinic_id, 'branch_id' => $branch->id, 'patient_id' => $patient->id]);

    $onDay = DB::table('patient_visits')->whereOnDate('visit_date', '2026-10-02');
    $since = DB::table('patient_visits')->whereSinceDate('visit_date', '2026-10-02');

    expect($onDay->count())->toBe(2)
        ->and($since->count())->toBe(3)
        ->and(strtolower($onDay->toSql()))->not->toContain('date(');
});

it('logs a slow query without its bindings', function () {
    Log::shouldReceive('channel')->with('performance')->once()->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'slow_query'
        && $context['sql'] === 'select * from users where email = ?'
        && ! str_contains(json_encode($context), 'patient@secret.test'));

    DB::connection()->logQuery('select * from users where email = ?', ['patient@secret.test'], config('observability.slow_query_ms') + 1);
});

it('refuses to seed or benchmark synthetic tenants outside a scratch or perf database', function (string $command) {
    $this->artisan($command)->assertFailed();

    expect(Clinic::query()->where('slug', 'like', 'perf-%')->exists())->toBeFalse();
})->with(['perf:seed-tenants', 'perf:benchmark']);
