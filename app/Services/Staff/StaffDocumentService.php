<?php

namespace App\Services\Staff;

use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\StaffDocument;
use App\Models\StaffDocumentType;
use App\Models\User;
use App\Services\Security\Malware\ScannedUploadIntake;
use App\Services\Security\SecurityAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Uploading and reviewing staff documents.
 *
 * Files go through the same intake as patient documents (quarantine,
 * malware scan, private disk); nothing is stored without a clean verdict.
 * Every upload and review decision is written to the security audit log,
 * and no document is ever deleted: a newer upload of the same type marks
 * the previous one replaced.
 */
class StaffDocumentService
{
    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const MAX_KB = 10240;

    public function __construct(
        protected ScannedUploadIntake $intake,
        protected SecurityAudit $audit,
    ) {}

    /**
     * @param  array{title?: string|null, issued_on?: string|null, expires_on?: string|null, notes?: string|null}  $details
     */
    public function upload(Clinic $clinic, ClinicEmployee $employee, StaffDocumentType $type, UploadedFile $file, array $details, ?User $actor): StaffDocument
    {
        $admitted = $this->intake->admit($file, "clinic_{$clinic->id}/staff/{$employee->id}/documents");

        return DB::transaction(function () use ($clinic, $employee, $type, $file, $details, $actor, $admitted) {
            $superseded = StaffDocument::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)
                ->where('clinic_employee_id', $employee->id)
                ->where('staff_document_type_id', $type->id)
                ->where('status', '!=', StaffDocument::REPLACED)
                ->get();

            foreach ($superseded as $previous) {
                $previous->update(['status' => StaffDocument::REPLACED]);
            }

            $document = StaffDocument::create([
                'clinic_id' => $clinic->id,
                'clinic_employee_id' => $employee->id,
                'staff_document_type_id' => $type->id,
                'title' => trim((string) ($details['title'] ?? '')) ?: $type->name,
                'file_path' => $admitted['path'],
                'file_name' => $file->getClientOriginalName(),
                'file_size_bytes' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'file_sha256' => $admitted['sha256'],
                'scanner' => $admitted['scanner'],
                'issued_on' => $details['issued_on'] ?? null,
                'expires_on' => $details['expires_on'] ?? null,
                'status' => StaffDocument::PENDING,
                'notes' => $details['notes'] ?? null,
                'uploaded_by' => $actor?->id,
            ]);

            $this->audit->record('staff_document.uploaded', $document, [
                'employee_id' => $employee->id,
                'type' => $type->key,
                'replaced' => $superseded->pluck('id')->all(),
            ]);

            return $document;
        });
    }

    public function verify(StaffDocument $document, ?User $actor): void
    {
        $this->assertReviewable($document);

        $document->update([
            'status' => StaffDocument::VERIFIED,
            'replacement_requested' => false,
            'review_note' => null,
            'reviewed_by' => $actor?->id,
            'reviewed_at' => now(),
        ]);

        $this->audit->record('staff_document.verified', $document, ['employee_id' => $document->clinic_employee_id]);
    }

    public function reject(StaffDocument $document, string $reason, bool $requestReplacement, ?User $actor): void
    {
        $this->assertReviewable($document);

        $document->update([
            'status' => StaffDocument::REJECTED,
            'replacement_requested' => $requestReplacement,
            'review_note' => $reason,
            'reviewed_by' => $actor?->id,
            'reviewed_at' => now(),
        ]);

        $this->audit->record($requestReplacement ? 'staff_document.replacement_requested' : 'staff_document.rejected', $document, [
            'employee_id' => $document->clinic_employee_id,
            'reason' => $reason,
        ]);
    }

    private function assertReviewable(StaffDocument $document): void
    {
        if ($document->status === StaffDocument::REPLACED) {
            throw ValidationException::withMessages(['document' => 'A newer upload has replaced this document. Review that one instead.']);
        }
    }
}
