<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class MarketingContactImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'batch_name',
        'original_filename',
        'status',
        'total_rows',
        'imported_count',
        'duplicate_count',
        'invalid_count',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * The contacts this batch *created*.
     *
     * Kept as it was — `import_id` on the contact still answers "which file
     * first added this person", and everything that already read this relation
     * keeps meaning the same thing.
     *
     * For "who did this file contain", which includes people it recognised as
     * already present, use {@see members()}.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(MarketingContact::class, 'import_id');
    }

    /**
     * Every row of the uploaded file, and what became of it.
     */
    public function rows(): HasMany
    {
        return $this->hasMany(MarketingContactImportRow::class, 'import_id');
    }

    /**
     * The unique people this file contained — created and already-existing
     * alike.
     *
     * This is the batch's membership, and what its marketing progress is
     * measured over. A contact who arrived in three files belongs to all three.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            MarketingContact::class,
            'marketing_contact_import_rows',
            'import_id',
            'contact_id'
        )
            ->whereIn('marketing_contact_import_rows.outcome', MarketingContactImportRow::CONTACT_OUTCOMES)
            ->withPivot(['outcome', 'row_number'])
            ->distinct();
    }

    /**
     * Human-readable display label for this batch.
     * Falls back to original filename if no batch name was provided.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->batch_name ?? $this->original_filename ?? "Import #{$this->id}";
    }

    /**
     * Where the people in this batch currently stand.
     *
     * One grouped query, no identifiers in PHP.
     *
     * The previous version plucked every contact id out of the database, then
     * sent them back in three `WHERE IN` clauses and looped the results. At a
     * thousand contacts that was tolerable; at ten thousand it is a ten-thousand
     * element `IN` list built three times and a result set walked row by row.
     * Nothing about the answer requires that — the database can group it.
     *
     * The definition of "current status" is unchanged and still applied in the
     * same order:
     *
     *   1. compliance wins — opted out or suppressed, whatever any campaign says
     *   2. otherwise the status of the contact's most recent recipient row
     *   3. no recipient row at all means never contacted
     *
     * Counted over the batch's *membership* — the unique people the file
     * contained, created and already-existing alike — so a contact who arrived
     * in three files is counted once here and once in each of the other two.
     * `DISTINCT` is not needed because the membership table is unique on
     * `(import_id, contact_id)`; the join cannot multiply a contact.
     *
     * @return array<string, int>
     */
    public function marketingStatusSummary(): array
    {
        $empty = [
            'total' => 0,
            'never_contacted' => 0,
            'queued' => 0,
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'replied' => 0,
            'failed' => 0,
            'opted_out' => 0,
            'suppressed' => 0,
        ];

        /*
         * The bucket expression is borrowed from MarketingContact rather than
         * restated here, so this summary and the contacts list can never come
         * to different conclusions about the same person. Aliased to `c`
         * because the population is reached through the membership table.
         */
        $rows = DB::table('marketing_contact_import_rows as r')
            ->join('marketing_contacts as c', 'c.id', '=', 'r.contact_id')
            ->where('r.import_id', $this->id)
            ->whereIn('r.outcome', MarketingContactImportRow::CONTACT_OUTCOMES)
            ->whereNull('c.deleted_at')
            ->selectRaw(MarketingContact::lifecycleBucketSql('c').' AS bucket, COUNT(*) AS people')
            ->groupBy('bucket')
            ->pluck('people', 'bucket');

        $summary = $empty;

        foreach ($rows as $bucket => $people) {
            /*
             * `skipped` is a real recipient status that has no bucket of its
             * own — a message that was never attempted because the contact was
             * off limits. Those contacts are already counted under opted_out or
             * suppressed, so anything unrecognised is added to the total and
             * nowhere else, rather than silently vanishing.
             */
            if (array_key_exists($bucket, $summary) && $bucket !== 'total') {
                $summary[$bucket] += (int) $people;
            } else {
                $summary['never_contacted'] += (int) $people;
            }

            $summary['total'] += (int) $people;
        }

        return $summary;
    }

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markCompleted(array $result): void
    {
        $this->update([
            'status' => 'completed',
            'imported_count' => $result['imported_count'] ?? 0,
            'duplicate_count' => $result['duplicate_count'] ?? 0,
            'invalid_count' => $result['invalid_count'] ?? 0,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * Scope to filter by organization.
     */
    public function scopeForOrganization(Builder $query, ?int $orgId): Builder
    {
        return $query->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
    }
}
