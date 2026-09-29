<?php

declare(strict_types=1);

namespace App\Models;

use App\ClinicType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named sequence of steps for getting one kind of customer live.
 *
 * Resolved by `clinic_type`, falling back to the default. The five steps this
 * replaces lived inside a React component, which meant the server could not
 * name them, no test could reach them, and a single dental clinic and a
 * multi-speciality hospital were handed the same checklist.
 */
class OnboardingTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'clinic_type',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'clinic_type' => ClinicType::class,
            'is_default' => 'boolean',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(OnboardingTemplateStep::class)->orderBy('position');
    }

    /**
     * The template a customer of this kind should follow.
     *
     * Never returns null when a default exists, because an account with an
     * unrecognised type still has to be gettable live. The lookup is by the
     * enum's stored value so an organisation whose `clinic_type` was written
     * before the enum existed still matches.
     */
    public static function forClinicType(ClinicType|string|null $clinicType): ?self
    {
        $value = $clinicType instanceof ClinicType ? $clinicType->value : $clinicType;

        $matched = $value !== null
            ? static::query()->with('steps')->where('clinic_type', $value)->first()
            : null;

        return $matched ?? static::query()->with('steps')->where('is_default', true)->first();
    }
}
