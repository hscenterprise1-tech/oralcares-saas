<?php

namespace App\Shared\Search\SearchProviders;

use App\Shared\Search\Contracts\SearchProviderInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSearchProvider implements SearchProviderInterface
{
    /**
     * Default implementation to re-index. Returns the base query.
     */
    public function getReindexQuery(): Builder
    {
        $class = $this->getModelClass();

        return $class::query();
    }

    /**
     * Default implementation indexes all records.
     */
    public function shouldIndex(Model $model): bool
    {
        return true;
    }
}
