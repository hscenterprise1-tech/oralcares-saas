<?php

namespace App\Models;

use App\LeadActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    /**
     * Disable standard Eloquent timestamps since this is an append-only timeline.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lead_id',
        'user_id',
        'type',
        'description',
        'properties',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LeadActivityType::class,
            'properties' => 'array',
        ];
    }

    /**
     * Automatically populate created_at timestamp and enforce append-only rules.
     *
     * Note: Eloquent model-level deletion/update protection (via updating/deleting event hooks)
     * prevents accidental updates or deletes done through the Eloquent ORM. However, it does not
     * prevent direct raw SQL statements or database-level cascade deletes (such as cascade deletion
     * defined on the foreign key constraints in the schema when a parent Lead is permanently hard-deleted).
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (LeadActivity $activity) {
            $activity->created_at = $activity->freshTimestamp();
        });

        static::updating(function (LeadActivity $activity) {
            throw new \LogicException('Lead activities are append-only and cannot be modified.');
        });

        static::deleting(function (LeadActivity $activity) {
            throw new \LogicException('Lead activities are append-only and cannot be deleted.');
        });
    }

    /**
     * Get the lead this activity belongs to.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Get the internal operator who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
