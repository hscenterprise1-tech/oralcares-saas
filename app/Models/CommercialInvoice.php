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
        'invoice_number',
        'status',
        'amount',
        'tax_amount',
        'discount_amount',
        'final_amount',
        'due_date',
        'issued_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
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
