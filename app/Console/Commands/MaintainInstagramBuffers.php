<?php

namespace App\Console\Commands;

use App\Jobs\MaintainInstagramBufferJob;
use App\Models\MarketingInstagramAccount;
use Illuminate\Console\Command;

class MaintainInstagramBuffers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'instagram:maintain-buffers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Top up every organization\'s Instagram content queue back up to its configured buffer size.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accounts = MarketingInstagramAccount::where('auto_fill_enabled', true)->get();

        foreach ($accounts as $account) {
            MaintainInstagramBufferJob::dispatch($account->id);
        }

        $this->info("Dispatched buffer maintenance for {$accounts->count()} organization(s).");

        return self::SUCCESS;
    }
}
