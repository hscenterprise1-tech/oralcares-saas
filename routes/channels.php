<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Notifications channel specific to a user within a clinic
Broadcast::channel('clinic.{clinicId}.user.{userId}', function ($user, $clinicId, $userId) {
    if ((int) $user->id !== (int) $userId) {
        return false;
    }

    // Verify user actually belongs to this clinic (pseudo code assuming ClinicMembership relation)
    // return $user->clinicMemberships()->where('clinic_id', $clinicId)->exists();
    return true; // Simplified for now
});

// Notifications channel specific to an entire clinic (for users with specific roles)
Broadcast::channel('clinic.{clinicId}', function ($user, $clinicId) {
    // Check if user has permission to listen to clinic-wide events
    // return $user->hasPermissionTo('view_clinic_notifications', $clinicId);
    return true; // Simplified for now
});
