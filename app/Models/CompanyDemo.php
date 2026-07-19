<?php

namespace App\Models;

use App\DemoOutcome;
use Database\Factories\CompanyDemoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDemo extends Model
{
    /** @use HasFactory<CompanyDemoFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'opportunity_id',
        'scheduled_at',
        'presenter_id',
        'meeting_link',
        'outcome',
        'notes',
        'feedback',
        'follow_up_date',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'outcome' => DemoOutcome::class,
            'follow_up_date' => 'date',
        ];
    }

    /**
     * Get the opportunity this demo belongs to.
     *
     * @return BelongsTo<CompanyOpportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(CompanyOpportunity::class, 'opportunity_id');
    }

    /**
     * Get the company employee presenting the demo.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function presenter(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'presenter_id');
    }
}
