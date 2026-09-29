<?php

namespace App\Shared\Search\Services;

use App\Models\Clinic;
use App\Models\CommercialDocument;
use App\Models\CommercialInvoice;
use App\Models\CompanyEmployee;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Shared\Search\Enums\SearchModule;
use App\Shared\Search\Models\SearchDocument;
use Illuminate\Database\Eloquent\Model;

class SearchIndexerService
{
    /**
     * Index or update a model record in search_documents index.
     */
    public function indexModel(Model $model): ?SearchDocument
    {
        $payload = $this->extractSearchPayload($model);
        if (! $payload) {
            return null;
        }

        return SearchDocument::updateOrCreate(
            [
                'model_type' => get_class($model),
                'model_id' => (int) $model->getKey(),
            ],
            [
                'module' => $payload['module'],
                'clinic_id' => $payload['clinic_id'] ?? null,
                'branch_id' => $payload['branch_id'] ?? null,
                'title' => $payload['title'],
                'subtitle' => $payload['subtitle'] ?? null,
                'url' => $payload['url'] ?? null,
                'searchable_text' => $payload['searchable_text'],
                'metadata' => $payload['metadata'] ?? [],
            ]
        );
    }

    /**
     * Remove a model record from search_documents index.
     */
    public function unindexModel(Model $model): void
    {
        SearchDocument::where('model_type', get_class($model))
            ->where('model_id', (int) $model->getKey())
            ->delete();
    }

    protected function stringify($value, string $default = ''): string
    {
        if (is_null($value)) {
            return $default;
        }
        if (is_object($value)) {
            return (string) ($value->value ?? $value->name ?? $default);
        }

        return (string) $value;
    }

