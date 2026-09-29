<?php

namespace App\Models;

use App\ClinicPasswordTokenPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time link for a clinic user to set a password. Issued and redeemed
 * only through `ClinicPasswordTokenService`; the plain token is never stored.
 */
class ClinicPasswordToken extends Model
{
    protected $fillable = [
        'user_id',
        'purpose',
        'email',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => ClinicPasswordTokenPurpose::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Not yet used and not yet expired.
     *
     * @param  Builder<ClinicPasswordToken>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }
}
