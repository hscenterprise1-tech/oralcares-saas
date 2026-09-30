<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch the prospect already runs.
 *
 * Not `Branch`, which belongs to a provisioned clinic. This is what a rep
 * records while qualifying: where the practice operates today, long before
 * there is anything to host.
 */
/*
 * Not soft-deleted. `syncLeadBranches()` replaces the whole set on every save,
 * so a soft-deleted branch was never readable and `deleted_at` never held a
 * value — the column is gone and the trait with it.
 */
class LeadBranch extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'name',
        'is_main',
        // Clinical capacity. All nullable — a physio branch has no beds and a
        // solo clinic has no theatres; zero would be a claim, null is not.
        'doctor_count',
        'bed_count',
        'operation_theatre_count',
        'treatment_station_count',
        'address',
        'area',
        'city',
        'state',
        'country',
        'postal_code',
        'contact_name',
        'phone',
        'email',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'position' => 'integer',
            'doctor_count' => 'integer',
            'bed_count' => 'integer',
            'operation_theatre_count' => 'integer',
            'treatment_station_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /*
         * One main branch per lead, kept true by demoting the others first.
         *
         * A database-level guarantee now backs this too (see the
         * `lead_branches_one_main_per_lead` generated-column unique index —
         * MySQL has no partial unique index, so it's built from a column
         * that's NULL for every branch except the main one), but this hook
         * stays: demoting *before* the row saves, not after, is what keeps a
         * direct `$branch->update(['is_main' => true])` from ever racing that
         * constraint — the sibling is already demoted by the time this row's
         * own write reaches the database, so it never collides with one still
         * marked main.
         */
        static::saving(function (self $branch) {
            if (! $branch->is_main) {
                return;
            }

            static::query()
                ->where('lead_id', $branch->lead_id)
                ->whereKeyNot($branch->getKey())
                ->where('is_main', true)
                ->update(['is_main' => false]);
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The branch's address as a person would write it.
     */
    public function getFullAddressAttribute(): string
    {
        return collect([$this->address, $this->area, $this->city, $this->state, $this->postal_code])
            ->filter()
            ->join(', ');
    }
}
