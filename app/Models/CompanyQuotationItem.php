<?php

namespace App\Models;

use Database\Factories\CompanyQuotationItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyQuotationItem extends Model
{
    /** @use HasFactory<CompanyQuotationItemFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_quotation_id',
        'item_type',
        'company_package_addon_id',
        'title',
        'description',
        'quantity',
        'unit_price',
        'discount',
        'total',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Get the quotation that this item belongs to.
     *
     * @return BelongsTo<CompanyQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(CompanyQuotation::class, 'company_quotation_id');
    }

    /**
     * The catalogue add-on this line was generated from, if `item_type` is
     * `addon`. Metadata only — `title`/`unit_price`/`total` on this row are
     * the frozen snapshot; this relation is never read to price anything.
     *
     * @return BelongsTo<CompanyPackageAddon, $this>
     */
    public function packageAddon(): BelongsTo
    {
        return $this->belongsTo(CompanyPackageAddon::class, 'company_package_addon_id');
    }
}
