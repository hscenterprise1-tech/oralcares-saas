<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * A record that one step submission, identified by its idempotency key, has
 * already been applied. A repeat of the same key is answered without
 * applying the step again.
 */
class OnboardingStepSubmission extends Model
{
    use ClinicScoped;

    public const UPDATED_AT = null;

    protected $fillable = [
        'onboarding_session_step_id',
        'clinic_id',
        'idempotency_key',
        'payload_hash',
        'user_id',
    ];
}
