<?php

namespace App\Services\Backup;

use App\Models\BackupRun;
use App\Services\Security\SecurityAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The application side of database backup and restore testing.
 *
 *   backup:  dump → gzip → encrypt (AES-256-GCM) → SHA-256 → backup disk
 *            + a manifest (row count per table) recorded in `backup_runs`
 *   verify:  download → check SHA-256 → decrypt (authenticated) → gunzip
 *            → restore into a scratch database → compare every table's row
 *            count with the manifest → record passed / failed
 *   prune:   keep the newest backup of each of the last N days and the
 *            first of each of the last M months; delete the rest
 *
 * Nothing unencrypted ever leaves the work directory, and the work directory
 * is emptied after every run. This complements a managed database's own
 * point-in-time recovery; it does not replace it (docs/security/backups.md).
 */
class BackupManager
{
    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly DatabaseRestorer $restorer,
        private readonly SecurityAudit $audit,
    ) {}

    public function run(): BackupRun
    {
        $run = BackupRun::create(['status' => BackupRun::RUNNING, 'started_at' => now()]);
        $work = $this->workDirectory($run);

        try {
            $encryptor = new BackupEncryptor(config('security.backup.encryption_key'));
            $sql = "{$work}/dump.sql";
            $gz = "{$work}/dump.sql.gz";
            $enc = "{$work}/dump.sql.gz.enc";

            $counts = $this->dumper->dump($sql);
            $this->gzip($sql, $gz);
            @unlink($sql);
            $encryptor->encrypt($gz, $enc);
            @unlink($gz);

            $disk = config('security.backup.disk');
            $path = trim(config('security.backup.path'), '/').'/'.now()->format('Y/m').'/oralcares-'.now()->format('Ymd-His')."-{$run->id}.sql.gz.enc";
            $stream = fopen($enc, 'rb');
            Storage::disk($disk)->writeStream($path, $stream);
            fclose($stream);

            $run->update([
                'status' => BackupRun::SUCCEEDED,
                'disk' => $disk,
                'path' => $path,
                'size_bytes' => filesize($enc),
                'sha256' => hash_file('sha256', $enc),
                'manifest' => ['format' => 'mysql-sql-gzip-aes256gcm-v1', 'tables' => $counts],
                'finished_at' => now(),
            ]);

            $this->audit->record('backup.succeeded', $run, ['size_bytes' => $run->size_bytes, 'tables' => count($counts)]);
        } catch (Throwable $e) {
            $run->update(['status' => BackupRun::FAILED, 'error' => $e->getMessage(), 'finished_at' => now()]);
            $this->audit->record('backup.failed', $run, ['error' => $e->getMessage()]);
        } finally {
            File::deleteDirectory($work);
        }

        return $run->fresh();
    }

    public function verify(BackupRun $run): BackupRun
    {
        $connection = config('security.backup.verify_connection');
        $work = $this->workDirectory($run);

        try {
            if (! $connection) {
                throw new RuntimeException('BACKUP_VERIFY_CONNECTION is not set; there is no scratch database to restore into.');
            }

            if ($connection === config('database.default')) {
                throw new RuntimeException('BACKUP_VERIFY_CONNECTION is the application connection. A restore test must never touch live data.');
            }

            if ($run->status !== BackupRun::SUCCEEDED) {
                throw new RuntimeException("Backup run {$run->id} did not succeed, so there is nothing to verify.");
            }

            $enc = "{$work}/dump.sql.gz.enc";
            $gz = "{$work}/dump.sql.gz";
            $sql = "{$work}/dump.sql";

            $read = Storage::disk($run->disk)->readStream($run->path);
            $local = fopen($enc, 'wb');
            stream_copy_to_stream($read, $local);
            fclose($local);
            fclose($read);

            if (! hash_equals((string) $run->sha256, hash_file('sha256', $enc))) {
                throw new RuntimeException('The stored backup does not match the checksum recorded when it was made.');
            }

            (new BackupEncryptor(config('security.backup.encryption_key')))->decrypt($enc, $gz);
            $this->gunzip($gz, $sql);

            $restored = $this->restorer->restore($sql, $connection);
            $expected = $run->manifest['tables'] ?? [];
            $differences = [];

            foreach ($expected as $table => $count) {
                if (($restored[$table] ?? null) !== $count) {
                    $differences[$table] = ['expected' => $count, 'restored' => $restored[$table] ?? null];
                }
            }

            $passed = $expected !== [] && $differences === [];
            $run->update([
                'verification_status' => $passed ? 'passed' : 'failed',
                'verified_at' => now(),
                'verification_report' => ['tables_checked' => count($expected), 'differences' => $differences],
            ]);
            $this->audit->record($passed ? 'backup.verified' : 'backup.verification_failed', $run, ['differences' => count($differences)]);
        } catch (Throwable $e) {
            $run->update(['verification_status' => 'failed', 'verified_at' => now(), 'verification_report' => ['error' => $e->getMessage()]]);
            $this->audit->record('backup.verification_failed', $run, ['error' => $e->getMessage()]);
        } finally {
            File::deleteDirectory($work);
        }

        return $run->fresh();
    }

    /**
     * @return int Number of backups deleted.
     */
    public function prune(?Carbon $now = null): int
    {
        $now ??= now();
        $keepDaily = (int) config('security.backup.retention.daily');
        $keepMonthly = (int) config('security.backup.retention.monthly');
        $runs = BackupRun::query()->succeeded()->orderByDesc('started_at')->get();
        $keep = [];

        // Newest backup of each of the last N days.
        foreach ($runs->groupBy(fn (BackupRun $run) => $run->started_at->toDateString()) as $day => $dayRuns) {
            if ($now->copy()->startOfDay()->diffInDays(Carbon::parse($day), true) < $keepDaily) {
                $keep[] = $dayRuns->first()->id;
            }
        }

        // Oldest backup of each of the last M months, the month's anchor.
        foreach ($runs->groupBy(fn (BackupRun $run) => $run->started_at->format('Y-m')) as $month => $monthRuns) {
            if ($now->copy()->startOfMonth()->diffInMonths(Carbon::parse("{$month}-01"), true) < $keepMonthly) {
                $keep[] = $monthRuns->last()->id;
            }
        }

        $pruned = 0;

        foreach ($runs->whereNotIn('id', array_unique($keep)) as $run) {
            Storage::disk($run->disk)->delete($run->path);
            $run->update(['status' => BackupRun::PRUNED]);
            $pruned++;
        }

        if ($pruned > 0) {
            $this->audit->record('backup.pruned', null, ['count' => $pruned]);
        }

        return $pruned;
    }

    private function workDirectory(BackupRun $run): string
    {
        $dir = storage_path('app/backup-work/'.$run->id.'-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    private function gzip(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 1024));
        }

        fclose($in);
        gzclose($out);
    }

    private function gunzip(string $source, string $target): void
    {
        $in = gzopen($source, 'rb');
        $out = fopen($target, 'wb');

        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1024 * 1024));
        }

        gzclose($in);
        fclose($out);
    }
}
