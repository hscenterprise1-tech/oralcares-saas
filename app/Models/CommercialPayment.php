<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialPayment extends Model
{
    protected $fillable = [
        'clinic_id',
        'commercial_invoice_id',
        'payment_id',
        'amount',
        'payment_method',
        'payment_type',
        'transaction_reference',
        'receipt_number',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * The invoice this payment settles.
     *
     * Both names resolve to the same relation: FinanceWorkspaceService and the
     * Payments page have always eager-loaded `commercialInvoice`, which did not
     * exist, so the Finance payments query threw a RelationNotFoundException
     * before it could return a row. Kept as an alias rather than renamed so
     * existing `invoice()` callers keep working.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CommercialInvoice::class, 'commercial_invoice_id');
    }

    public function commercialInvoice(): BelongsTo
    {
        return $this->invoice();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
