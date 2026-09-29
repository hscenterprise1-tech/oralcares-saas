<?php

use App\Jobs\ProcessMarketingContactImportJob;
use App\Models\MarketingContact;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
    ]);
    grantMarketingPermissions($this->user);
});

test('user can download CSV import template', function () {
    $response = $this->actingAs($this->user)->get(route('company.marketing.contacts.import.template'));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())->toContain('name,phone,organization,state,city,area,tags');
});

test('user can upload and preview CSV import file with validation and duplicate breakdown', function () {
    // Create an existing contact in DB to test DB duplicate detection
    MarketingContact::create([
        'name' => 'Existing Contact',
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
    ]);

    $csvContent = "name,phone,organization,city,tags\n"
        ."Rahul Patel,919876543210,Patel Dental,Vadodara,Dentist\n" // Duplicate in DB
        ."Amit Shah,919825012345,Shah Hospital,Ahmedabad,Hospital\n" // New Valid
        ."Amit Shah,919825012345,Shah Hospital,Ahmedabad,Hospital\n" // File duplicate
        ."Invalid User,abc,No Org,No City,Tag\n"; // Invalid Phone

    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csvContent);

    $response = $this->actingAs($this->user)->postJson(route('company.marketing.contacts.import.preview'), [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJson([
            'total_rows' => 4,
            'valid_rows' => 1,
            'invalid_rows' => 1,
            'duplicate_rows' => 2,
            'new_contacts' => 1,
        ]);
});

test('user can process confirmed contact batch import with tags and duplicate skipping', function () {
    $rows = [
        [
            'name' => 'Rahul Patel',
            'phone' => '+91 9876543210',
            'organization' => 'Patel Dental Clinic',
            'city' => 'Vadodara',
            'tags' => 'Dentist|Prospect',
            'status' => 'VALID',
        ],
        [
            'name' => 'Amit Shah',
            'phone' => '09825012345',
            'organization' => 'Shah Hospital',
            'city' => 'Ahmedabad',
            'tags' => 'Hospital',
            'status' => 'VALID',
        ],
        [
            'name' => 'Duplicate Row',
            'phone' => '919876543210', // Same phone as Rahul Patel
            'organization' => 'Other Clinic',
            'city' => 'Surat',
            'tags' => 'Lab',
            'status' => 'FILE_DUPLICATE',
        ],
    ];

    $response = $this->actingAs($this->user)->postJson(route('company.marketing.contacts.import.process'), [
        'rows' => $rows,
        // Every import is a named batch now — the name is what the batch list
        // and each contact's detail page identify it by.
        'batch_name' => 'Test Import Batch',
    ]);

    $response->assertOk()
        ->assertJsonPath('result.imported_count', 2)
        ->assertJsonPath('result.skipped_count', 1);

    $this->assertDatabaseHas('marketing_contacts', [
        'name' => 'Rahul Patel',
        'normalized_phone' => '9876543210',
        'organization' => 'Patel Dental Clinic',
    ]);

    $this->assertDatabaseHas('marketing_tags', [
        'name' => 'Dentist',
        'slug' => 'dentist',
    ]);

    $this->assertDatabaseHas('marketing_tags', [
        'name' => 'Prospect',
        'slug' => 'prospect',
    ]);
});

test('preview flags a same-organization contact with a different phone number for review, without blocking it', function () {
    $existing = MarketingContact::create([
        'name' => 'Dr. Existing Owner',
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
        'organization' => 'Patel Dental Clinic',
    ]);

    $csvContent = "name,phone,organization,city,tags\n"
        ."Dr. New Colleague,919825012345,Patel Dental Clinic,Vadodara,Dentist\n";

    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csvContent);

    $response = $this->actingAs($this->user)->postJson(route('company.marketing.contacts.import.preview'), [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJson([
            'valid_rows' => 1,
            'new_contacts' => 1,
            'duplicate_rows' => 0,
        ])
        ->assertJsonPath('rows.0.status', 'POSSIBLE_DUPLICATE_ORG')
        ->assertJsonPath('rows.0.existing_contact.id', $existing->id)
        ->assertJsonPath('rows.0.existing_contact.name', 'Dr. Existing Owner');
});

test('preview surfaces when an exact phone duplicate was originally added', function () {
    MarketingContact::create([
        'name' => 'Existing Contact',
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
        'organization' => 'Patel Dental',
    ]);

    $csvContent = "name,phone,organization,city,tags\nRahul Patel,919876543210,Patel Dental,Vadodara,Dentist\n";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csvContent);

    $response = $this->actingAs($this->user)->postJson(route('company.marketing.contacts.import.preview'), [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('rows.0.status', 'EXISTS_IN_DB')
        ->assertJsonPath('rows.0.existing_contact.name', 'Existing Contact');

    expect($response->json('rows.0.existing_contact.added_on'))->not->toBeNull();
});

test('a large import batch is dispatched to the background queue instead of processed inline', function () {
    Queue::fake();

    $rows = collect(range(1, 501))->map(fn ($i) => [
        'name' => "Contact {$i}",
        'phone' => '9'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
        'organization' => 'Bulk Org',
        'city' => 'Surat',
        'tags' => '',
        'status' => 'VALID',
    ])->all();

    $response = $this->actingAs($this->user)->postJson(route('company.marketing.contacts.import.process'), [
        'rows' => $rows,
        // Every import is a named batch now — the name is what the batch list
        // and each contact's detail page identify it by.
        'batch_name' => 'Test Import Batch',
    ]);

    $response->assertOk()->assertJson(['queued' => true]);

    Queue::assertPushed(ProcessMarketingContactImportJob::class);

    $this->assertDatabaseHas('marketing_contact_imports', [
        'status' => 'pending',
        'total_rows' => 501,
    ]);
});

test('a small import batch is processed immediately and status is queryable', function () {
    $rows = [[
        'name' => 'Rahul Patel',
        'phone' => '919876543210',
        'organization' => 'Patel Dental Clinic',
        'city' => 'Vadodara',
        'tags' => '',
        'status' => 'VALID',
    ]];

    $response = $this->actingAs($this->user)->postJson(route('company.marketing.contacts.import.process'), [
        'rows' => $rows,
        // Every import is a named batch now — the name is what the batch list
        // and each contact's detail page identify it by.
        'batch_name' => 'Test Import Batch',
    ]);

    $response->assertOk()->assertJson(['queued' => false]);

    $importId = $response->json('import_id');

    $statusResponse = $this->actingAs($this->user)->getJson(
        route('company.marketing.contacts.import.status', $importId)
    );

    $statusResponse->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('result.imported_count', 1);
});
