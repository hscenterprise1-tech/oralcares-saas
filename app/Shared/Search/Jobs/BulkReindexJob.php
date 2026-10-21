<?php

namespace App\Shared\Search\Jobs;

use App\Services\ClinicContext;
use App\Shared\Search\Contracts\SearchProviderInterface;
use App\Shared\Search\Services\IndexManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class BulkReindexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $providerClass
    ) {
        $this->onQueue(config('queue.queues.search'));
    }

    /**
     * Execute the job.
     *
     * Indexes in pages of 500 inside this job. It used to dispatch one job
     * per record, which for the patient index is one job per patient of every
     * clinic — hundreds of millions of queue entries at scale. It also read
     * clinic-scoped models in a queued job with no tenant context, which the
     * tenant scope refuses; a reindex is deliberately cross-tenant and says so.
     */
    public function handle(IndexManagerService $indexManager, ClinicContext $context): void
    {
        /** @var SearchProviderInterface $provider */
        $provider = app($this->providerClass);

        $context->withoutTenancy(fn () => $provider->getReindexQuery()->chunkById(500, function (Collection $models) use ($indexManager, $provider) {
            foreach ($models as $model) {
                $indexManager->indexModel($model, $provider);
            }
        }));
    }
}
