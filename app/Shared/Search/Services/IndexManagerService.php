<?php

namespace App\Shared\Search\Services;

use App\Shared\Search\Contracts\SearchProviderInterface;
use App\Shared\Search\Models\SearchDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class IndexManagerService
{
    /**
     * Index a single Eloquent model using its mapped provider.
     */
    public function indexModel(Model $model, SearchProviderInterface $provider): void
    {
        if (! $provider->shouldIndex($model)) {
            $this->removeModel($model);

            return;
        }

        try {
            $dto = $provider->toSearchDocument($model);

            SearchDocument::updateOrCreate(
                [
                    'model_type' => $dto->modelType,
                    'model_id' => $dto->modelId,
                ],
                [
                    'module' => $dto->module,
                    'clinic_id' => $dto->clinicId,
                    'branch_id' => $dto->branchId,
                    'title' => $dto->title,
                    'subtitle' => $dto->subtitle,
                    'url' => $dto->url,
                    'searchable_text' => $dto->searchableText,
                    'metadata' => $dto->metadata,
                    'updated_at' => now(), // Touch timestamp to keep it fresh
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to index model', [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove a model from the search index.
     */
    public function removeModel(Model $model): void
    {
        SearchDocument::where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->delete();
    }
}
