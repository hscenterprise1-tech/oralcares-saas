<?php

use App\Events\NotificationFailed;
use App\Events\NotificationQueued;
use App\Events\NotificationSent;
use App\Events\ReminderTriggered;
use App\Models\Clinic;
use App\Models\Patient;
use App\Services\ChannelResolverService;
use App\Services\CommunicationPreferenceService;
use App\Services\NotificationService;
use App\Services\ReminderService;
use App\Services\TemplateService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('notification templates can render dynamic placeholders', function () {
    $clinic = Clinic::factory()->create();
    $templateService = app(TemplateService::class);

    $template = $templateService->createTemplate(
        $clinic,
        'Welcome Alert',
        'welcome',
        'Hello {{patient_name}}',
        'Welcome to {{clinic_name}}.'
    );

    $rendered = $templateService->renderTemplate($template, [
        'patient_name' => 'John Doe',
        'clinic_name' => 'OralCares Downtown',
    ]);

    expect($rendered['subject'])->toBe('Hello John Doe')
        ->and($rendered['content'])->toBe('Welcome to OralCares Downtown.');
});

test('communication preferences and quiet hours mapping checks', function () {
    $clinic = Clinic::factory()->create();
    $prefService = app(CommunicationPreferenceService::class);

    // 1. Set SMS disabled
    $prefService->setPreference($clinic, 'sms', false);
    expect($prefService->isChannelEnabled($clinic, 'sms'))->toBeFalse();

    // 2. Set quiet hours on WhatsApp (22:00 to 08:00)
    $prefService->setPreference($clinic, 'whatsapp', true, '22:00:00', '08:00:00');

    // WhatsApp disabled inside quiet hours (e.g. 23:30:00)
    expect($prefService->isChannelEnabled($clinic, 'whatsapp', '23:30:00'))->toBeFalse()
        // WhatsApp enabled outside quiet hours (e.g. 10:00:00)
        ->and($prefService->isChannelEnabled($clinic, 'whatsapp', '10:00:00'))->toBeTrue();
});

test('channel resolution fallback order check', function () {
    $clinic = Clinic::factory()->create();
    $prefService = app(CommunicationPreferenceService::class);
    $resolver = app(ChannelResolverService::class);

    // Default preference maps to first rich channel: whatsapp
    expect($resolver->resolvePreferredChannel($clinic, 'Patient', 1))->toBe('whatsapp');

    // Disable WhatsApp -> should fall back to SMS
    $prefService->setPreference($clinic, 'whatsapp', false);
    expect($resolver->resolvePreferredChannel($clinic, 'Patient', 1))->toBe('sms');

    // Disable SMS -> should fall back to Email
    $prefService->setPreference($clinic, 'sms', false);
    expect($resolver->resolvePreferredChannel($clinic, 'Patient', 1))->toBe('email');

    // Disable Email -> should fall back to In-App
    $prefService->setPreference($clinic, 'email', false);
    expect($resolver->resolvePreferredChannel($clinic, 'Patient', 1))->toBe('in_app');
});

test('notification queuing, mock send execution, and event dispatching', function () {
    Event::fake([
        NotificationQueued::class,
        NotificationSent::class,
        NotificationFailed::class,
    ]);

    $clinic = Clinic::factory()->create();
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $templateService = app(TemplateService::class);
    $notificationService = app(NotificationService::class);

    $template = $templateService->createTemplate(
        $clinic,
        'Welcome Alert',
        'welcome',
        'Hello {{patient_name}}',
        'Welcome to OralCares.'
    );

    // 1. Queue
    $notification = $notificationService->queueNotification(
        $clinic,
        null,
        'Patient',
        $patient->id,
        'email',
        'high',
        $template,
        ['patient_name' => 'John Doe']
    );

    expect($notification->status)->toBe('pending');
    Event::assertDispatched(NotificationQueued::class);

    // 2. Deliver
    $notificationService->sendNotification($notification);
    expect($notification->fresh()->status)->toBe('delivered')
        ->and($notification->fresh()->sent_at)->not->toBeNull();
    Event::assertDispatched(NotificationSent::class);
});

test('reminders scheduling triggers events', function () {
    Event::fake([
        ReminderTriggered::class,
    ]);

    $clinic = Clinic::factory()->create();
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $templateService = app(TemplateService::class);
    $reminderService = app(ReminderService::class);

    $template = $templateService->createTemplate(
        $clinic,
        'Outstanding Payment Due',
        'due_bill',
        'Pending Invoice',
        'Total amount {{bill_amount}}'
    );

    $reminder = $reminderService->scheduleReminder(
        $clinic,
        null,
        'payment_due',
        '2026-07-20',
        'Patient',
        $patient->id,
        $template,
        ['bill_amount' => '120.00']
    );

    expect($reminder->scheduled_at->toDateString())->toBe('2026-07-20')
        ->and($reminder->status)->toBe('pending');

    Event::assertDispatched(ReminderTriggered::class);
});
