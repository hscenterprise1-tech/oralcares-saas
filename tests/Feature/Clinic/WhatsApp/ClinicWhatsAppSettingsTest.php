<?php

use App\ClinicRole;
use App\Models\ClinicWhatsAppSettings;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\UserType;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\ReceptionFixture;

/**
 * Who controls what: the clinic owner chooses on/off, Common or Own and the
 * automations; OralCares staff configure and activate the numbers. No
 * credential ever reaches a clinic screen.
 */
beforeEach(function () {
    $this->f = ReceptionFixture::make('wa-settings');
    $this->f->clinic->update(['specialties' => ['dental']]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->company = function (array $permissions): User {
        // Company permissions live outside any clinic branch (team 0).
        setPermissionsTeamId(0);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user = User::factory()->create(['user_type' => UserType::CompanyUser]);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    };
});

test('the owner turns WhatsApp on, picks Common, and keeps only automations the clinic may use', function () {
    WhatsAppConnection::create(['mode' => WhatsAppConnection::SHARED, 'label' => 'Common', 'phone_number_id' => 'PN-1', 'access_token' => 'EAA-secret', 'status' => 'active']);

    ($this->asOwner)()->put('/clinic/whatsapp', ['enabled' => true, 'mode' => 'common', 'require_opt_in' => true,
        'automations' => ['appointment_confirmed', 'bill_invoice', 'physio_package_expiry']])->assertSessionHasNoErrors();

    $settings = ClinicWhatsAppSettings::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->sole();
    // A dental clinic cannot switch on a physiotherapy message.
    expect(array_keys($settings->automations))->toBe(['appointment_confirmed', 'bill_invoice']);

    ($this->asOwner)()->get('/clinic/whatsapp')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/WhatsApp/Index', false)
        ->where('status.state', 'connected')
        ->where('status.label', 'OralCares Common WhatsApp')
        ->where('automations', fn ($automations) => collect($automations)->pluck('key')->doesntContain('physio_session_reminder')));
    // The token is never in the page.
    expect(($this->asOwner)()->get('/clinic/whatsapp')->getContent())->not->toContain('EAA-secret');
});

test('choosing Own asks OralCares to activate the clinic\'s number; until then nothing is connected', function () {
    ($this->asOwner)()->post('/clinic/whatsapp/own-number', ['note' => 'Use our front desk number'])->assertSessionHasNoErrors();

    ($this->asOwner)()->get('/clinic/whatsapp')->assertInertia(fn (Assert $page) => $page
        ->where('status.state', 'pending_activation')->where('settings.mode', 'own'));

    WhatsAppConnection::create(['mode' => WhatsAppConnection::OWNED, 'clinic_id' => $this->f->clinic->id, 'label' => 'Own', 'display_phone_number' => '919812345678', 'phone_number_id' => 'PN-OWN', 'status' => 'active']);
    ($this->asOwner)()->get('/clinic/whatsapp')->assertInertia(fn (Assert $page) => $page
        ->where('status.state', 'connected')->where('status.number', '••••••••5678'));
});

test('front-desk and clinical staff cannot open or change WhatsApp settings', function (ClinicRole $role) {
    [$user, $session] = $this->f->memberWithRole($role);

    $this->actingAs($user)->withSession($session)->get('/clinic/whatsapp')->assertForbidden();
    $this->actingAs($user)->withSession($session)->put('/clinic/whatsapp', ['enabled' => true, 'mode' => 'common', 'require_opt_in' => false, 'automations' => []])->assertForbidden();
})->with([ClinicRole::Receptionist, ClinicRole::Doctor, ClinicRole::BillingStaff]);

test('OralCares sets up and activates a clinic\'s own number; clinic users and viewers cannot', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'PN-OWN'])]);
    $manager = ($this->company)(['manage-settings', 'view-integrations']);
    $viewer = ($this->company)(['view-integrations']);
    $payload = ['mode' => 'clinic_owned', 'clinic_id' => $this->f->clinic->id, 'label' => 'Clinic own', 'phone_number_id' => 'PN-OWN', 'access_token' => 'EAA-own-secret'];

    $this->actingAs($this->f->owner)->post('/company/clinic-whatsapp/connections', $payload)->assertForbidden();
    // A clinic request leaves its branch as the permission team; a company request starts outside any branch.
    setPermissionsTeamId(0);
    $this->actingAs($viewer)->post('/company/clinic-whatsapp/connections', $payload)->assertForbidden();
    $this->actingAs($viewer)->get('/company/clinic-whatsapp')->assertOk();

    $this->actingAs($manager)->post('/company/clinic-whatsapp/connections', $payload)->assertSessionHasNoErrors();
    $connection = WhatsAppConnection::query()->where('phone_number_id', 'PN-OWN')->sole();
    expect($connection->status)->toBe('pending')->and($connection->getRawOriginal('access_token'))->not->toBe('EAA-own-secret');

    $this->actingAs($manager)->post("/company/clinic-whatsapp/connections/{$connection->id}/activate")->assertSessionHasNoErrors();
    $this->actingAs($manager)->post("/company/clinic-whatsapp/connections/{$connection->id}/templates/defaults")->assertSessionHasNoErrors();
    expect($connection->fresh()->status)->toBe('active')->and($connection->templates()->count())->toBeGreaterThan(10);

    // The company page shows that a token is stored, never the token.
    $page = $this->actingAs($manager)->get('/company/clinic-whatsapp');
    $page->assertInertia(fn (Assert $p) => $p->where('connections.0.has_access_token', true));
    expect($page->getContent())->not->toContain('EAA-own-secret');

    // A second own number for the same clinic is refused.
    $this->actingAs($manager)->post('/company/clinic-whatsapp/connections', [...$payload, 'phone_number_id' => 'PN-OWN-2'])->assertSessionHasErrors('whatsapp');
});

test('activation is refused when Meta rejects the credentials', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 401)]);
    $manager = ($this->company)(['manage-settings']);
    $connection = WhatsAppConnection::create(['mode' => WhatsAppConnection::SHARED, 'label' => 'Common', 'phone_number_id' => 'PN-S', 'access_token' => 'EAA-bad', 'status' => 'pending']);

    $this->actingAs($manager)->post("/company/clinic-whatsapp/connections/{$connection->id}/activate")->assertSessionHasErrors('whatsapp');
    expect($connection->fresh()->status)->toBe('pending');
});
