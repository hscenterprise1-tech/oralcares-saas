<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialInvoice extends Model
{
    protected $fillable = [
        'clinic_id',
        'company_subscription_id',
        'commercial_document_id',
        'company_quotation_id',
        'invoice_number',
        'status',
        'amount',
        'tax_amount',
        'discount_amount',
        'final_amount',
        'seller_gstin',
        'seller_state',
        'buyer_gstin',
        'buyer_state',
        'taxable_amount',
        'gst_rate',
        'tax_type',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'due_date',
        'issued_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'taxable_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'due_date' => 'date',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(CompanyQuotation::class, 'company_quotation_id');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'company_subscription_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CommercialPayment::class);
    }
}
