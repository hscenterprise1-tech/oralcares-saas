<?php

namespace App\Shared\Search\Observers;

use App\Shared\Search\Services\SearchIndexerService;
use Illuminate\Database\Eloquent\Model;

class SearchIndexObserver
{
    public function __construct(
        protected SearchIndexerService $indexer
    ) {}

    public function saved(Model $model): void
    {
        $this->indexer->indexModel($model);
    }

    public function deleted(Model $model): void
    {
        $this->indexer->unindexModel($model);
    }
}
