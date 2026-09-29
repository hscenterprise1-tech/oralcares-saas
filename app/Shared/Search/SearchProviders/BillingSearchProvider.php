<?php

namespace App\Shared\Search\SearchProviders;

use App\Models\Invoice; // Assuming an Invoice model exists
use App\Shared\Search\DTO\SearchDocumentDTO;
use App\Shared\Search\Enums\SearchModule;
use Illuminate\Database\Eloquent\Model;

class BillingSearchProvider extends AbstractSearchProvider
{
    public function getModelClass(): string
    {
        return Invoice::class;
    }

    public function toSearchDocument(Model $model): SearchDocumentDTO
    {
        /** @var Invoice $model */
        return new SearchDocumentDTO(
            module: SearchModule::BILLING,
            modelType: $this->getModelClass(),
            modelId: $model->id,
            title: $model->invoice_number ?? 'Invoice #'.$model->id,
            searchableText: implode(' ', array_filter([
                $model->invoice_number,
                $model->status,
                $model->total_amount,
            ])),
            subtitle: 'Amount: '.($model->total_amount ?? '0.00'),
            url: "/billing/invoices/{$model->id}",
            clinicId: $model->clinic_id ?? null,
            branchId: $model->branch_id ?? null,
            metadata: [
                'status' => $model->status ?? 'pending',
                'amount' => $model->total_amount ?? 0,
            ]
        );
    }
}
