<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoState extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'geo_states';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'country_id',
        'code',
        'name',
        'gst_code',
        'type',
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
            'country_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active records.
     *
     * @param  Builder<GeoState>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the country that owns the state.
     *
     * @return BelongsTo<GeoCountry, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(GeoCountry::class, 'country_id');
    }

    /**
     * Get the districts for the state.
     *
     * @return HasMany<GeoDistrict, $this>
     */
    public function districts(): HasMany
    {
        return $this->hasMany(GeoDistrict::class, 'state_id');
    }

    /**
     * Get the cities for the state.
     *
     * @return HasMany<GeoCity, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(GeoCity::class, 'state_id');
    }

    /**
     * Get the pincodes for the state.
     *
     * @return HasMany<GeoPincode, $this>
     */
    public function pincodes(): HasMany
    {
        return $this->hasMany(GeoPincode::class, 'state_id');
    }
}
