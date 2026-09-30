<?php

use App\Models\User;
use App\Shared\Notification\Models\EnterpriseNotification;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('can fetch unread count', function () {
    EnterpriseNotification::factory()->count(3)->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $this->user->id,
        'read_at' => null,
    ]);

    actingAs($this->user)
        ->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJson(['count' => 3]);
});

it('can mark notification as read', function () {
    $notification = EnterpriseNotification::factory()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $this->user->id,
        'read_at' => null,
    ]);

    actingAs($this->user)
        ->patchJson("/api/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJson(['success' => true]);

    $this->assertNotNull($notification->fresh()->read_at);
});

it('prevents marking other users notifications as read', function () {
    $otherUser = User::factory()->create();
    $notification = EnterpriseNotification::factory()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $otherUser->id,
        'read_at' => null,
    ]);

    actingAs($this->user)
        ->patchJson("/api/notifications/{$notification->id}/read")
        ->assertForbidden();
});
