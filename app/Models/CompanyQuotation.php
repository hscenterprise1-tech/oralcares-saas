<?php

namespace App\Models;

use App\QuotationStatus;
use Database\Factories\CompanyQuotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'quotation_number',
        'version',
        'parent_id',
        'lead_id',
        'clinic_id',
        'prepared_by_id',
        'assigned_id',
        'status',
        'valid_until',
        'subtotal',
        'discount',
        'tax',
        'grand_total',
        'notes',
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
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
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
