<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Backup\BackupManager;
use Illuminate\Console\Command;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run {--no-prune : Keep every existing backup this time}';

    protected $description = 'Take an encrypted database backup, then apply the retention policy';

    public function handle(BackupManager $backups): int
    {
        $run = $backups->run();

        if ($run->status !== BackupRun::SUCCEEDED) {
            $this->error("Backup {$run->id} failed: {$run->error}");

            return self::FAILURE;
        }

        $this->info("Backup {$run->id} stored at {$run->disk}:{$run->path} ({$run->size_bytes} bytes). Not verified until `backup:verify` passes.");

        if (! $this->option('no-prune')) {
            $this->line('Pruned: '.$backups->prune());
        }

        return self::SUCCESS;
    }
}
