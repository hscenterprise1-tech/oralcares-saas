<?php

namespace App\Shared\Search\Jobs;

use App\Shared\Search\Contracts\SearchProviderInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class BulkReindexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $providerClass
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /** @var SearchProviderInterface $provider */
        $provider = app($this->providerClass);

        $provider->getReindexQuery()->chunkById(100, function (Collection $models) {
            foreach ($models as $model) {
                IndexDocumentJob::dispatch($model, $this->providerClass);
            }
        });
    }
}
