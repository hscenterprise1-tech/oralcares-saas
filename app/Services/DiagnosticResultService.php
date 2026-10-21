<?php

namespace App\Services;

use App\Models\ClinicalAttachment;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicEmployee;
use App\Models\DiagnosticResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Result entry for callers that work with results directly (integrations,
 * older code). It writes through `ClinicalOrderService`, the one
 * investigations lifecycle, so the same state rules and audit apply.
 */
class DiagnosticResultService
{
    public function __construct(protected ClinicalOrderService $orders) {}

    /**
     * Store (or replace, until reviewed) the result for an order item.
     *
     * @param  array<string, mixed>  $content
     */
    public function storeDraft(ClinicalOrderItem $item, array $content, string $type, ?User $reporter = null): DiagnosticResult
    {
        return $this->orders->recordResult($item, $this->employeeFor($item, $reporter), [
            'summary' => is_string($content['text'] ?? null) ? $content['text'] : null,
            'content' => [...$content, 'type' => $type],
        ], $reporter);
    }

    /**
     * Review the result: it becomes final and its order item is reviewed.
     */
    public function verifyAndFinalize(DiagnosticResult $result, User $verifier): DiagnosticResult
    {
        $item = ClinicalOrderItem::query()->withoutGlobalScopes()->findOrFail($result->clinical_order_item_id);

        return $this->orders->review($item, $this->employeeFor($item, $verifier), null, $verifier);
    }

    /**
     * Attach a reference (e.g. a PACS viewer link) to a result. Uploaded
     * files go through the scanned patient-document upload instead.
     */
    public function attachFile(Model $attachable, string $filePath, string $type, string $title, ?string $description = null): ClinicalAttachment
    {
        return $attachable->attachments()->create([
            'organization_id' => $attachable->organization_id,
            'file_path' => $filePath,
            'file_type' => $type,
            'title' => $title,
            'description' => $description,
        ]);
    }

    private function employeeFor(ClinicalOrderItem $item, ?User $user): ?ClinicEmployee
    {
        return $user ? ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $item->clinic_id)->where('user_id', $user->id)->first() : null;
    }
}