    /**
     * Extract normalized search attributes from different model types.
     */
    protected function extractSearchPayload(Model $model): ?array
    {
        if ($model instanceof Lead) {
            $name = $model->name ?? $model->company_name ?? $model->contact_name ?? 'Lead #'.$model->id;
            $statusStr = $this->stringify($model->status, 'new');
            $companyName = $model->company_name ?? '';
            $subtitle = ! empty($companyName) ? $companyName.' • '.ucfirst($statusStr) : 'Lead • '.ucfirst($statusStr);

            return [
                'module' => SearchModule::CRM,
                'clinic_id' => $model->clinic_id ?? null,
                'title' => $name,
                'subtitle' => $subtitle,
                'url' => '/company/crm/leads/'.$model->id,
                'searchable_text' => implode(' ', array_filter([
                    $name,
                    $companyName,
                    $model->email ?? '',
                    $model->phone ?? '',
                    $statusStr,
                    $model->source ?? '',
                ])),
                'metadata' => ['status' => $statusStr],
            ];
        }

        if ($model instanceof Organization) {
            $name = $model->name ?? $model->company_name ?? $model->legal_name ?? 'Organization #'.$model->id;
            $subtitle = 'Organization • '.($model->city ?? 'Enterprise');

            return [
                'module' => SearchModule::CRM,
                'clinic_id' => $model->clinic_id ?? null,
                'title' => $name,
                'subtitle' => $subtitle,
                'url' => '/company/crm/organizations/'.$model->id,
                'searchable_text' => implode(' ', array_filter([
                    $name,
                    $model->email ?? '',
                    $model->phone ?? '',
                    $model->city ?? '',
                ])),
                'metadata' => [],
            ];
        }

        if ($model instanceof Clinic) {
            $name = $model->name ?? 'Clinic #'.$model->id;
            $statusStr = $this->stringify($model->status, 'active');
            $subtitle = 'Clinic • '.($model->city ?? $statusStr);

            return [
                'module' => SearchModule::CLINICAL,
                'clinic_id' => $model->id,
                'title' => $name,
                'subtitle' => $subtitle,
                /*
                 * The account, not the retired clinic console. A clinic is
                 * managed on the account that owns it; one carrying no
                 * organisation has nowhere to send anybody.
                 */
                'url' => $model->organization_id
                    ? '/company/customer-success/accounts/'.$model->organization_id.'?tab=clinics'
                    : null,
                'searchable_text' => implode(' ', array_filter([
                    $name,
                    $model->code ?? '',
                    $model->email ?? '',
                    $model->phone ?? '',
                    $model->city ?? '',
                    $statusStr,
                ])),
                'metadata' => ['status' => $statusStr],
            ];
        }

        if ($model instanceof CommercialInvoice) {
            $number = $model->invoice_number ?? 'INV-'.$model->id;
            $statusStr = $this->stringify($model->status, 'unpaid');
            $subtitle = 'Invoice • '.($model->billing_name ?? 'Client').' • ₹'.number_format($model->total_amount ?? 0);

            return [
                'module' => SearchModule::BILLING,
                'clinic_id' => $model->clinic_id ?? null,
                'title' => $number,
                'subtitle' => $subtitle,
                /*
                 * `/company/finance/invoices/{id}` has no route, so every
                 * invoice result led to a 404. Finance exposes an index and
                 * no single-invoice page; the index takes a search term, so
                 * the result opens the list narrowed to this invoice.
                 */
                'url' => '/company/finance/invoices?search='.urlencode($number),
                'searchable_text' => implode(' ', array_filter([
                    $number,
                    $model->billing_name ?? '',
                    $statusStr,
                ])),
                'metadata' => ['status' => $statusStr],
            ];
        }

        if ($model instanceof Task) {
            $title = $model->title ?? 'Task #'.$model->id;
            $priorityStr = $this->stringify($model->priority, 'normal');
            $statusStr = $this->stringify($model->status, 'pending');
            $subtitle = 'Task • Priority: '.ucfirst($priorityStr).' • '.ucfirst($statusStr);

            return [
                'module' => SearchModule::WORKFLOW,
                'clinic_id' => $model->clinic_id ?? null,
                'title' => $title,
                'subtitle' => $subtitle,
                /*
                 * Same as invoices: there is no single-task page, only the
                 * index, and it filters on a search term.
                 */
                'url' => '/company/crm/tasks?search='.urlencode($title),
                'searchable_text' => implode(' ', array_filter([
                    $title,
                    $model->description ?? '',
                    $statusStr,
                    $priorityStr,
                ])),
                'metadata' => ['status' => $statusStr, 'priority' => $priorityStr],
            ];
        }

        if ($model instanceof CommercialDocument) {
            /*
             * These are historical records. CC3 retired the documents surface,
             * so there is no page of their own to send anybody to — the deal is
             * where the document's context actually lives, and where its
             * quotations, payments and invoice sit beside it.
             *
             * The columns are `document_number` and `type`; `title` and
             * `document_type` never existed on this model, so every result was
             * titled "Document #17" and subtitled "Commercial".
             */
            $title = $model->document_number ?? 'Document #'.$model->id;
            $statusStr = $this->stringify($model->status, 'active');
            $docType = $this->stringify($model->type, 'commercial');
            $subtitle = 'Document • '.ucfirst($docType);

            return [
                'module' => SearchModule::WORKFLOW,
                'clinic_id' => $model->clinic_id ?? null,
                'title' => $title,
                'subtitle' => $subtitle,
                'url' => $model->lead_id ? '/company/crm/leads/'.$model->lead_id : null,
                'searchable_text' => implode(' ', array_filter([
                    $title,
                    $docType,
                    $statusStr,
                ])),
                'metadata' => ['status' => $statusStr],
            ];
        }

        if ($model instanceof CompanyEmployee) {
            $name = $model->name ?? 'Employee #'.$model->id;
            $subtitle = 'Employee • '.($model->designation ?? $model->department ?? 'Staff');

            return [
                'module' => SearchModule::HR,
                'clinic_id' => $model->clinic_id ?? null,
                'title' => $name,
                'subtitle' => $subtitle,
                'url' => '/company/people/employees/'.$model->id,
                'searchable_text' => implode(' ', array_filter([
                    $name,
                    $model->email ?? '',
                    $model->phone ?? '',
                    $model->designation ?? '',
                    $model->department ?? '',
                ])),
                'metadata' => ['department' => $model->department ?? 'General'],
            ];
        }

        if ($model instanceof User) {
            $name = $model->name ?? $model->email ?? 'User #'.$model->id;
            $roleStr = $this->stringify($model->role, 'user');
            $subtitle = 'User • '.ucfirst($roleStr);

            return [
                'module' => SearchModule::HR,
                'clinic_id' => $model->clinic_id ?? null,
                'title' => $name,
                'subtitle' => $subtitle,
                'url' => '/company/people/employees/'.$model->id,
                'searchable_text' => implode(' ', array_filter([
                    $name,
                    $model->email ?? '',
                    $roleStr,
                ])),
                'metadata' => ['role' => $roleStr],
            ];
        }

        return null;
    }
}
