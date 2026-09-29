<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoDistrict extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'geo_districts';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'state_id',
        'name',
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
            'state_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active records.
     *
     * @param  Builder<GeoDistrict>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the state that owns the district.
     *
     * @return BelongsTo<GeoState, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(GeoState::class, 'state_id');
    }

    /**
     * Get the cities for the district.
     *
     * @return HasMany<GeoCity, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(GeoCity::class, 'district_id');
    }

    /**
     * Get the pincodes for the district.
     *
     * @return HasMany<GeoPincode, $this>
     */
    public function pincodes(): HasMany
    {
        return $this->hasMany(GeoPincode::class, 'district_id');
    }
}
