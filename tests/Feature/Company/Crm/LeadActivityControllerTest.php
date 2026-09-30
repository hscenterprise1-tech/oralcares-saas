<?php

use App\ActivityType;
use App\Models\Lead;
use App\Models\User;
use App\UserType;

beforeEach(function () {
    $this->user = grantCrmPermissions(User::factory()->create(['user_type' => UserType::CompanyUser]));
    $this->lead = Lead::factory()->create();
});

it('can log an activity for a lead with properties', function () {
    $response = $this->actingAs($this->user)
        ->post(route('company.crm.leads.activities.store', $this->lead), [
            'type' => ActivityType::Call->value,
            'description' => 'Discussed new features.',
            'properties' => [
                'duration' => 15,
                'outcome' => 'Interested',
            ],
        ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('activities', [
        'subject_id' => $this->lead->id,
        'subject_type' => $this->lead->getMorphClass(),
        'type' => ActivityType::Call->value,
        'description' => 'Discussed new features.',
        'user_id' => $this->user->id,
    ]);

    // Check properties separately since it's JSON
    $activity = $this->lead->activities()->where('type', ActivityType::Call->value)->first();
    expect($activity->properties)->toBe([
        'duration' => 15,
        'outcome' => 'Interested',
    ]);
});
