<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoPincode extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'geo_pincodes';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pincode',
        'area_id',
        'city_id',
        'district_id',
        'state_id',
        'latitude',
        'longitude',
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
            'area_id' => 'integer',
            'city_id' => 'integer',
            'district_id' => 'integer',
            'state_id' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active records.
     *
     * @param  Builder<GeoPincode>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query for a specific 6-digit pincode.
     *
     * @param  Builder<GeoPincode>  $query
     */
    public function scopeForPincode(Builder $query, string $pincode): Builder
    {
        return $query->where('pincode', trim($pincode));
    }

    /**
     * Get the area associated with the pincode.
     *
     * @return BelongsTo<GeoArea, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(GeoArea::class, 'area_id');
    }

    /**
     * Get the city associated with the pincode.
     *
     * @return BelongsTo<GeoCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(GeoCity::class, 'city_id');
    }

    /**
     * Get the district associated with the pincode.
     *
     * @return BelongsTo<GeoDistrict, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(GeoDistrict::class, 'district_id');
    }

    /**
     * Get the state associated with the pincode.
     *
     * @return BelongsTo<GeoState, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(GeoState::class, 'state_id');
    }
}
