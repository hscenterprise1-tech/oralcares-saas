<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Backup\BackupManager;
use Illuminate\Console\Command;

class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify {run? : Backup run id (defaults to the newest successful backup)}';

    protected $description = 'Restore a backup into the scratch database and check it against its manifest';

    public function handle(BackupManager $backups): int
    {
        $run = $this->argument('run')
            ? BackupRun::find($this->argument('run'))
            : BackupRun::query()->succeeded()->latest('started_at')->first();

        if (! $run) {
            $this->error('No backup to verify.');

            return self::FAILURE;
        }

        $run = $backups->verify($run);

        if ($run->verification_status !== 'passed') {
            $this->error("Restore test FAILED for backup {$run->id}: ".json_encode($run->verification_report));

            return self::FAILURE;
        }

        $this->info("Restore test passed for backup {$run->id}: {$run->verification_report['tables_checked']} tables restored and matched.");

        return self::SUCCESS;
    }
}
