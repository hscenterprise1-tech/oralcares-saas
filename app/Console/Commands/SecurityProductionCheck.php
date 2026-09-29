<?php

namespace App\Console\Commands;

use App\ClinicStatus;
use App\Contracts\MalwareScannerInterface;
use App\Models\BackupRun;
use App\Models\Clinic;
use App\Models\CompanySubscription;
use App\Services\Backup\BackupEncryptor;
use App\Services\Security\Malware\ClamAvScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pre-deploy gate: checks the configuration and infrastructure a deploy must
 * have before it serves patient data, and exits non-zero when any check
 * required for the target environment fails, so a pipeline can stop on it.
 *
 *   php artisan security:production-check                     (target: production)
 *   php artisan security:production-check --target=staging
 *
 * Each check is required for production, for staging, or both (see
 * docs/security/production-deployment.md). Local development is expected to
 * fail most of them. The checks are never relaxed to make a deploy pass.
 */
class SecurityProductionCheck extends Command
{
    protected $signature = 'security:production-check {--target=production : production or staging}';

    protected $description = 'Check that security-relevant configuration and infrastructure are safe for deployment';

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> */
    private array $results = [];

    private string $target = 'production';

    public function handle(): int
    {
        $this->target = $this->option('target') === 'staging' ? 'staging' : 'production';

        // Environment and framework
        $this->check('both', app()->environment($this->target), 'APP_ENV matches the target', 'APP_ENV is "'.app()->environment().'", expected "'.$this->target.'"');
        $this->check('both', ! config('app.debug'), 'APP_DEBUG is off', 'APP_DEBUG is on: stack traces and config would be shown to users');
        $this->check('both', filled(config('app.key')), 'APP_KEY is set', 'APP_KEY is empty');
        $this->check('both', Str::startsWith((string) config('app.url'), 'https://'), 'APP_URL uses https', 'APP_URL is not https: '.config('app.url'));

        // Sessions
        $this->check('both', config('session.secure') === true, 'Session cookie is Secure', 'SESSION_SECURE_COOKIE is not true, so the session cookie can travel over plain HTTP');
        $this->check('both', config('session.http_only') === true, 'Session cookie is HttpOnly', 'SESSION_HTTP_ONLY is off');
        $this->check('both', in_array(config('session.same_site'), ['lax', 'strict'], true), 'Session cookie SameSite is lax/strict', 'SESSION_SAME_SITE is "'.config('session.same_site').'"');
        $this->check('both', ! in_array(config('session.driver'), ['array', 'file', 'cookie'], true), 'Sessions stored server-side and shared', 'SESSION_DRIVER is "'.config('session.driver').'": sessions are lost, not shared across servers, or held client-side');

        // Mail and queue
        $this->check('both', ! in_array(config('mail.default'), ['log', 'array'], true), 'Mail is actually delivered', 'MAIL_MAILER is "'.config('mail.default').'": password setup and reset links would never arrive');
        $this->check('both', config('queue.default') !== 'sync', 'Queue runs off the request', 'QUEUE_CONNECTION is sync: mail and jobs run inside web requests');

        // Malware scanning
        $scanner = app(MalwareScannerInterface::class);
        $this->check('both', $scanner->isReal(), 'A real malware scanner is configured', 'MALWARE_SCANNER is "'.config('security.malware.driver').'": uploads are refused (production) or unscanned (elsewhere)');
        $this->check('both', ! ($scanner instanceof ClamAvScanner) || $scanner->ping(), 'The malware scanner answers', 'clamd at '.config('security.malware.clamav.host').':'.config('security.malware.clamav.port').' did not answer PING');

        // Storage
        $this->check('both', config('filesystems.default') !== 'public', 'Default disk is not public', 'FILESYSTEM_DISK is "public": files would be web-reachable by default');
        $this->check('production', config('filesystems.disks.private.driver') === 's3' && filled(config('filesystems.disks.private.bucket')), 'Patient documents on object storage', 'PRIVATE_FILESYSTEM_DRIVER is not s3 (with PRIVATE_AWS_BUCKET): documents live on one app server\'s disk');

        // Backups
        $this->check('both', $this->backupKeyIsValid(), 'Backup encryption key is valid', 'BACKUP_ENCRYPTION_KEY is missing or not 32 bytes: backups cannot be taken');
        $this->check('production', config('filesystems.disks.'.config('security.backup.disk').'.driver') === 's3', 'Backups stored off the server', 'The backup disk is not s3: backups sit on the server they are meant to survive');
        $this->check('production', filled(config('security.backup.verify_connection')), 'A restore-test database is configured', 'BACKUP_VERIFY_CONNECTION is not set: backups can never be verified');
        [$fresh, $freshDetail] = $this->latestBackupIsFresh();
        $this->check('production', $fresh, 'A recent backup exists', $freshDetail);
        [$verified, $verifiedDetail] = $this->latestRestoreTestIsFresh();
        $this->check('production', $verified, 'A recent restore test passed', $verifiedDetail);

        // Security logging
        $this->check('production', in_array(config('logging.channels.'.config('security.audit.log_channel').'.driver'), ['syslog', 'monolog', 'errorlog'], true), 'Security events ship off the server', 'SECURITY_LOG_DRIVER is not syslog or stderr: the security trail exists only on this server');
        $this->check('both', config('security.audit.retention_days') >= 180, 'Security log retention ≥ 180 days', 'SECURITY_AUDIT_RETENTION_DAYS is below 180');

        // Accounts and tenants
        $masterPassword = (string) config('auth.master_admin.password');
        $this->check('both', $masterPassword === '' || (strlen($masterPassword) >= 16 && ! in_array(Str::lower($masterPassword), ['password', 'admin', 'changeme', 'secret'], true)), 'Master admin seed password is not weak', 'MASTER_ADMIN_PASSWORD is shorter than 16 characters or a known default');
        [$allSubscribed, $subscribedDetail] = $this->everyActiveClinicHasASubscription();
        $this->check('production', $allSubscribed, 'Every active clinic has a subscription', $subscribedDetail);

        $this->table(['Status', 'Required for', 'Check', 'Detail'], array_map(fn ($r) => [$r[0], $r[1], $r[2], $r[3]], $this->results));

        $failures = collect($this->results)->where(0, 'FAIL')->count();

        if ($failures > 0) {
            $this->error("{$failures} check(s) required for {$this->target} failed.");

            return self::FAILURE;
        }

        $this->info("All checks required for {$this->target} passed.");

        return self::SUCCESS;
    }

