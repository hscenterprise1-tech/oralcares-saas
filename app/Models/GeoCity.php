<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoCity extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'geo_cities';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'district_id',
        'state_id',
        'name',
        'slug',
        'tier',
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
            'district_id' => 'integer',
            'state_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active records.
     *
     * @param  Builder<GeoCity>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query by classification tier.
     *
     * @param  Builder<GeoCity>  $query
     */
    public function scopeTier(Builder $query, string $tier): Builder
    {
        return $query->where('tier', $tier);
    }

    /**
     * Get the district that owns the city.
     *
     * @return BelongsTo<GeoDistrict, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(GeoDistrict::class, 'district_id');
    }

    /**
     * Get the state that owns the city.
     *
     * @return BelongsTo<GeoState, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(GeoState::class, 'state_id');
    }

    /**
     * Get the areas for the city.
     *
     * @return HasMany<GeoArea, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(GeoArea::class, 'city_id');
    }

    /**
     * Get the pincodes for the city.
     *
     * @return HasMany<GeoPincode, $this>
     */
    public function pincodes(): HasMany
    {
        return $this->hasMany(GeoPincode::class, 'city_id');
    }
}
