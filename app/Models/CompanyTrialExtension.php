<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyTrialExtension extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_trial_id',
        'previous_end_date',
        'new_end_date',
        'reason',
        'extended_by_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_end_date' => 'date',
            'new_end_date' => 'date',
        ];
    }

    /**
     * Get the trial this extension belongs to.
     *
     * @return BelongsTo<CompanyTrial, $this>
     */
    public function trial(): BelongsTo
    {
        return $this->belongsTo(CompanyTrial::class, 'company_trial_id');
    }

    /**
     * Get the company employee who approved the extension.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function extendedBy(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'extended_by_id');
    }
}
