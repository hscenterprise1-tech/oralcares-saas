<?php

use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\Contracts\MalwareScannerInterface;
use App\Models\BackupRun;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Organization;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Security\Malware\FakeMalwareScanner;
use App\Services\Security\Malware\ScanResult;
use App\Services\Security\SecurityAudit;
use App\UserType;

/**
 * @return array{user: User, clinic: Clinic, branch: Branch, membership: ClinicMembership}
 */
function clinicMember(bool $owner = false, array $clinicAttributes = []): array
{
    $organization = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id] + $clinicAttributes);
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $organization->id]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'password' => bcrypt('password123')]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
        'is_primary_owner' => $owner,
    ]);

    return compact('user', 'clinic', 'branch', 'membership');
}

function inClinic($test, array $member)
{
    return $test->actingAs($member['user'])->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $member['membership']->id,
        ClinicContext::SESSION_BRANCH_ID => $member['branch']->id,
    ]);
}

/*
 * Response headers
 */

it('sends the baseline security headers', function () {
    $this->get('/clinic/login')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeaderMissing('Strict-Transport-Security');
});

/*
 * Authorization layers
 */

it('refuses appointment actions to a member without the appointment permissions', function () {
    $member = clinicMember(owner: false);

    inClinic($this, $member)->get('/clinic/appointments')->assertForbidden();
    inClinic($this, $member)->postJson('/clinic/appointments', [])->assertForbidden();
});

it('refuses a module the clinic\'s plan does not include, even to the owner', function () {
    $member = clinicMember(owner: true);
    $plan = CompanyPlan::factory()->create();
    // The feature catalogue is seeded by migration; reuse its row.
    $patients = CompanyFeature::where('key', 'patients')->first() ?? CompanyFeature::factory()->create(['key' => 'patients']);
    $plan->features()->sync([$patients->id]);
    CompanySubscription::factory()->create(['clinic_id' => $member['clinic']->id, 'company_plan_id' => $plan->id]);

    inClinic($this, $member)->get('/clinic/appointments')->assertForbidden();
    inClinic($this, $member)->get('/clinic/patients')->assertOk();
});

/*
 * Security audit trail
 */

it('records a clinic sign-in, a failed attempt without the password, and a sign-out', function () {
    $member = clinicMember();

    $this->post('/clinic/login', ['email' => $member['user']->email, 'password' => 'wrong-password']);
    $this->post('/clinic/login', ['email' => $member['user']->email, 'password' => 'password123']);
    $this->post('/clinic/logout');

    $failed = SecurityAuditLog::where('event', 'auth.failed')->sole();
    expect($failed->context)->toMatchArray(['email' => strtolower($member['user']->email)])
        ->and(json_encode($failed->context))->not->toContain('wrong-password');

    expect(SecurityAuditLog::where('event', 'auth.login')->where('actor_user_id', $member['user']->id)->exists())->toBeTrue()
        ->and(SecurityAuditLog::where('event', 'auth.logout')->where('actor_user_id', $member['user']->id)->exists())->toBeTrue();
});

it('records a correct password refused at the wrong clinic', function () {
    $member = clinicMember(clinicAttributes: ['slug' => 'home-dental']);
    Clinic::factory()->create(['slug' => 'elsewhere-dental']);

    $this->post('/clinic/login?clinic=elsewhere-dental', ['email' => $member['user']->email, 'password' => 'password123']);

    expect(SecurityAuditLog::where('event', 'auth.refused')->sole()->context)
        ->toMatchArray(['reason' => 'no_access_to_requested_clinic', 'requested_clinic' => 'elsewhere-dental']);
});

it('records a clinic being suspended and a membership being deactivated', function () {
    $member = clinicMember();

    $member['clinic']->update(['status' => ClinicStatus::Suspended]);
    $member['membership']->update(['status' => ClinicMembershipStatus::Inactive]);

    expect(SecurityAuditLog::where('event', 'clinic.status_changed')->sole()->context)->toBe(['from' => 'active', 'to' => 'suspended'])
        ->and(SecurityAuditLog::where('event', 'membership.status_changed')->sole()->context)
        ->toMatchArray(['member_user_id' => $member['user']->id, 'from' => 'active', 'to' => 'inactive']);
});

