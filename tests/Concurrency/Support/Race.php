<?php

namespace Tests\Concurrency\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs requests at the same time, each in its own worker process on its own
 * MySQL connection (`tests/Concurrency/worker.php`).
 *
 * Every worker boots and waits at a file barrier; the parent releases them
 * together. With `holdingLock`, the parent first takes the row lock the
 * workers will need, in its own transaction, then waits until the server
 * shows every worker blocked on that row inside its own transaction
 * (`SHOW PROCESSLIST`, which this account sees for its own connections only),
 * and only then lets go. That is the proof the transactions genuinely overlap:
 * all of them were open and contending at once, not one after another.
 */
final class Race
{
    /**
     * @param  list<array{user_id: int, session: array<string, mixed>, method: string, uri: string, data?: array<string, mixed>, json?: bool}>  $requests
     * @param  array{0: string, 1: int}|null  $holdingLock  [table, id] of the row to hold while the workers pile up on it
     * @return array{results: list<array<string, mixed>>, blocked: list<int>}
     */
    public static function run(array $requests, ?array $holdingLock = null): array
    {
        ConcurrencyDatabase::assertIsolated();

        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oc-race-'.Str::uuid();
        mkdir($barrier);
        $processes = [];

        try {
            foreach (array_values($requests) as $i => $request) {
                file_put_contents($spec = "{$barrier}/spec-{$i}.json", json_encode($request, JSON_THROW_ON_ERROR));
                $processes[$i] = new Process([PHP_BINARY, base_path('tests/Concurrency/worker.php'), $spec, $barrier, (string) $i], base_path(), null, null, 180);
                $processes[$i]->start();
            }

            self::waitUntil(fn () => count(glob("{$barrier}/ready-*")) === count($processes), $processes, 'every worker ready');

            $blocked = [];
            if ($holdingLock !== null) {
                [$table, $id] = $holdingLock;
                DB::beginTransaction();
                DB::table($table)->where('id', $id)->lockForUpdate()->first();
            }

            touch("{$barrier}/go");

            if ($holdingLock !== null) {
                try {
                    $self = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                    self::waitUntil(function () use ($self, $table, $processes, &$blocked) {
                        $blocked = collect(DB::select('SHOW FULL PROCESSLIST'))
                            ->filter(fn ($row) => (int) $row->Id !== $self && $row->db === ConcurrencyDatabase::DATABASE
                                && str_contains((string) $row->Info, $table) && str_contains(strtolower((string) $row->Info), 'for update'))
                            ->map(fn ($row) => (int) $row->Id)->values()->all();

                        return count($blocked) === count($processes);
                    }, $processes, 'every worker blocked on the held row');
                } finally {
                    DB::commit();
                }
            }

            $results = [];
            foreach ($processes as $i => $process) {
                $process->wait();
                $line = collect(explode("\n", $process->getOutput()))->first(fn ($l) => str_starts_with($l, 'RACE-RESULT '));
                if ($line === null) {
                    throw new RuntimeException("Worker {$i} reported nothing (exit {$process->getExitCode()}): ".Str::limit($process->getErrorOutput().$process->getOutput(), 2000));
                }
                $results[$i] = json_decode(substr($line, strlen('RACE-RESULT ')), true, flags: JSON_THROW_ON_ERROR);
            }

            $race = ['results' => array_values($results), 'blocked' => $blocked];

            // Opt-in evidence trail: each race's connections, blocked waiters and outcomes.
            if ($evidence = getenv('RACE_EVIDENCE_FILE')) {
                file_put_contents($evidence, json_encode(['test' => test()->name(), 'lock' => $holdingLock, ...$race])."\n", FILE_APPEND);
            }

            return $race;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            array_map('unlink', glob("{$barrier}/*") ?: []);
            @rmdir($barrier);
        }
    }

    /**
     * Poll a condition (never a fixed sleep), failing fast if a worker dies.
     *
     * @param  array<int, Process>  $processes
     */
    private static function waitUntil(callable $condition, array $processes, string $what): void
    {
        $deadline = microtime(true) + 90;
        while (! $condition()) {
            foreach ($processes as $i => $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException("Worker {$i} stopped before {$what} (exit {$process->getExitCode()}): ".Str::limit($process->getErrorOutput().$process->getOutput(), 2000));
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Timed out waiting for {$what}.");
            }
            usleep(5000);
        }
    }
}
