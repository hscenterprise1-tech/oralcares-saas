<?php

namespace App\Models;

use Database\Factories\CommercialDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class CommercialDocument extends Model
{
    /** @use HasFactory<CommercialDocumentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'lead_id',
        'type',
        'document_number',
        'version',
        'status',
        'currency',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'grand_total',
        'valid_until',
        'terms',
        'metadata',
        'commercial_snapshot',
        'created_by',
        'parent_id',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'metadata' => 'array',
        'commercial_snapshot' => 'array',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    const ALLOWED_TRANSITIONS = [
        'draft' => ['under_review', 'sent', 'archived'],
        'under_review' => ['approved', 'rejected', 'draft', 'archived'],
        'approved' => ['sent', 'draft', 'archived'],
        'sent' => ['viewed', 'accepted', 'rejected', 'expired', 'paid', 'archived'],
        'viewed' => ['accepted', 'rejected', 'expired', 'paid', 'archived'],
        'accepted' => ['paid', 'archived'],
        'rejected' => ['archived'],
        'expired' => ['archived'],
        'paid' => ['archived'],
        'archived' => [],
    ];

    protected static function booted()
    {
        static::updating(function (CommercialDocument $document) {
            if ($document->isDirty('status')) {
                $originalStatus = $document->getOriginal('status');
                $newStatus = $document->status;

                $allowedNext = self::ALLOWED_TRANSITIONS[$originalStatus] ?? [];

                if (! in_array($newStatus, $allowedNext)) {
                    throw ValidationException::withMessages([
                        'status' => "Invalid transition from {$originalStatus} to {$newStatus}.",
                    ]);
                }
            }
        });
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function items()
    {
        return $this->hasMany(CommercialDocumentItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parent()
    {
        return $this->belongsTo(CommercialDocument::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(CommercialDocument::class, 'parent_id');
    }

    /**
     * The Finance ledger invoice mirroring this document, if it is an invoice
     * that has been issued. Written by CommercialLedgerService.
     */
    public function commercialInvoice()
    {
        return $this->hasOne(CommercialInvoice::class, 'commercial_document_id');
    }
}
