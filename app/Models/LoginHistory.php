<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sign-in.
 *
 * The table existed with the right shape and no writer, so it held nothing.
 * That is the reason "has the trial customer actually logged in?" — the single
 * most useful question about a trial — could not be answered truthfully, and
 * why `trials.last_login` was never populated by anything.
 *
 * Deliberately thin. It records that a person signed in and from roughly where;
 * it does not record what they then did, which is theirs.
 */
class LoginHistory extends Model
{
    protected $table = 'login_histories';

    protected $fillable = [
        'user_id',
        'device',
        'browser',
        'ip_address',
        'login_time',
        'logout_time',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'login_time' => 'datetime',
            'logout_time' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
