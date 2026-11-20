<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Services\Patient\ClinicMorningDigest;
use Illuminate\Console\Command;

/** The morning summary (`ClinicMorningDigest`) for every clinic. */
class SendClinicMorningDigest extends Command
{
    protected $signature = 'clinic:morning-digest';

    protected $description = 'Send each doctor and clinic owner their morning summary';

    public function handle(ClinicMorningDigest $digest): int
    {
        $sent = 0;
        Clinic::query()->select(['id', 'organization_id', 'communication_config'])->chunkById(200, function ($clinics) use ($digest, &$sent) {
            foreach ($clinics as $clinic) {
                $sent += $digest->run($clinic);
            }
        });

        $this->info("Morning summaries sent: {$sent}.");

        return self::SUCCESS;
    }
}
