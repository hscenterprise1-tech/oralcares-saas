<?php

namespace App\Shared\Search\SearchProviders;

use App\Models\PatientBilling;
use App\Shared\Search\DTO\SearchDocumentDTO;
use App\Shared\Search\Enums\SearchModule;
use Illuminate\Database\Eloquent\Model;

/**
 * Clinic bills in the search index, findable by number inside their own
 * clinic only (see `SearchRepository::CLINIC_DOCUMENT_TYPES`).
 */
class BillingSearchProvider extends AbstractSearchProvider
{
    public function getModelClass(): string
    {
        return PatientBilling::class;
    }

    public function toSearchDocument(Model $model): SearchDocumentDTO
    {
        /** @var PatientBilling $model */
        return new SearchDocumentDTO(
            module: SearchModule::BILLING,
            modelType: $this->getModelClass(),
            modelId: $model->id,
            title: $model->billing_number,
            searchableText: implode(' ', array_filter([$model->billing_number, $model->status?->value])),
            subtitle: 'Amount: ₹'.number_format((float) $model->final_amount, 2),
            url: "/clinic/patients/{$model->patient_id}?tab=billing",
            clinicId: $model->clinic_id,
            branchId: $model->branch_id,
            metadata: [
                'status' => $model->status?->value,
                'amount' => (float) $model->final_amount,
            ]
        );
    }
}
