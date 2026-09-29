<?php

use App\ClinicMembershipStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function setupSettingsTest()
{
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
        // Settings require `settings.manage`; the owner always holds it.
        'is_primary_owner' => true,
    ]);

    OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => 'completed',
        'status' => 'completed',
    ]);

    CompanySubscription::factory()->create(['clinic_id' => $clinic->id]);

    return [$user, $clinic];
}

beforeEach(function () {
    Storage::fake('public');
});

it('shows the current branding on the settings page', function () {
    [$user, $clinic] = setupSettingsTest();
    $clinic->update(['display_name' => 'Branded Dental']);

    $this->actingAs($user)->get(route('admin.settings.index'))->assertInertia(fn ($page) => $page
        ->component('Clinic/Admin/Settings/Index')
        ->where('branding.display_name', 'Branded Dental')
        ->where('branding.name', $clinic->name)
        ->where('branding.logo_url', null)
        ->where('invoiceBranding.legal_name', null)
        ->where('invoiceBranding.use_clinic_logo_for_invoice', true)
        ->where('invoiceBranding.invoice_logo_url', null)
        ->where('invoiceBranding.has_own_invoice_logo', false)
    );
});

it('can update the invoice legal name', function () {
    [$user, $clinic] = setupSettingsTest();

    $response = $this->actingAs($user)->put(route('admin.settings.update'), [
        'legal_name' => 'Branded Dental Pvt Ltd',
    ]);

    $response->assertRedirect();
    expect($clinic->fresh()->legal_name)->toBe('Branded Dental Pvt Ltd');
});

it('invoice logo falls back to the clinic logo while "use clinic logo for invoice" stays on', function () {
    [$user, $clinic] = setupSettingsTest();
    $logo = UploadedFile::fake()->create('logo.png', 50, 'image/png');

    $this->actingAs($user)->put(route('admin.settings.update'), ['logo' => $logo]);

    $clinic->refresh();
    expect($clinic->use_clinic_logo_for_invoice)->toBeTrue()
        ->and($clinic->invoice_logo_url)->toBe($clinic->logo_url)
        ->and($clinic->invoice_logo_path)->toBeNull();
});

