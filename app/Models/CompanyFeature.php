<?php

namespace App\Models;

use Database\Factories\CompanyFeatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CompanyFeature extends Model
{
    /** @use HasFactory<CompanyFeatureFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key',
        'description',
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
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the plans that link this feature.
     *
     * @return BelongsToMany<CompanyPlan, $this>
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyPlan::class,
            'company_plan_features',
            'company_feature_id',
            'company_plan_id'
        );
    }
}
