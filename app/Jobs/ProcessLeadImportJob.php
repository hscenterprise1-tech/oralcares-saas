<?php

namespace App\Jobs;

use App\Models\LeadImport;
use App\Models\User;
use App\Services\CRM\LeadImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The queued half of a large lead import — see
 * `LeadImportController::process()` for the size threshold a file has to
 * cross to land here rather than running inline in the request.
 */
class ProcessLeadImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function __construct(
        protected int $importId,
        protected array $rows,
        protected ?int $organizationId,
        protected ?int $userId,
    ) {}

    public function handle(LeadImportService $importer): void
    {
        $import = LeadImport::find($this->importId);

        if (! $import) {
            return;
        }

        /*
         * A redelivered job (the queue's visibility timeout firing on a
         * still-running attempt) or a manual retry must not import the same
         * file twice. Only the worker that wins this atomic claim proceeds —
         * everyone else finds the row already `processing`/`completed` and
         * quietly stops.
         */
        if (! $import->markProcessingIfPending()) {
            return;
        }

        try {
            $result = $importer->processImport(
                $import,
                $this->rows,
                $this->organizationId,
                $this->userId ? User::find($this->userId) : null,
            );

            $import->markCompleted($result);
        } catch (\Throwable $e) {
            $import->markFailed($e->getMessage());
        }
    }
}
