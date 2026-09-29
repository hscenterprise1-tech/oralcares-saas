<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use Auditable, OrganizationScoped;

    protected $fillable = [
        'organization_id',
        'patient_id',
        'encounter_id',
        'invoice_number',
        'status',
        'subtotal_amount',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'amount_paid',
        'balance_due',
        'issued_at',
        'due_date',
        'notes',
        'terms',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function recalculateTotals(): void
    {
        $this->subtotal_amount = $this->items()->sum(\DB::raw('quantity * unit_price'));
        $this->tax_amount = $this->items()->sum('tax_amount');
        $this->discount_amount = $this->items()->sum('discount_amount');

        $this->total_amount = $this->subtotal_amount + $this->tax_amount - $this->discount_amount;
        $this->balance_due = $this->total_amount - $this->amount_paid;

        $this->save();
    }
}
