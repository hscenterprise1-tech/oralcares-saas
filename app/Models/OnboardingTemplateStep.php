<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in an onboarding sequence.
 *
 * `step_key` is what `onboarding_progress.completed_steps` records, so it is
 * deliberately separate from the label — renaming or reordering a step must not
 * lose the progress of every account already part-way through it.
 */
class OnboardingTemplateStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'onboarding_template_id',
        'step_key',
        'label',
        'description',
        'position',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'onboarding_template_id');
    }
}
