<?php

namespace App\Services;

use App\Models\ClinicalAttachment;
use App\Models\ClinicalOrderItem;
use App\Models\DiagnosticResult;
use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DiagnosticResultService
{
    /**
     * Store a new draft result for an order item.
     */
    public function storeDraft(ClinicalOrderItem $item, array $content, string $type, ?User $reporter = null): DiagnosticResult
    {
        if ($item->status === 'completed' || $item->status === 'cancelled') {
            throw new Exception('Cannot add results to a completed or cancelled order item.');
        }

        return DiagnosticResult::create([
            'organization_id' => $item->organization_id ?? $item->order?->organization_id,
            'patient_id' => $item->order->patient_id,
            'clinical_order_item_id' => $item->id,
            'status' => 'draft',
            'result_type' => $type,
            'reported_by' => $reporter?->id,
            'content' => $content,
        ]);
    }

    /**
     * Verify and finalize a result, automatically completing the parent order item.
     */
    public function verifyAndFinalize(DiagnosticResult $result, User $verifier): DiagnosticResult
    {
        if ($result->status === 'final') {
            throw new Exception('Result is already finalized.');
        }

        return DB::transaction(function () use ($result, $verifier) {
            $result->update([
                'status' => 'final',
                'verified_by' => $verifier->id,
                'verified_at' => now(),
            ]);

            // Complete the parent order item
            $result->orderItem->update([
                'status' => 'completed',
            ]);

            // Here we would typically emit a PatientTimelineEvent: 'diagnostic_result_published'

            return $result;
        });
    }

    /**
     * Attach a generic file to any morphable model (like a DiagnosticResult).
     */
    public function attachFile(Model $attachable, string $filePath, string $type, string $title, ?string $description = null): ClinicalAttachment
    {
        return $attachable->attachments()->create([
            'organization_id' => $attachable->organization_id, // Assumes model has organization_id
            'file_path' => $filePath,
            'file_type' => $type,
            'title' => $title,
            'description' => $description,
        ]);
    }
}