it('can upload a separate invoice logo once "use clinic logo for invoice" is off', function () {
    [$user, $clinic] = setupSettingsTest();
    $invoiceLogo = UploadedFile::fake()->create('invoice-logo.png', 50, 'image/png');

    $response = $this->actingAs($user)->put(route('admin.settings.update'), [
        'use_clinic_logo_for_invoice' => false,
        'invoice_logo' => $invoiceLogo,
    ]);

    $response->assertRedirect();
    $clinic->refresh();
    expect($clinic->use_clinic_logo_for_invoice)->toBeFalse()
        ->and($clinic->invoice_logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($clinic->invoice_logo_path);
    expect($clinic->invoice_logo_url)->not->toBe($clinic->logo_url);
});

it('can update the clinic display name', function () {
    [$user, $clinic] = setupSettingsTest();

    $response = $this->actingAs($user)->put(route('admin.settings.update'), [
        'display_name' => 'New Trading Name',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($clinic->fresh()->display_name)->toBe('New Trading Name');
});

it('can upload a clinic logo and replaces the previous one', function () {
    [$user, $clinic] = setupSettingsTest();

    // `->create()` rather than `->image()` — the latter needs the GD
    // extension to render actual pixels, which this environment doesn't
    // have. A declared image mime type is all the `image` validation rule
    // and the controller actually inspect.
    $first = UploadedFile::fake()->create('logo.png', 50, 'image/png');
    $this->actingAs($user)->put(route('admin.settings.update'), ['logo' => $first]);

    $clinic->refresh();
    $firstPath = $clinic->logo_path;
    expect($firstPath)->not->toBeNull();
    Storage::disk('public')->assertExists($firstPath);

    $second = UploadedFile::fake()->create('logo2.png', 50, 'image/png');
    $this->actingAs($user)->put(route('admin.settings.update'), ['logo' => $second]);

    $clinic->refresh();
    expect($clinic->logo_path)->not->toBe($firstPath);
    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($clinic->logo_path);
});

it('rejects a non-image logo upload', function () {
    [$user, $clinic] = setupSettingsTest();

    $file = UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf');

    $response = $this->actingAs($user)->put(route('admin.settings.update'), ['logo' => $file]);

    $response->assertSessionHasErrors('logo');
    expect($clinic->fresh()->logo_path)->toBeNull();
});

it('rejects an oversized logo upload', function () {
    [$user, $clinic] = setupSettingsTest();

    $file = UploadedFile::fake()->create('logo.png', 3000, 'image/png'); // 3MB, over the 2MB cap

    $response = $this->actingAs($user)->put(route('admin.settings.update'), ['logo' => $file]);

    $response->assertSessionHasErrors('logo');
    expect($clinic->fresh()->logo_path)->toBeNull();
});

it('cannot manage another clinic\'s branding', function () {
    [$user] = setupSettingsTest();
    $otherOrg = Organization::factory()->create();
    $otherClinic = Clinic::factory()->create(['organization_id' => $otherOrg->id, 'display_name' => 'Untouched']);

    $this->actingAs($user)->put(route('admin.settings.update'), ['display_name' => 'Hijacked']);

    expect($otherClinic->fresh()->display_name)->toBe('Untouched');
});

it('shows the default patient-registration field config for a clinic that never configured one', function () {
    [$user, $clinic] = setupSettingsTest();
    expect($clinic->patient_field_config)->toBeNull();

    $this->actingAs($user)->get(route('admin.settings.index'))->assertInertia(fn ($page) => $page
        ->where('patientFieldConfig.last_name', 'required')
        ->where('patientFieldConfig.gender', 'required')
        ->where('patientFieldConfig.email', 'optional')
        ->where('patientFieldConfig.doctor_id', 'optional')
    );
});

it('can save which patient-registration fields are required, optional or hidden', function () {
    [$user, $clinic] = setupSettingsTest();

    $response = $this->actingAs($user)->put(route('admin.settings.patient-fields'), [
        'last_name' => 'optional',
        'date_of_birth' => 'required',
        'gender' => 'required',
        'blood_group' => 'hidden',
        'alternate_phone' => 'hidden',
        'email' => 'required',
        'address' => 'optional',
        'emergency_contact' => 'required',
        'allergies' => 'optional',
        'doctor_id' => 'hidden',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($clinic->fresh()->patient_field_config)->toBe([
        'last_name' => 'optional',
        'date_of_birth' => 'required',
        'gender' => 'required',
        'blood_group' => 'hidden',
        'alternate_phone' => 'hidden',
        'email' => 'required',
        'address' => 'optional',
        'emergency_contact' => 'required',
        'allergies' => 'optional',
        'doctor_id' => 'hidden',
    ]);
});

it('rejects a patient-registration field state that is not required, optional or hidden', function () {
    [$user] = setupSettingsTest();

    $response = $this->actingAs($user)->put(route('admin.settings.patient-fields'), [
        'last_name' => 'sometimes', // not one of the three allowed states
        'date_of_birth' => 'optional',
        'gender' => 'required',
        'blood_group' => 'optional',
        'alternate_phone' => 'optional',
        'email' => 'optional',
        'address' => 'optional',
        'emergency_contact' => 'optional',
        'allergies' => 'optional',
        'doctor_id' => 'optional',
    ]);

    $response->assertSessionHasErrors(['last_name']);
});

it('cannot manage another clinic\'s patient-registration fields', function () {
    [$user] = setupSettingsTest();
    $otherOrg = Organization::factory()->create();
    $otherClinic = Clinic::factory()->create(['organization_id' => $otherOrg->id]);

    $this->actingAs($user)->put(route('admin.settings.patient-fields'), [
        'last_name' => 'hidden',
        'date_of_birth' => 'optional',
        'gender' => 'hidden',
        'blood_group' => 'optional',
        'alternate_phone' => 'optional',
        'email' => 'optional',
        'address' => 'optional',
        'emergency_contact' => 'optional',
        'allergies' => 'optional',
        'doctor_id' => 'optional',
    ]);

    expect($otherClinic->fresh()->patient_field_config)->toBeNull();
});
