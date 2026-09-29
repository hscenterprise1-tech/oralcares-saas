<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekday of the company's working pattern.
 *
 * The table is empty until somebody configures it, and that emptiness is
 * meaningful: nothing in this system says a company works Monday to Friday, so
 * an unconfigured calendar reports "not configured" rather than assuming one.
 */
class CompanyWorkingDay extends Model
{
    protected $table = 'company_working_days';

    protected $fillable = ['day_of_week', 'is_working', 'updated_by'];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_working' => 'boolean',
        ];
    }

    /** Sunday … Saturday, matching Carbon's `dayOfWeek`. */
    public const NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function name(): string
    {
        return self::NAMES[$this->day_of_week] ?? 'Unknown';
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
