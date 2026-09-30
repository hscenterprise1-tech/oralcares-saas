<?php

namespace App\Models;

use App\BillingCycle;
use App\QuotationStatus;
use Database\Factories\CompanyQuotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CompanyQuotation extends Model
{
    /** @use HasFactory<CompanyQuotationFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'quotation_number',
        'version',
        'parent_id',
        'lead_id',
        'company_plan_id',
        'billing_cycle',
        'clinic_id',
        'prepared_by_id',
        'assigned_id',
        'status',
        'valid_until',
        'subtotal',
        'discount',
        'discount_type',
        'discount_value',
        'discount_is_manual',
        'discount_requested_by_id',
        'tax',
        'taxable_amount',
        'gst_rate',
        'tax_type',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'grand_total',
        'notes',
        'terms',
        'payment_terms',
        'implementation_terms',
        'support_terms',
        'cancellation_terms',
        'sent_at',
        'approved_at',
        'rejected_at',
        'expired_at',
        'rejection_reason',
        'proforma_number',
        'proforma_issued_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'billing_cycle' => BillingCycle::class,
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expired_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_is_manual' => 'boolean',
            'tax' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'proforma_issued_at' => 'datetime',
        ];
    }

    /**
     * Whether the GST breakdown could be computed for this quotation.
     *
     * False for every quotation raised before Phase 2 — `gst_rate` is null on
     * all of them — and for any quotation raised since where the seller or
     * customer state was unknown at the time. Both cases render the same
     * way: a single flat "Tax" line rather than a CGST/SGST/IGST breakdown.
     */
    public function hasGstBreakdown(): bool
    {
        return $this->gst_rate !== null && $this->tax_type !== null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * Whoever most recently set this quotation's discount.
     *
     * The one fact `QuotationApprovalService`'s tiered discount rule needs —
     * an admin and an employee have different direct-discount ceilings, and
     * this is who to check it against. Null on any quotation whose discount
     * was never set through `LeadWorkspaceService::setQuotationDiscount()`
     * (every quotation before Phase 2B, and any test fixture that writes
     * `discount` directly), in which case the approval service falls back to
     * its original global-threshold behaviour unchanged.
     */
    public function discountRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_requested_by_id');
    }

    /**
     * Get the parent quotation this version is revised from.
     *
     * @return BelongsTo<CompanyQuotation, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CompanyQuotation::class, 'parent_id');
    }

    /**
     * Get all revisions/versions linked to this base quotation.
     *
     * @return HasMany<CompanyQuotation, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CompanyQuotation::class, 'parent_id');
    }

    /**
     * Get the lead associated with the quotation.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Get the package/plan this quotation is priced against, if any.
     *
     * @return BelongsTo<CompanyPlan, $this>
     */
    public function companyPlan(): BelongsTo
    {
        return $this->belongsTo(CompanyPlan::class);
    }

    /**
     * Get the clinic associated with the quotation.
     *
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    /**
     * Get the employee who prepared this quotation.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'prepared_by_id');
    }

    /**
     * Get the sales employee assigned to the quotation.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'assigned_id');
    }

    /**
     * Get the line items of the quotation.
     *
     * @return HasMany<CompanyQuotationItem, $this>
     */
    /**
     * Money settled against this quotation.
     *
     * Each quotation is independent — a customer may hold several, and a
     * payment belongs to exactly one of them. Keying here rather than on the
     * deal is what keeps money from bleeding between two proposals to the same
     * customer.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'company_quotation_id');
    }

    /** The invoice raised once this quotation was settled in full. */
    public function invoice(): HasOne
    {
        return $this->hasOne(CommercialInvoice::class, 'company_quotation_id');
    }

    /**
     * Whether this quotation's commercial terms are fixed.
     *
     * An invoice exists only once the quotation has been settled in full, and
     * an invoice is a document the customer has. Repricing behind it would mean
     * the paper they hold and the record we hold no longer agree — so the terms
     * lock at that point and stay locked.
     *
     * The way to charge something different is a new quotation, which is what
     * revising already does: a fresh record with its own number, items, totals
     * and approval, leaving this one and its invoice exactly as they are.
     */
    public function isFinanciallyLocked(): bool
    {
        return $this->invoice()->exists();
    }

    /**
     * Whether this version is the one still being worked, as opposed to one a
     * revision, a rejection, an expiry, or a win has retired. A deal has at
     * most one active quotation at any time.
     */
    public function isActive(): bool
    {
        $status = $this->status instanceof QuotationStatus ? $this->status : QuotationStatus::from((string) $this->status);

        return $status->isActive();
    }

    /**
     * `grand_total` annualised, so a quarterly quote and a monthly quote can
     * be compared on the same axis. Derived, never stored — storing it would
     * let it drift from `billing_cycle` the moment either changed independently.
     */
    public function normalizedArr(): float
    {
        $cycle = $this->billing_cycle instanceof BillingCycle ? $this->billing_cycle : BillingCycle::from((string) ($this->billing_cycle ?: 'monthly'));

        return round((float) $this->grand_total * $cycle->annualFactor(), 2);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CompanyQuotationItem::class, 'company_quotation_id');
    }

    /**
     * Get the audit activities logged for this quotation.
     *
     * @return HasMany<CompanyQuotationActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(CompanyQuotationActivity::class, 'company_quotation_id');
    }
}
