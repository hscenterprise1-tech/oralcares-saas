<?php

use App\Models\BackupRun;
use App\Models\SecurityAuditLog;
use App\Services\Backup\BackupEncryptor;
use App\Services\Backup\BackupManager;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\DatabaseRestorer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Stand-ins for mysqldump and the mysql client, which do not exist in the test
 * environment. Everything between them (compression, encryption, checksums,
 * storage, manifest comparison, retention) is the real code.
 */
class FakeDumper implements DatabaseDumper
{
    public ?Throwable $failWith = null;

    public function __construct(public array $counts = ['patients' => 3, 'appointments' => 5]) {}

    public function dump(string $targetPath): array
    {
        if ($this->failWith) {
            throw $this->failWith;
        }

        file_put_contents($targetPath, "-- dump\n".json_encode($this->counts)."\n".str_repeat('INSERT INTO t VALUES (1);', 90000));

        return $this->counts;
    }
}

class FakeRestorer implements DatabaseRestorer
{
    public ?string $restoredSql = null;

    public ?array $override = null;

    public function restore(string $sqlPath, string $connection): array
    {
        $this->restoredSql = file_get_contents($sqlPath);
        preg_match('/^-- dump\n(.+)$/m', $this->restoredSql, $m);

        return $this->override ?? json_decode($m[1], true);
    }
}

function backupKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

beforeEach(function () {
    Storage::fake('backups');
    config([
        'security.backup.encryption_key' => backupKey(),
        'security.backup.verify_connection' => 'restore_scratch',
    ]);
    $this->dumper = new FakeDumper;
    $this->restorer = new FakeRestorer;
    app()->instance(DatabaseDumper::class, $this->dumper);
    app()->instance(DatabaseRestorer::class, $this->restorer);
});

it('stores an encrypted, checksummed backup with its manifest', function () {
    $run = app(BackupManager::class)->run();

    expect($run->status)->toBe('succeeded')
        ->and($run->manifest['tables'])->toBe(['patients' => 3, 'appointments' => 5]);

    $stored = Storage::disk('backups')->get($run->path);
    expect(hash('sha256', $stored))->toBe($run->sha256)
        ->and($stored)->toStartWith('OCBK1')
        ->and($stored)->not->toContain('INSERT INTO');

    expect(SecurityAuditLog::where('event', 'backup.succeeded')->exists())->toBeTrue()
        ->and(glob(storage_path('app/backup-work/*')))->toBe([]);
});

it('refuses to write a backup without an encryption key', function () {
    config(['security.backup.encryption_key' => null]);

    $run = app(BackupManager::class)->run();

    expect($run->status)->toBe('failed')
        ->and($run->error)->toContain('BACKUP_ENCRYPTION_KEY')
        ->and(Storage::disk('backups')->allFiles())->toBe([]);
});

it('records a failed dump as failed, not as a backup', function () {
    $this->dumper->failWith = new RuntimeException('mysqldump: access denied');

    $run = app(BackupManager::class)->run();

    expect($run->status)->toBe('failed')
        ->and(SecurityAuditLog::where('event', 'backup.failed')->sole()->context['error'])->toContain('access denied');
    $this->artisan('backup:run')->assertExitCode(1);
});

it('passes a restore test only when every table matches the manifest', function () {
    $run = app(BackupManager::class)->run();

    $verified = app(BackupManager::class)->verify($run);

    expect($verified->verification_status)->toBe('passed')
        ->and($this->restorer->restoredSql)->toContain('INSERT INTO t VALUES');
});

it('fails the restore test when a restored table differs', function () {
    $run = app(BackupManager::class)->run();
    $this->restorer->override = ['patients' => 3, 'appointments' => 4];

    $verified = app(BackupManager::class)->verify($run);

    expect($verified->verification_status)->toBe('failed')
        ->and($verified->verification_report['differences'])->toBe(['appointments' => ['expected' => 5, 'restored' => 4]]);
});

it('fails the restore test when the stored file was altered', function () {
    $run = app(BackupManager::class)->run();
    $bytes = Storage::disk('backups')->get($run->path);
    $bytes[200] = $bytes[200] === 'A' ? 'B' : 'A';
    Storage::disk('backups')->put($run->path, $bytes);

    expect(app(BackupManager::class)->verify($run)->verification_report['error'])->toContain('checksum');
});

it('never restores into the application database', function () {
    config(['security.backup.verify_connection' => config('database.default')]);
    $run = app(BackupManager::class)->run();

    $verified = app(BackupManager::class)->verify($run);

    expect($verified->verification_status)->toBe('failed')
        ->and($this->restorer->restoredSql)->toBeNull();
});

it('detects tampering, truncation and a wrong key when decrypting', function () {
    $key = backupKey();
    $plain = tempnam(sys_get_temp_dir(), 'p');
    $enc = tempnam(sys_get_temp_dir(), 'e');
    $out = tempnam(sys_get_temp_dir(), 'o');
    file_put_contents($plain, random_bytes(2_500_000)); // spans three chunks
    (new BackupEncryptor($key))->encrypt($plain, $enc);

    (new BackupEncryptor($key))->decrypt($enc, $out);
    expect(hash_file('sha256', $out))->toBe(hash_file('sha256', $plain));

    expect(fn () => (new BackupEncryptor(backupKey()))->decrypt($enc, $out))->toThrow(RuntimeException::class);

    $bytes = file_get_contents($enc);
    file_put_contents($enc, substr($bytes, 0, 1_200_000));
    expect(fn () => (new BackupEncryptor($key))->decrypt($enc, $out))->toThrow(RuntimeException::class, 'truncated');

    $bytes[500] = chr(ord($bytes[500]) ^ 1);
    file_put_contents($enc, $bytes);
    expect(fn () => (new BackupEncryptor($key))->decrypt($enc, $out))->toThrow(RuntimeException::class);

    array_map('unlink', [$plain, $enc, $out]);
});

it('keeps 35 daily and 12 monthly backups and deletes the rest', function () {
    $now = Carbon::parse('2026-09-29 02:00');

    foreach (range(0, 399) as $daysAgo) {
        $at = $now->copy()->subDays($daysAgo)->setTime(1, 30);
        $path = "database/{$daysAgo}.enc";
        Storage::disk('backups')->put($path, 'x');
        BackupRun::create(['status' => 'succeeded', 'disk' => 'backups', 'path' => $path, 'started_at' => $at, 'finished_at' => $at]);
    }

    app(BackupManager::class)->prune($now);

    $kept = BackupRun::where('status', 'succeeded')->pluck('started_at');
    $recent = $kept->filter(fn ($at) => $at->gte($now->copy()->subDays(34)->startOfDay()));

    expect($recent)->toHaveCount(35)
        ->and($kept->map(fn ($at) => $at->format('Y-m'))->unique()->count())->toBe(12)
        ->and($kept->min()->gte($now->copy()->subMonths(11)->startOfMonth()))->toBeTrue()
        ->and(count(Storage::disk('backups')->allFiles()))->toBe($kept->count());
});
