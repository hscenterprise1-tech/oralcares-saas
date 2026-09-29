<?php

namespace App\Shared\Search\Support;

use App\Shared\Search\Contracts\SearchProviderInterface;
use App\Shared\Search\Jobs\IndexDocumentJob;
use App\Shared\Search\Services\IndexManagerService;
use Illuminate\Database\Eloquent\Model;

class SearchModelObserver
{
    public function __construct(
        protected SearchProviderInterface $provider
    ) {}

    public function saved(Model $model): void
    {
        IndexDocumentJob::dispatch($model, get_class($this->provider));
    }

    public function deleted(Model $model): void
    {
        // When deleted, we can dispatch a job or immediately remove
        // For simplicity, we remove it immediately or dispatch a removal job.
        // I will dispatch the same job, and IndexManagerService handles the check.
        // If it's SoftDeleted, shouldIndex() should return false and remove it.
        // If hard deleted, shouldIndex() fails or model doesn't exist.
        // Wait, if it's hard deleted, the model is already gone from DB.
        // IndexManagerService::removeModel is safer.
        app(IndexManagerService::class)->removeModel($model);
    }

    public function restored(Model $model): void
    {
        IndexDocumentJob::dispatch($model, get_class($this->provider));
    }
}
