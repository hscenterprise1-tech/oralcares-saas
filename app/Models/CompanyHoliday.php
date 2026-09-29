<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day the company is closed.
 *
 * Past years are kept rather than cleared — a holiday that has been and gone is
 * what makes an old attendance record readable.
 */
class CompanyHoliday extends Model
{
    protected $table = 'company_holidays';

    protected $fillable = ['holiday_date', 'name', 'description', 'is_optional', 'created_by'];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_optional' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeForYear(Builder $query, int $year): void
    {
        $query->whereYear('holiday_date', $year);
    }
}
