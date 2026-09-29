<?php

use App\Models\Clinic;
use App\Models\User;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Search\Models\SearchDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('models are auto indexed into search documents when saved', function () {
    $clinic = Clinic::factory()->create(['name' => 'SmileCare Clinic']);

    expect(SearchDocument::where('model_type', Clinic::class)->where('model_id', $clinic->id)->exists())->toBeTrue();

    $doc = SearchDocument::where('model_type', Clinic::class)->where('model_id', $clinic->id)->first();
    expect($doc->title)->toContain('SmileCare Clinic');
});

test('unindexing removes model from search documents when deleted', function () {
    $clinic = Clinic::factory()->create(['name' => 'Transient Clinic']);

    expect(SearchDocument::where('model_type', Clinic::class)->where('model_id', $clinic->id)->exists())->toBeTrue();

    $clinic->delete();

    expect(SearchDocument::where('model_type', Clinic::class)->where('model_id', $clinic->id)->exists())->toBeFalse();
});

test('global search api returns indexed results for authenticated user', function () {
    /*
     * The user factory makes a clinic tenant, and the index is now scoped: a
     * tenant sees documents belonging to its own organisation's clinics and
     * nothing else. This user is put in the clinic's organisation, which is what
     * "their own records" means — the test used to pass for any user because the
     * scope read a `users.clinic_id` column that does not exist, so nothing was
     * ever filtered.
     */
    $clinic = Clinic::factory()->create([
        'name' => 'Metro Dental Clinic',
    ]);

    $user = User::factory()->create(['organization_id' => $clinic->organization_id]);

    $response = $this->actingAs($user)
        ->getJson('/api/search?q=Metro');

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->not->toBeEmpty();
});

test('global search api hides another tenant records from a clinic user', function () {
    $theirs = Clinic::factory()->create(['name' => 'Metro Dental Clinic']);
    $mine = Clinic::factory()->create(['name' => 'Riverside Dental Clinic']);

    $user = User::factory()->create(['organization_id' => $mine->organization_id]);

    $response = $this->actingAs($user)->getJson('/api/search?q=Metro');

    $response->assertStatus(200);
    expect($response->json('data'))->toBeEmpty();
});

test('notification unread count endpoint returns correct count', function () {
    $user = User::factory()->create();

    EnterpriseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Shared\Notification\Notifications\EnterpriseNotificationMessage',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'category' => 'system',
        'priority' => 'normal',
        'data' => ['title' => 'Test Alert', 'message' => 'Your invoice is ready'],
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/notifications/unread-count');

    $response->assertStatus(200)
        ->assertJson(['count' => 1]);
});

test('user can mark all notifications as read', function () {
    $user = User::factory()->create();

    EnterpriseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Shared\Notification\Notifications\EnterpriseNotificationMessage',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'category' => 'system',
        'priority' => 'normal',
        'data' => ['title' => 'System Update'],
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/notifications/mark-all-read');

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(EnterpriseNotification::where('notifiable_id', $user->id)->whereNull('read_at')->count())->toBe(0);
});
