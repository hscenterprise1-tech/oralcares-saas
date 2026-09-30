<?php

use App\ContactRole;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Everybody on a deal, not just the person it was opened with.
 *
 * Secondary contacts were add-only: created and then never corrected, never
 * removed, and never made the main one. The global contacts endpoints could
 * not be used from a deal either — they authorize against the contact, never
 * stamp the `organization_id` a contact row requires, and drop the WhatsApp
 * number on every update.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    $role = Role::firstOrCreate(['name' => 'Contacts Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole($role);

    $this->org = Organization::factory()->create();
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->user->assignRole($role);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->user->id,
        'contact_name' => 'Dr Anita Rao',
        'email' => 'anita@sunrise.test',
        'phone' => '+91 90000 11111',
    ]);

    $this->primary = Contact::create([
        'lead_id' => $this->lead->id,
        'organization_id' => $this->org->id,
        'first_name' => 'Dr Anita',
        'last_name' => 'Rao',
        'email' => 'anita@sunrise.test',
        'phone' => '+91 90000 11111',
        'whatsapp' => '+91 90000 11111',
        'job_title' => 'Managing Director',
        'decision_role' => ContactRole::Owner,
        'is_primary' => true,
    ]);

    $this->actingAs($this->user);
});

it('adds somebody else to the deal, stamped with the tenant a contact row requires', function () {
    $this->post(route('company.crm.leads.contacts.store', $this->lead), [
        'first_name' => 'Rakesh',
        'last_name' => 'Menon',
        'email' => 'rakesh@sunrise.test',
        'phone' => '+91 90000 22222',
        'whatsapp' => '+91 90000 33333',
        'job_title' => 'Finance Head',
        'decision_role' => ContactRole::Finance->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $added = Contact::where('lead_id', $this->lead->id)->where('is_primary', false)->sole();

    expect($added->organization_id)->toBe($this->org->id)
        /* The field the global endpoint silently discards. */
        ->and($added->whatsapp)->toBe('+91 90000 33333')
        ->and($added->job_title)->toBe('Finance Head')
        ->and($added->decision_role)->toBe(ContactRole::Finance);

    expect($this->lead->activities()->latest('id')->first()->description)
        ->toContain('Contact added')
        ->toContain('Rakesh Menon');
});

it('corrects somebody without losing their WhatsApp number', function () {
    $contact = Contact::create([
        'lead_id' => $this->lead->id,
        'organization_id' => $this->org->id,
        'first_name' => 'Rakesh',
        'whatsapp' => '+91 90000 33333',
        'is_primary' => false,
    ]);

    $this->put(route('company.crm.leads.contacts.update', [$this->lead, $contact]), [
        'first_name' => 'Rakesh',
        'last_name' => 'Menon',
        'job_title' => 'Chief Financial Officer',
        'whatsapp' => '+91 90000 33333',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($contact->fresh())
        ->job_title->toBe('Chief Financial Officer')
        ->whatsapp->toBe('+91 90000 33333');
});

it('moves the deal onto the person promoted to main contact', function () {
    /*
     * The primary contact is also the deal's own name, email and phone — the
     * columns the list, the search and the duplicate check read. Promoting
     * somebody without moving those would leave the deal naming one person and
     * its contact card another.
     */
    $contact = Contact::create([
        'lead_id' => $this->lead->id,
        'organization_id' => $this->org->id,
        'first_name' => 'Rakesh',
        'last_name' => 'Menon',
        'email' => 'rakesh@sunrise.test',
        'phone' => '+91 90000 22222',
        'is_primary' => false,
    ]);

    $this->post(route('company.crm.leads.contacts.promote', [$this->lead, $contact]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($contact->fresh()->is_primary)->toBeTrue()
        ->and($this->primary->fresh()->is_primary)->toBeFalse()
        /* Exactly one primary, always. */
        ->and(Contact::where('lead_id', $this->lead->id)->where('is_primary', true)->count())->toBe(1);

    expect($this->lead->fresh())
        ->contact_name->toBe('Rakesh Menon')
        ->email->toBe('rakesh@sunrise.test')
        ->phone->toBe('+91 90000 22222');

    expect($this->lead->activities()->latest('id')->first()->description)
        ->toContain('Main contact changed');
});

it('refuses to remove the main contact, and removes anybody else', function () {
    $contact = Contact::create([
        'lead_id' => $this->lead->id,
        'organization_id' => $this->org->id,
        'first_name' => 'Rakesh',
        'is_primary' => false,
    ]);

    /* The deal's own name comes from the main contact; it cannot just vanish. */
    $this->delete(route('company.crm.leads.contacts.destroy', [$this->lead, $this->primary]))
        ->assertSessionHasErrors('contact');

    expect($this->primary->fresh())->not->toBeNull();

    $this->delete(route('company.crm.leads.contacts.destroy', [$this->lead, $contact]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Contact::find($contact->id))->toBeNull();
});

it('refuses a contact that belongs to another deal', function () {
    $otherLead = Lead::factory()->create(['organization_id' => $this->org->id]);
    $foreign = Contact::create([
        'lead_id' => $otherLead->id,
        'organization_id' => $this->org->id,
        'first_name' => 'Somebody',
        'is_primary' => false,
    ]);

    $this->put(route('company.crm.leads.contacts.update', [$this->lead, $foreign]), [
        'first_name' => 'Hijacked',
    ])->assertNotFound();

    expect($foreign->fresh()->first_name)->toBe('Somebody');
});