it('drops secrets from audit context at any depth', function () {
    app(SecurityAudit::class)->record('test.event', null, [
        'password' => 'hunter2',
        'reset_token' => 'abc',
        'nested' => ['api_secret' => 'x', 'kept' => 'visible'],
        'reason' => 'kept',
    ]);

    expect(SecurityAuditLog::sole()->context)->toBe(['nested' => ['kept' => 'visible'], 'reason' => 'kept']);
});

it('never lets an audit entry be edited or deleted through the app', function () {
    app(SecurityAudit::class)->record('test.event');
    $entry = SecurityAuditLog::sole();

    expect(fn () => $entry->update(['event' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(SecurityAuditLog::sole()->event)->toBe('test.event');
});

/*
 * Production configuration gate
 */

it('fails the production check on an unsafe configuration', function () {
    config(['app.debug' => true, 'session.secure' => false]);

    $this->artisan('security:production-check')->assertExitCode(1);
});

/**
 * Every setting and fact a production deploy must have.
 */
function safeProductionSetup(): void
{
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.debug' => false,
        'app.url' => 'https://clinic.example.test',
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'session.driver' => 'database',
        'mail.default' => 'smtp',
        'queue.default' => 'database',
        'auth.master_admin.password' => 'a-long-unique-seed-password',
        'filesystems.disks.private.driver' => 's3',
        'filesystems.disks.private.bucket' => 'oralcares-documents',
        'filesystems.disks.backups.driver' => 's3',
        'security.backup.encryption_key' => 'base64:'.base64_encode(random_bytes(32)),
        'security.backup.verify_connection' => 'restore_scratch',
        'logging.channels.security' => ['driver' => 'syslog'],
    ]);
    app()->instance(MalwareScannerInterface::class, new class implements MalwareScannerInterface
    {
        public function scan(string $absolutePath): ScanResult
        {
            return ScanResult::clean();
        }

        public function name(): string
        {
            return 'test-real';
        }

        public function isReal(): bool
        {
            return true;
        }
    });
    BackupRun::create(['status' => 'succeeded', 'disk' => 'backups', 'path' => 'x', 'started_at' => now()->subHours(3), 'verification_status' => 'passed', 'verified_at' => now()->subDays(2)]);
}

it('passes the production check on a safe configuration', function () {
    safeProductionSetup();

    $this->artisan('security:production-check')->assertExitCode(0);
});

it('fails the production check when production infrastructure is missing', function (Closure $breakIt) {
    safeProductionSetup();
    $breakIt();

    $this->artisan('security:production-check')->assertExitCode(1);
})->with([
    'no real scanner' => [fn () => app()->instance(MalwareScannerInterface::class, new FakeMalwareScanner)],
    'documents on local disk' => [fn () => config(['filesystems.disks.private.driver' => 'local'])],
    'no backup key' => [fn () => config(['security.backup.encryption_key' => null])],
    'backups on the app server' => [fn () => config(['filesystems.disks.backups.driver' => 'local'])],
    'stale backup' => [fn () => BackupRun::query()->update(['started_at' => now()->subDays(3)])],
    'no passing restore test' => [fn () => BackupRun::query()->update(['verification_status' => 'failed'])],
    'security log stays on the server' => [fn () => config(['logging.channels.security' => ['driver' => 'daily']])],
    'insecure cookie' => [fn () => config(['session.secure' => false])],
    'debug on' => [fn () => config(['app.debug' => true])],
    'plain http' => [fn () => config(['app.url' => 'http://clinic.example.test'])],
    'legacy clinic without subscription' => [fn () => Clinic::factory()->create()],
]);

it('treats production-only checks as warnings for a staging target', function () {
    safeProductionSetup();
    app()->detectEnvironment(fn () => 'staging');
    config(['filesystems.disks.private.driver' => 'local']);

    $this->artisan('security:production-check', ['--target' => 'staging'])->assertExitCode(0);
});
