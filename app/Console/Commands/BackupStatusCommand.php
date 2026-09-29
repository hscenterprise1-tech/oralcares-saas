<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use Illuminate\Console\Command;

class BackupStatusCommand extends Command
{
    protected $signature = 'backup:status';

    protected $description = 'Show the latest backup and restore-test results';

    public function handle(): int
    {
        $latest = BackupRun::query()->succeeded()->latest('started_at')->first();
        $verified = BackupRun::query()->where('verification_status', 'passed')->latest('verified_at')->first();
        $lastFailure = BackupRun::query()->where('status', BackupRun::FAILED)->latest('started_at')->first();

        $this->table(['Item', 'Value'], [
            ['Latest successful backup', $latest ? "#{$latest->id} at {$latest->started_at} ({$latest->started_at->diffForHumans()})" : 'none'],
            ['Latest passed restore test', $verified ? "#{$verified->id} verified {$verified->verified_at->diffForHumans()}" : 'none (no backup is verified yet)'],
            ['Latest failed backup', $lastFailure ? "#{$lastFailure->id} at {$lastFailure->started_at}: {$lastFailure->error}" : 'none'],
        ]);

        return self::SUCCESS;
    }
}
