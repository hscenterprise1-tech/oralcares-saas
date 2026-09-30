<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of leave the company offers.
 *
 * Nothing is seeded. The clinic migration's comment once named "casual, sick,
 * vacation, emergency, custom" — a comment, never an enum, never validated —
 * and what leave this company offers is theirs to define, not this file's.
 *
 * `is_paid` is recorded and read by nothing. It is the seam payroll will use
 * one day; today it changes no figure anywhere.
 */
class CompanyLeaveType extends Model
{
    protected $table = 'company_leave_types';

    protected $fillable = [
        'code', 'name', 'description', 'is_paid', 'requires_approval',
        'allows_half_day', 'is_active', 'sort_order', 'colour', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'requires_approval' => 'boolean',
            'allows_half_day' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(CompanyLeaveRequest::class, 'leave_type_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(CompanyLeaveBalance::class, 'leave_type_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
