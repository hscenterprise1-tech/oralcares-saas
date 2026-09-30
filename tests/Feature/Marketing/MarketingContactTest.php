<?php

use App\Models\MarketingContact;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
    ]);
    grantMarketingPermissions($this->user);
});

test('user can view marketing contacts list', function () {
    MarketingContact::create([
        'name' => 'Rahul Patel',
        'phone' => '+91 9876543210',
        'normalized_phone' => '9876543210',
        'organization' => 'Patel Dental Clinic',
        'city' => 'Vadodara',
    ]);

    $response = $this->actingAs($this->user)->get(route('company.marketing.contacts.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Marketing/Contacts/Index')
            ->has('contacts.data', 1)
        );
});

test('user can create a new marketing contact', function () {
    $response = $this->actingAs($this->user)->post(route('company.marketing.contacts.store'), [
        'name' => 'Amit Shah',
        'phone' => '09825012345',
        'organization' => 'Shah Dental Hospital',
        'city' => 'Ahmedabad',
        'tags' => ['Dentist', 'Prospect'],
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('marketing_contacts', [
        'name' => 'Amit Shah',
        'phone' => '09825012345',
        'normalized_phone' => '9825012345',
        'organization' => 'Shah Dental Hospital',
        'city' => 'Ahmedabad',
    ]);

    $this->assertDatabaseHas('marketing_tags', [
        'name' => 'Dentist',
        'slug' => 'dentist',
    ]);
});

test('cannot create duplicate contact with same normalized phone number', function () {
    MarketingContact::create([
        'name' => 'Existing Contact',
        'phone' => '+91 9876543210',
        'normalized_phone' => '9876543210',
    ]);

    $response = $this->actingAs($this->user)->post(route('company.marketing.contacts.store'), [
        'name' => 'Duplicate Contact',
        'phone' => '09876543210', // Same normalized phone '9876543210'
    ]);

    $response->assertSessionHasErrors([
        'phone' => 'A contact with this phone number already exists.',
    ]);

    $this->assertDatabaseCount('marketing_contacts', 1);
});

test('user can search contacts by name or phone or organization or city', function () {
    MarketingContact::create([
        'name' => 'Priya Mehta',
        'phone' => '919812345678',
        'normalized_phone' => '9812345678',
        'organization' => 'Mehta Hospital',
        'city' => 'Surat',
    ]);

    MarketingContact::create([
        'name' => 'Suresh Joshi',
        'phone' => '919898989898',
        'normalized_phone' => '9898989898',
        'organization' => 'Joshi Clinic',
        'city' => 'Rajkot',
    ]);

    $response = $this->actingAs($this->user)->get(route('company.marketing.contacts.index', [
        'search' => 'Mehta',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', 'Priya Mehta')
        );
});

test('user can update contact details', function () {
    $contact = MarketingContact::create([
        'name' => 'Original Name',
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
        'marketing_status' => 'active',
    ]);

    $response = $this->actingAs($this->user)->put(route('company.marketing.contacts.update', $contact->id), [
        'name' => 'Updated Name',
        'phone' => '9876543210',
        'marketing_status' => 'opted_out',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('marketing_contacts', [
        'id' => $contact->id,
        'name' => 'Updated Name',
        'marketing_status' => 'opted_out',
    ]);
});

test('user can soft delete a contact', function () {
    $contact = MarketingContact::create([
        'name' => 'To Delete',
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
    ]);

    $response = $this->actingAs($this->user)->delete(route('company.marketing.contacts.destroy', $contact->id));

    $response->assertRedirect();
    $this->assertSoftDeleted('marketing_contacts', ['id' => $contact->id]);
});
