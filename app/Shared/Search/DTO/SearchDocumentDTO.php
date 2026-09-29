<?php

namespace App\Shared\Search\DTO;

use App\Shared\Search\Enums\SearchModule;

class SearchDocumentDTO
{
    /**
     * @param  SearchModule  $module  The domain module this document belongs to.
     * @param  class-string  $modelType  The FQCN of the Eloquent model.
     * @param  int|string  $modelId  The primary key of the model.
     * @param  string  $title  The primary display title.
     * @param  string  $searchableText  All text that should be indexed for full-text search.
     * @param  string|null  $subtitle  Secondary display text.
     * @param  string|null  $url  The relative URL to view this resource.
     * @param  int|null  $clinicId  Tenant clinic ID.
     * @param  int|null  $branchId  Tenant branch ID.
     * @param  array  $metadata  Any JSON metadata (e.g. status, priority, tags) used for advanced filtering.
     */
    public function __construct(
        public SearchModule $module,
        public string $modelType,
        public string|int $modelId,
        public string $title,
        public string $searchableText,
        public ?string $subtitle = null,
        public ?string $url = null,
        public ?int $clinicId = null,
        public ?int $branchId = null,
        public array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'module' => $this->module->value,
            'model_type' => $this->modelType,
            'model_id' => $this->modelId,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'searchable_text' => $this->searchableText,
            'url' => $this->url,
            'clinic_id' => $this->clinicId,
            'branch_id' => $this->branchId,
            'metadata' => json_encode($this->metadata),
        ];
    }
}
