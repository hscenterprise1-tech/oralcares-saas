<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoArea extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'geo_areas';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'city_id',
        'name',
        'slug',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'city_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active records.
     *
     * @param  Builder<GeoArea>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the city that owns the area.
     *
     * @return BelongsTo<GeoCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(GeoCity::class, 'city_id');
    }

    /**
     * Get the pincodes for the area.
     *
     * @return HasMany<GeoPincode, $this>
     */
    public function pincodes(): HasMany
    {
        return $this->hasMany(GeoPincode::class, 'area_id');
    }
}
