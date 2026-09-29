<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadImport extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'original_filename',
        'status',
        'total_rows',
        'imported_count',
        'duplicate_count',
        'invalid_count',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(LeadImportRow::class, 'import_id');
    }

    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Atomically claim this import for processing, or refuse.
     *
     * The database queue's visibility timeout (`retry_after`) can redeliver
     * a job that is still running — a large import legitimately taking
     * longer than the default 90 seconds — handing the same row to a second
     * worker while the first is mid-transaction. A plain `markProcessing()`
     * can't see that: both workers would happily flip `pending` to
     * `processing` and both would import the whole file. This does the
     * claim and the check in one statement, so only the worker that
     * actually wins the row proceeds.
     */
    public function markProcessingIfPending(): bool
    {
        $claimed = self::query()
            ->whereKey($this->id)
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_PROCESSING, 'updated_at' => now()]);

        if ($claimed) {
            $this->status = self::STATUS_PROCESSING;
        }

        return (bool) $claimed;
    }

    public function markCompleted(array $result): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'imported_count' => $result['imported_count'] ?? 0,
            'duplicate_count' => $result['duplicate_count'] ?? 0,
            'invalid_count' => $result['invalid_count'] ?? 0,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
