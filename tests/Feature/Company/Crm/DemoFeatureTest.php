<?php

use App\Models\Demo;
use App\Models\Lead;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Models\Organization;

beforeEach(function () {
    $this->org = Organization::factory()->create();

    $this->companyUser = grantCrmPermissions(
        User::factory()->create([
            'user_type' => UserType::CompanyUser,
            'organization_id' => $this->org->id,
        ])
    );

    $this->clinicUser = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => $this->org->id,
    ]);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
    ]);
});

it('allows company user to create demo and logs activity', function () {
    $payload = [
        'lead_id' => $this->lead->id,
        'scheduled_at' => now()->addDays(2)->format('Y-m-d\TH:i:00'),
        'status' => 'scheduled',
        'meeting_link' => 'https://meet.google.com/abc',
        'notes' => 'Product walkthrough',
    ];

    $response = $this->actingAs($this->companyUser)
        ->post(route('company.crm.demos.store'), $payload);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertDatabaseHas('demos', [
        'lead_id' => $this->lead->id,
        'status' => 'scheduled',
        'meeting_link' => 'https://meet.google.com/abc',
    ]);

    $this->assertDatabaseHas('activities', [
        'subject_type' => 'lead',
        'subject_id' => $this->lead->id,
        'type' => 'demo',
    ]);
});

it('prevents clinic user from creating demo', function () {
    $payload = [
        'lead_id' => $this->lead->id,
        'scheduled_at' => now()->addDays(2)->format('Y-m-d\TH:i:00'),
        'status' => 'scheduled',
    ];

    $response = $this->actingAs($this->clinicUser)
        ->post(route('company.crm.demos.store'), $payload);

    $response->assertForbidden();
});

it('allows company user to complete demo', function () {
    $demo = Demo::create([
        'lead_id' => $this->lead->id,
        'scheduled_at' => now()->addDays(2),
        'status' => 'scheduled',
    ]);

    $payload = [
        'status' => 'completed',
        'feedback' => 'Customer loved it',
    ];

    $response = $this->actingAs($this->companyUser)
        ->put(route('company.crm.demos.update', $demo), $payload);

    $response->assertSessionHasNoErrors();

    $this->assertDatabaseHas('demos', [
        'id' => $demo->id,
        'status' => 'completed',
        'feedback' => 'Customer loved it',
    ]);

    $this->assertDatabaseHas('activities', [
        'subject_type' => 'lead',
        'subject_id' => $this->lead->id,
        'type' => 'demo',
        'description' => 'Demo completed.'."\nFeedback: Customer loved it",
    ]);
});
