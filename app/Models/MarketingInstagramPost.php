<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingInstagramPost extends Model
{
    use HasFactory;

    protected $table = 'marketing_instagram_posts';

    protected $fillable = [
        'organization_id',
        'instagram_account_id',
        'topic',
        'topic_source',
        'caption',
        'image_path',
        'image_url',
        'status',
        'scheduled_at',
        'approved_at',
        'approved_by_user_id',
        'rejection_reason',
        'published_at',
        'provider_media_id',
        'error_message',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function instagramAccount(): BelongsTo
    {
        return $this->belongsTo(MarketingInstagramAccount::class, 'instagram_account_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
