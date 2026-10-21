<?php

use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\Models\ClinicMembership;
use App\Models\User;
use App\UserType;
use Illuminate\Support\Facades\Broadcast;

/**
 * Whether a user may listen to a clinic's private channels: a clinic user with
 * an active membership in that clinic, while the clinic itself is active.
 * Head office never subscribes to a clinic's live events.
 */
$isActiveClinicMember = function (User $user, int|string $clinicId): bool {
    if ($user->user_type !== UserType::ClinicUser) {
        return false;
    }

    return ClinicMembership::withoutGlobalScopes()
        ->where('clinic_id', (int) $clinicId)
        ->where('user_id', $user->id)
        ->where('status', ClinicMembershipStatus::Active)
        ->whereHas('clinic', fn ($query) => $query->where('status', ClinicStatus::Active))
        ->exists();
};

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// One user's notifications inside one clinic.
Broadcast::channel('clinic.{clinicId}.user.{userId}', function (User $user, $clinicId, $userId) use ($isActiveClinicMember) {
    return (int) $user->id === (int) $userId && $isActiveClinicMember($user, $clinicId);
});

// Clinic-wide events.
Broadcast::channel('clinic.{clinicId}', function (User $user, $clinicId) use ($isActiveClinicMember) {
    return $isActiveClinicMember($user, $clinicId);
});
