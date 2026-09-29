<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class MarketingTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'color',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(MarketingContact::class, 'marketing_contact_tag', 'tag_id', 'contact_id');
    }

    public function tenantOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
