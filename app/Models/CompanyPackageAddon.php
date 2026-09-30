<?php

namespace App\Models;

use Database\Factories\CompanyPackageAddonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An optional add-on a quotation package can attach — Module A, Module B,
 * Cloud Backup, Support/AMC. A global catalogue, not scoped to one plan: the
 * business sells the same add-on the same way regardless of which base
 * package it rides on.
 */
class CompanyPackageAddon extends Model
{
    /** @use HasFactory<CompanyPackageAddonFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'price',
        'pricing_basis',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function quotationItems(): HasMany
    {
        return $this->hasMany(CompanyQuotationItem::class);
    }
}
