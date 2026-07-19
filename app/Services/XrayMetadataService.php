<?php

namespace App\Services;

use App\Events\XrayCaptured;
use App\Models\ClinicEmployee;
use App\Models\XrayAttachment;
use App\Models\XrayRecord;
use App\XrayStatus;
use Illuminate\Support\Facades\DB;

class XrayMetadataService
{
    /**
     * Attach an uploaded image to an X-Ray record.
     */
    public function attachImage(
        XrayRecord $xray,
        string $fileName,
        string $storagePath,
        string $mimeType,
        int $fileSizeBytes,
        ?ClinicEmployee $uploader = null,
        ?string $notes = null
    ): XrayAttachment {
        return DB::transaction(function () use ($xray, $fileName, $storagePath, $mimeType, $fileSizeBytes, $uploader, $notes) {
            $nextVersion = $xray->attachments()->max('version') ?? 0;

            $attachment = XrayAttachment::create([
                'xray_record_id' => $xray->id,
                'file_name' => $fileName,
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'file_size_bytes' => $fileSizeBytes,
                'version' => $nextVersion + 1,
                'uploaded_by' => $uploader?->id,
                'uploaded_at' => now(),
                'notes' => $notes,
            ]);

            // Promote order status to Captured
            $xray->update([
                'status' => XrayStatus::Captured,
                'file_path' => $storagePath, // Map primary file path for legacy compatibility
            ]);

            event(new XrayCaptured($xray));

            return $attachment;
        });
    }
}
