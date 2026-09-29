<?php

namespace App\Shared\Search\Contracts;

use App\Shared\Search\DTO\SearchDocumentDTO;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface SearchProviderInterface
{
    /**
     * Get the FQCN of the primary model this provider handles.
     *
     * @return class-string
     */
    public function getModelClass(): string;

    /**
     * Determine if a given model instance should be indexed.
     * Useful for excluding drafts, archived records, etc.
     */
    public function shouldIndex(Model $model): bool;

    /**
     * Map the Eloquent model to a standard SearchDocumentDTO.
     */
    public function toSearchDocument(Model $model): SearchDocumentDTO;

    /**
     * Get a query builder optimized for chunking to perform full re-indexes.
     * Use eager loading here if relationships are needed for `toSearchDocument`.
     */
    public function getReindexQuery(): Builder;
}
