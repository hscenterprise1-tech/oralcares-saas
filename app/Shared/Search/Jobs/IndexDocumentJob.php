<?php

namespace App\Shared\Search\Jobs;

use App\Shared\Search\Contracts\SearchProviderInterface;
use App\Shared\Search\Services\IndexManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Model $model,
        public string $providerClass
    ) {}

    /**
     * Execute the job.
     */
    public function handle(IndexManagerService $indexManager): void
    {
        /** @var SearchProviderInterface $provider */
        $provider = app($this->providerClass);

        $indexManager->indexModel($this->model, $provider);
    }
}
