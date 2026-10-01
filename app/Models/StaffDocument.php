<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document on a staff member's file, stored on the private disk after a
 * malware scan and served only through an authorised download.
 *
 * Stored status is the review outcome: pending_verification, verified,
 * rejected, or replaced (a newer upload of the same type superseded it).
 * Expired / expiring soon is computed from `expires_on`, never stored, so it
 * is always current. Nothing is deleted: rejected and replaced documents
 * stay on file for the audit history.
 */
class StaffDocument extends Model
{
    use ClinicScoped;

    public const PENDING = 'pending_verification';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public const REPLACED = 'replaced';

    protected $fillable = [
        'clinic_id',
        'clinic_employee_id',
        'staff_document_type_id',
        'title',
        'file_path',
        'file_name',
        'file_size_bytes',
        'mime_type',
        'file_sha256',
        'scanner',
        'issued_on',
        'expires_on',
        'status',
        'notes',
        'review_note',
        'replacement_requested',
        'uploaded_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'reviewed_at' => 'datetime',
            'replacement_requested' => 'boolean',
            'file_size_bytes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'clinic_employee_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(StaffDocumentType::class, 'staff_document_type_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * expired | expiring_soon | valid | null (no expiry date).
     */
    public function expiryState(CarbonInterface $today): ?string
    {
        if (! $this->expires_on) {
            return null;
        }

        if ($this->expires_on->lt($today)) {
            return 'expired';
        }

        return $this->expires_on->lte($today->copy()->addDays((int) config('clinic_staff.expiring_soon_days', 30))) ? 'expiring_soon' : 'valid';
    }
}
