<?php

namespace App\Shared\Search\SearchProviders;

use App\Models\Patient;
use App\Shared\Search\DTO\SearchDocumentDTO;
use App\Shared\Search\Enums\SearchModule;
use Illuminate\Database\Eloquent\Model;

class PatientSearchProvider extends AbstractSearchProvider
{
    public function getModelClass(): string
    {
        return Patient::class;
    }

    public function toSearchDocument(Model $model): SearchDocumentDTO
    {
        /** @var Patient $model */
        return new SearchDocumentDTO(
            module: SearchModule::PATIENT,
            modelType: $this->getModelClass(),
            modelId: $model->id,
            title: $model->name ?? 'Unknown Patient',
            searchableText: implode(' ', array_filter([
                $model->name,
                $model->phone,
                $model->email,
                $model->mrn ?? '', // Medical Record Number
            ])),
            subtitle: $model->phone ?? $model->email,
            url: "/patients/{$model->id}",
            clinicId: $model->clinic_id ?? null,
            branchId: $model->branch_id ?? null,
            metadata: [
                'status' => $model->status ?? 'active',
            ]
        );
    }
}
