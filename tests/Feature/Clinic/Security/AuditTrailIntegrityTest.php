<?php

use App\Models\SecurityAuditLog;
use App\Services\Security\SecurityAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;

/**
 * Point the security log channel at an in-memory handler and return it.
 */
function captureSecurityLog(): TestHandler
{
    $handler = new TestHandler;
    config([
        'logging.channels.security-capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'security.audit.log_channel' => 'security-capture',
    ]);
    Log::channel('security-capture')->getLogger()->setHandlers([$handler]);

    return $handler;
}

it('chains every entry to the one before it and verifies clean', function () {
    $audit = app(SecurityAudit::class);
    $audit->record('test.one', null, ['b' => 2, 'a' => 1]);
    $audit->record('test.two');
    $audit->record('test.three', null, ['nested' => ['z' => 1, 'y' => [3, 2, 1]]]);

    $rows = SecurityAuditLog::orderBy('id')->get();

    expect($rows[0]->prev_hash)->toBeNull()
        ->and($rows[1]->prev_hash)->toBe($rows[0]->entry_hash)
        ->and($rows[2]->prev_hash)->toBe($rows[1]->entry_hash);

    $this->artisan('security:verify-audit-chain')->assertExitCode(0);
});

it('detects an entry edited directly in the database', function () {
    $audit = app(SecurityAudit::class);
    $audit->record('auth.failed', null, ['email' => 'victim@clinic.test']);
    $audit->record('auth.login');

    // Bypasses the model's append-only guard, as someone with database access could.
    DB::table('security_audit_logs')->where('event', 'auth.failed')->update(['context' => json_encode(['email' => 'someone-else@clinic.test'])]);

    $this->artisan('security:verify-audit-chain')->assertExitCode(1);
});

it('detects an entry deleted directly in the database', function () {
    $audit = app(SecurityAudit::class);
    $audit->record('document.downloaded');
    $audit->record('auth.logout');
    $audit->record('auth.login');

    DB::table('security_audit_logs')->where('event', 'auth.logout')->delete();

    $this->artisan('security:verify-audit-chain')->assertExitCode(1);
});

it('ships every entry to the security log channel without secrets', function () {
    $handler = captureSecurityLog();

    app(SecurityAudit::class)->record('auth.password_set', null, ['via' => 'reset', 'password' => 'hunter2', 'reset_token' => 'abc']);

    $record = $handler->getRecords()[0];

    expect($record['message'])->toBe('auth.password_set')
        ->and($record['context']['context'])->toBe(['via' => 'reset'])
        ->and($record['context']['entry_hash'])->toBe(SecurityAuditLog::sole()->entry_hash)
        ->and(json_encode($record['context']))->not->toContain('hunter2')->not->toContain('abc');
});

it('still ships the event off-box when the database copy cannot be written', function () {
    $handler = captureSecurityLog();
    Schema::drop('security_audit_logs');

    app(SecurityAudit::class)->record('auth.lockout', null, ['email' => 'x@clinic.test']);

    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]['message'])->toBe('auth.lockout');
});

it('stamps entries and responses with the request correlation id', function () {
    $response = $this->withHeader('X-Request-Id', 'trace-12345678')->post('/clinic/login', ['email' => 'nobody@clinic.test', 'password' => 'x']);

    $response->assertHeader('X-Request-Id', 'trace-12345678');
    expect(SecurityAuditLog::where('event', 'auth.failed')->sole()->request_id)->toBe('trace-12345678');
});

it('replaces a malformed incoming request id with its own', function () {
    $issued = $this->withHeader('X-Request-Id', 'bad id <script>')->get('/clinic/login')->headers->get('X-Request-Id');

    expect($issued)->not->toBe('bad id <script>')
        ->and(Str::isUuid($issued))->toBeTrue();
});
