<?php

use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The public door is shut by default until the marketing site exists.
    // These tests open it, so the path stays covered while it is switched off.
    config(['crm.public_intake_enabled' => true]);

    $this->organization = Organization::factory()->provider()->create();

    // The fallback owner. Without one a public lead would be created unowned,
    // which is the state this whole path exists to prevent.
    $this->admin = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
});

it('serves the enquiry form to a visitor who is not logged in', function () {
    get('/enquiry')->assertOk();
});

it('creates a lead from a public submission and gives it an owner', function () {
    post('/enquiry', [
        'company_name' => 'Riverside Physiotherapy',
        'contact_name' => 'Dr. Meera Shah',
        'phone' => '9812200011',
    ])->assertSessionHasNoErrors();

    $lead = Lead::where('company_name', 'Riverside Physiotherapy')->firstOrFail();

    expect($lead->source)->toBe('website')
        ->and($lead->organization_id)->toBe($this->organization->id)
        // The point of the whole path: an enquiry that arrives at 2am is
        // somebody's responsibility by 2:01.
        ->and($lead->assigned_user_id)->not->toBeNull();
});

it('omits blank optional fields rather than writing null over a column default', function () {
    // `branch_count` is NOT NULL with a default. Passing an explicit null for an
    // untouched form field overrides that default and the insert fails — which
    // is exactly what happened the first time this form was submitted.
    post('/enquiry', [
        'company_name' => 'Default Column Clinic',
        'contact_name' => 'Someone',
        'phone' => '9812200012',
        'email' => '',
        'city' => '',
    ])->assertSessionHasNoErrors();

    expect(Lead::where('company_name', 'Default Column Clinic')->exists())->toBeTrue();
});

it('requires the three things a first call cannot start without', function () {
    post('/enquiry', [])->assertSessionHasErrors(['company_name', 'contact_name', 'phone']);

    expect(Lead::count())->toBe(0);
});

it('rejects a clinic type that is not one of ours', function () {
    post('/enquiry', [
        'company_name' => 'Made Up Speciality',
        'contact_name' => 'Someone',
        'phone' => '9812200013',
        'healthcare_specialty' => 'Time Travel Clinic',
    ])->assertSessionHasErrors('healthcare_specialty');
});

it('accepts every clinic type the business actually sells to', function (string $type) {
    post('/enquiry', [
        'company_name' => "Clinic {$type}",
        'contact_name' => 'Someone',
        'phone' => '98122'.random_int(10000, 99999),
        'healthcare_specialty' => $type,
    ])->assertSessionHasNoErrors();
})->with(['Dental Clinic', 'IVF', 'Physiotherapy', 'Multi-speciality Hospital', 'Eye Hospital']);

it('swallows a honeypot submission without creating anything', function () {
    // Bots fill every field on the page. Real visitors never see this one.
    // The response is a success either way — telling a bot it was caught only
    // teaches it to try again differently.
    post('/enquiry', [
        'company_name' => 'Spam Clinic',
        'contact_name' => 'Bot',
        'phone' => '9812200014',
        'website_url' => 'http://spam.example.com',
    ])->assertSessionHasNoErrors();

    expect(Lead::count())->toBe(0);
});

it('flags a public enquiry that duplicates an existing lead', function () {
    Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Sunrise Dental',
        'phone' => '9812200015',
        'normalized_phone' => '9812200015',
    ]);

    post('/enquiry', [
        'company_name' => 'Sunrise Dental',
        'contact_name' => 'Same Number Again',
        'phone' => '9812200015',
    ])->assertSessionHasNoErrors();

    // Both rows exist — a public form must never silently discard an enquiry —
    // but the new one carries the duplicate warning on its timeline.
    $newest = Lead::where('contact_name', 'Same Number Again')->firstOrFail();

    expect($newest->activities()->where('description', 'like', '%duplicate%')->exists())->toBeTrue();
});

it('rate limits a flood of submissions from one address', function () {
    foreach (range(1, 6) as $i) {
        post('/enquiry', [
            'company_name' => "Flood {$i}",
            'contact_name' => 'Someone',
            'phone' => '98123000'.$i,
        ]);
    }

    post('/enquiry', [
        'company_name' => 'Seventh',
        'contact_name' => 'Someone',
        'phone' => '9812300099',
    ])->assertStatus(429);
});

it('answers 404 while the public door is shut', function () {
    config(['crm.public_intake_enabled' => false]);

    get('/enquiry')->assertNotFound();

    post('/enquiry', [
        'company_name' => 'Too Early Clinic',
        'contact_name' => 'Someone',
        'phone' => '9812300100',
    ])->assertNotFound();

    expect(Lead::count())->toBe(0);
});
