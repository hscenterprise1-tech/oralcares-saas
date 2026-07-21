<?php

use App\Models\User;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationChannel;
use App\Shared\Notification\Enums\NotificationPriority;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Notification\Models\NotificationPreference;
use App\Shared\Notification\Services\NotificationEngineService;
use App\Shared\Notification\Channels\InAppChannel;
use App\Shared\Notification\Channels\OmniMailChannel;
use App\Shared\Notification\Repositories\PreferenceRepository;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->preferenceRepository = new PreferenceRepository();
    
    // Bind actual service for tests
    $this->engine = new NotificationEngineService($this->preferenceRepository);
    $this->engine->registerChannel(new InAppChannel());
    $this->engine->registerChannel(new OmniMailChannel());
});

it('sends an in-app notification by default and saves to database', function () {
    $dto = new SendNotificationDTO(
        title: 'Test Title',
        message: 'Test Message',
        category: NotificationCategory::SYSTEM,
        priority: NotificationPriority::NORMAL
    );

    $this->engine->send($this->user, $dto);

    $this->assertDatabaseHas('ent_notifications', [
        'notifiable_id' => $this->user->id,
        'title' => 'Test Title',
        'category' => 'system',
    ]);
});

it('respects user preferences when a category is disabled', function () {
    // Disable system category
    NotificationPreference::create([
        'user_id' => $this->user->id,
        'disabled_categories' => ['system'],
    ]);

    $dto = new SendNotificationDTO(
        title: 'Test Title',
        message: 'Test Message',
        category: NotificationCategory::SYSTEM,
        priority: NotificationPriority::NORMAL
    );

    $this->engine->send($this->user, $dto);

    $this->assertDatabaseMissing('ent_notifications', [
        'notifiable_id' => $this->user->id,
        'title' => 'Test Title',
    ]);
});

it('sends email when requested and user has not disabled it', function () {
    Mail::fake();

    $dto = new SendNotificationDTO(
        title: 'Email Alert',
        message: 'This goes to email',
        category: NotificationCategory::SYSTEM,
        priority: NotificationPriority::NORMAL,
        channels: [NotificationChannel::IN_APP, NotificationChannel::EMAIL]
    );

    $this->engine->send($this->user, $dto);

    // Verify it was logged in the DB
    $this->assertDatabaseHas('ent_notifications', [
        'notifiable_id' => $this->user->id,
        'title' => 'Email Alert',
    ]);

    // Verify Email was sent
    Mail::assertSent(\App\Shared\Notification\Mail\EnterpriseNotificationMail::class);
});
