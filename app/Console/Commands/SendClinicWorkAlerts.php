<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Services\Patient\ClinicWorkAlerts;
use Illuminate\Console\Command;

/**
 * The evening run of `ClinicWorkAlerts` for every clinic: unsigned
 * consultations, results waiting for review, overdue lab cases, stalled
 * plans and quiet cases, each told once to the staff who own it.
 */
class SendClinicWorkAlerts extends Command
{
    protected $signature = 'clinic:staff-alerts';

    protected $description = 'Tell clinic staff about unsigned consultations, unreviewed results, overdue lab cases, stalled plans and quiet cases';

    public function handle(ClinicWorkAlerts $alerts): int
    {
        $totals = ['unsigned' => 0, 'results' => 0, 'lab' => 0, 'plans' => 0, 'cases' => 0];
        Clinic::query()->select(['id', 'organization_id', 'communication_config'])->chunkById(200, function ($clinics) use ($alerts, &$totals) {
            foreach ($clinics as $clinic) {
                foreach ($alerts->run($clinic) as $kind => $count) {
                    $totals[$kind] += $count;
                }
            }
        });

        $this->info('Staff alerts sent: '.collect($totals)->map(fn (int $count, string $kind) => "{$kind} {$count}")->implode(', '));

        return self::SUCCESS;
    }
}
