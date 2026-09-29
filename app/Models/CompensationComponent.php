<?php

namespace App\Models;

use App\CompensationCalculationType;
use App\CompensationComponentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable payroll line: what it is called, and how it is computed.
 *
 * Components exist so a payslip can say "House rent allowance 20,000" instead
 * of folding everything into one unexplained figure.
 */
class CompensationComponent extends Model
{
    protected $table = 'company_compensation_components';

    protected $fillable = [
        'code', 'name', 'type', 'calculation_type', 'is_active', 'sort_order', 'description',
    ];

    protected function casts(): array
    {
        return [
            'type' => CompensationComponentType::class,
            'calculation_type' => CompensationCalculationType::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CompensationLine::class, 'component_id');
    }
}