    private function check(string $requiredFor, bool $passes, string $label, string $failure): void
    {
        $applies = $requiredFor === 'both' || $requiredFor === $this->target;
        $status = $passes ? 'PASS' : ($applies ? 'FAIL' : 'WARN');

        $this->results[] = [$status, $requiredFor === 'both' ? 'staging+production' : $requiredFor, $label, $passes ? '' : $failure];
    }

    private function backupKeyIsValid(): bool
    {
        try {
            new BackupEncryptor(config('security.backup.encryption_key'));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function latestBackupIsFresh(): array
    {
        if (! Schema::hasTable('backup_runs')) {
            return [false, 'backup_runs table is missing (migrations not run)'];
        }

        $latest = BackupRun::query()->succeeded()->latest('started_at')->first();
        $maxHours = (int) config('security.backup.max_age_hours');

        if (! $latest) {
            return [false, 'No successful backup has ever been taken'];
        }

        return $latest->started_at->gt(now()->subHours($maxHours))
            ? [true, '']
            : [false, "Newest successful backup is from {$latest->started_at} (older than {$maxHours}h)"];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function latestRestoreTestIsFresh(): array
    {
        if (! Schema::hasTable('backup_runs')) {
            return [false, 'backup_runs table is missing (migrations not run)'];
        }

        $latest = BackupRun::query()->where('verification_status', 'passed')->latest('verified_at')->first();
        $maxDays = (int) config('security.backup.max_verification_age_days');

        if (! $latest) {
            return [false, 'No backup has ever passed a restore test'];
        }

        return $latest->verified_at->gt(now()->subDays($maxDays))
            ? [true, '']
            : [false, "Newest passing restore test was {$latest->verified_at} (older than {$maxDays} days)"];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function everyActiveClinicHasASubscription(): array
    {
        $missing = Clinic::query()
            ->where('status', ClinicStatus::Active)
            ->whereNotIn('id', CompanySubscription::query()->whereNotNull('clinic_id')->select('clinic_id'))
            ->count();

        return $missing === 0
            ? [true, '']
            : [false, "{$missing} active clinic(s) have no subscription row and bypass plan limits; run subscriptions:backfill-legacy"];
    }
}
