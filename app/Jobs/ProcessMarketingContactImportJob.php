<?php

namespace App\Jobs;

use App\Models\MarketingContactImport;
use App\Services\Marketing\ContactImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMarketingContactImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function __construct(
        public int $importId,
        public array $rows,
        public ?int $organizationId,
        public ?int $userId
    ) {}

    public function handle(ContactImportService $importService): void
    {
        $import = MarketingContactImport::find($this->importId);

        if (! $import) {
            return;
        }

        $import->markProcessing();

        try {
            $result = $importService->processImport($this->rows, $this->organizationId, $this->userId);
            $import->markCompleted($result);
        } catch (\Throwable $e) {
            $import->markFailed($e->getMessage());
        }
    }
}
