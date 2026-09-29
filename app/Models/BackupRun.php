<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One database backup attempt, and the restore test run against it.
 * Written by `BackupManager` only.
 */
class BackupRun extends Model
{
    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const PRUNED = 'pruned';

    protected $fillable = [
        'status', 'disk', 'path', 'size_bytes', 'sha256', 'manifest', 'error',
        'started_at', 'finished_at', 'verification_status', 'verified_at', 'verification_report',
    ];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'verification_report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<BackupRun>  $query
     */
    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', self::SUCCEEDED);
    }
}
