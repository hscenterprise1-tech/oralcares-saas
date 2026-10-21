<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\ClinicMembership;
use App\Models\ClinicSpecialty;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\OnboardingProgress;
use App\Models\OnboardingSession;
use App\Models\OnboardingSessionStep;
use App\Models\SecurityAuditLog;
use App\Models\Trial;
use App\Models\User;
use App\OnboardingSessionStatus;
use App\Services\CustomerProvisioningEngine;
use App\Services\Onboarding\OnboardingEngine;
use App\Services\Provisioning\DealSpecialties;
use App\Services\Provisioning\EntitlementResolver;
use App\Services\Provisioning\ProvisioningIntent;
use App\Services\Provisioning\TrialProvisioningService;
use App\Support\Specialty\SpecialtyRegistry;
use App\TrialStatus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A trial clinic gets the same guided setup a sold clinic does.
 *
 * Trial provisioning used to leave the account a pending setup checklist and
 * no onboarding session, so the portal held the owner on a checklist page
 * that had no screen. Now provisioning starts the session the Deal-Won flow
 * starts, and nothing about the plan, specialty or trial window changes.
 */
beforeEach(function () {
    Mail::fake();

    // A trial of a deal that sold `$specialties`, on a plan selling `$modules`
    // (a generic plan sells no specialty product).
    $this->trial = function (string $company = 'Vasant Vihar Dental', array $specialties = ['dental'], array $modules = ['appointments', 'patients', 'billing', 'emr']): Trial {
        $plan = CompanyPlan::factory()->create(['key' => 'plan-'.Str::lower(Str::random(6)), 'name' => 'Growth', 'max_users' => 20, 'max_doctors' => 5, 'max_branches' => 3, 'storage_limit_gb' => 50, 'trial_duration_days' => 14]);
        $plan->features()->attach(CompanyFeature::whereIn('key', $modules)->pluck('id'));
        $lead = Lead::factory()->create(['company_name' => $company, 'contact_name' => 'Asha Menon', 'email' => 'owner.'.Str::lower(Str::random(6)).'@trial.test', 'phone' => '919812345678', 'healthcare_specialties' => $specialties]);

        return Trial::factory()->create(['lead_id' => $lead->id, 'company_plan_id' => $plan->id, 'status' => TrialStatus::Preparing, 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(14)->toDateString(), 'duration_days' => 14]);
    };
    $this->provision = fn (Trial $trial): Trial => app(TrialProvisioningService::class)->provision($trial)['trial'];
    $this->owner = function (Trial $trial): User {
        app()->instance('enforce_tenant_scope_in_tests', true);

        return User::findOrFail(ClinicMembership::withoutGlobalScopes()->where('clinic_id', $trial->clinic_id)->where('is_primary_owner', true)->value('user_id'));
    };
    $this->submit = fn (string $step, array $values, ?string $key = null) => $this->post(route('clinic.onboarding.submit', ['step' => $step]), ['idempotency_key' => $key ?? (string) Str::uuid(), 'values' => $values]);
    // Valid values for every required step a trial clinic can be given, keyed by step.
    $this->values = function (Clinic $clinic): array {
        return [
            'clinic_identity' => ['display_name' => 'Vasant Vihar Dental', 'legal_name' => 'Vasant Vihar Dental LLP', 'contact_email' => 'hello@trial.test', 'contact_phone' => '9800000002'],
            'primary_branch' => ['name' => 'Vasant Vihar', 'address_line1' => '12 Main Road', 'city' => 'New Delhi', 'state' => 'Delhi', 'pincode' => '110057', 'phone' => '9800000003'],
            'working_hours' => ['days' => collect(range(0, 6))->map(fn ($day) => ['day' => $day, 'open' => $day !== 0, 'opens' => '09:00', 'closes' => '18:00'])->all(), 'holidays' => []],
            'clinical_resources' => ['count' => 2],
            'services' => ['services' => ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinic->id)->get()
                ->map(fn ($service) => ['id' => $service->id, 'name' => $service->name, 'category' => $service->category, 'duration' => 30, 'price' => 500, 'active' => true])->all()],
            'appointments' => ['slot_minutes' => 15, 'default_duration_minutes' => 30, 'buffer_minutes' => 0, 'cancellation_notice_hours' => 4, 'no_show_after_minutes' => 20, 'online_booking_enabled' => false],
            'billing_reception' => ['invoice_prefix' => 'VVD-', 'tax_mode' => 'none', 'tax_percent' => 0, 'payment_methods' => ['cash', 'upi'], 'registration_fee' => null, 'receipt_footer' => 'Thank you'],
        ];
    };
    // Every required step of the trial clinic's own session, then the review.
    $this->completeSetup = function (Trial $trial): void {
        $clinic = Clinic::withoutGlobalScopes()->findOrFail($trial->clinic_id);
        $session = OnboardingSession::withoutGlobalScopes()->where('clinic_id', $clinic->id)->sole();
        $values = ($this->values)($clinic);
        $required = OnboardingSessionStep::withoutGlobalScopes()->where('onboarding_session_id', $session->id)->where('is_required', true)->where('step_key', '!=', 'review')->orderBy('position')->pluck('step_key');

        foreach ($required as $step) {
            expect($values)->toHaveKey($step);
            ($this->submit)($step, $values[$step])->assertSessionHasNoErrors();
        }
        ($this->submit)('review', ['confirmed' => true], 'trial-finish-1')->assertRedirect(route('clinic.onboarding.done'));
    };
});

it('starts exactly one guided setup when a trial is provisioned, and shows the owner a real page', function () {
    $trial = ($this->provision)(($this->trial)());

    $sessions = OnboardingSession::withoutGlobalScopes()->where('clinic_id', $trial->clinic_id)->get();
    expect($sessions)->toHaveCount(1)
        ->and($sessions->first()->kind)->toBe(OnboardingSession::KIND_CLINIC)
        ->and($sessions->first()->status)->toBe(OnboardingSessionStatus::Pending)
        // The engine's legacy checklist is still there; the guided setup now governs it.
        ->and(OnboardingProgress::where('organization_id', $trial->customer_organization_id)->value('status'))->toBe('pending');

    $this->actingAs(($this->owner)($trial));
    $this->get(route('clinic.onboarding.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/Onboarding/Wizard', true)
        ->where('mode', 'edit')
        ->where('step.key', 'clinic_identity'));
});

it('keeps an unfinished trial clinic on setup whichever protected page is opened', function () {
    $trial = ($this->provision)(($this->trial)());
    $this->actingAs(($this->owner)($trial));

    foreach ([route('dashboard'), route('clinic.dashboard'), route('clinic.appointments.index'), route('clinic.onboarding.done')] as $url) {
        $this->get($url)->assertRedirect(route('clinic.onboarding.index'));
    }
});

it('lets the trial owner finish setup once, without touching the plan, specialty or trial window', function () {
    $trial = ($this->provision)(($this->trial)());
    $clinic = Clinic::withoutGlobalScopes()->findOrFail($trial->clinic_id);
    $subscription = CompanySubscription::where('clinic_id', $clinic->id)->sole();
    $before = [
        'plan' => $subscription->company_plan_id,
        'window' => app(EntitlementResolver::class)->window($subscription)['state'],
        'modules' => app(EntitlementResolver::class)->forClinic($clinic)['modules'],
        'specialties' => $clinic->specialties,
        'clinic_status' => $clinic->status,
        'trial_status' => $trial->status,
    ];
    expect($before['window'])->toBe('trial');

    $this->actingAs(($this->owner)($trial));
    ($this->completeSetup)($trial);

    // A double submission of the last step changes nothing more.
    ($this->submit)('review', ['confirmed' => true], 'trial-finish-1')->assertRedirect(route('dashboard'));

    $clinic->refresh();
    $subscription->refresh();
    expect(OnboardingSession::withoutGlobalScopes()->where('clinic_id', $clinic->id)->sole()->status)->toBe(OnboardingSessionStatus::Completed)
        ->and(OnboardingProgress::where('organization_id', $clinic->organization_id)->value('status'))->toBe('completed')
        ->and(SecurityAuditLog::where('event', 'onboarding.completed')->where('clinic_id', $clinic->id)->count())->toBe(1)
        ->and(SecurityAuditLog::where('event', 'clinic.activated')->where('clinic_id', $clinic->id)->count())->toBe(1)
        ->and([
            'plan' => $subscription->company_plan_id,
            'window' => app(EntitlementResolver::class)->window($subscription)['state'],
            'modules' => app(EntitlementResolver::class)->forClinic($clinic)['modules'],
            'specialties' => $clinic->specialties,
            'clinic_status' => $clinic->status,
            'trial_status' => $trial->fresh()->status,
        ])->toEqual($before);

    $this->get(route('clinic.onboarding.done'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Onboarding/Done', true));
    $this->get(route('clinic.onboarding.index'))->assertRedirect(route('dashboard'));
    $this->followingRedirects()->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Dashboard', true));
});

it('never starts a second setup on retry, re-provisioning or conversion to paid', function () {
    $trial = ($this->provision)(($this->trial)());
    app(TrialProvisioningService::class)->provision($trial->fresh());
    app(TrialProvisioningService::class)->retry($trial->fresh());
    expect(OnboardingSession::withoutGlobalScopes()->where('clinic_id', $trial->clinic_id)->count())->toBe(1);

    // Finish setup during the trial, then win the deal: the finished setup stands.
    $this->actingAs(($this->owner)($trial));
    ($this->completeSetup)($trial);
    app(CustomerProvisioningEngine::class)->provision($trial->fresh()->lead->fresh(), 'Main Branch', ProvisioningIntent::sale());

    $sessions = OnboardingSession::withoutGlobalScopes()->where('clinic_id', $trial->clinic_id)->get();
    expect($sessions)->toHaveCount(1)
        ->and($sessions->first()->status)->toBe(OnboardingSessionStatus::Completed)
        ->and(SecurityAuditLog::where('event', 'clinic.activated')->where('clinic_id', $trial->clinic_id)->count())->toBe(1);
    $this->followingRedirects()->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Dashboard', true));
});

it('keeps one trial owner out of another trial clinic\'s setup', function () {
    $mine = ($this->provision)(($this->trial)('Mine Dental'));
    $theirs = ($this->provision)(($this->trial)('Theirs Dental'));
    $theirClinic = Clinic::withoutGlobalScopes()->findOrFail($theirs->clinic_id);

    $this->actingAs(($this->owner)($mine));
    $this->get(route('clinic.onboarding.index', ['clinic' => $theirClinic->slug]))->assertStatus(403);
    ($this->submit)('clinic_identity', ($this->values)($theirClinic)['clinic_identity'])->assertSessionHasNoErrors();

    expect(OnboardingSessionStep::withoutGlobalScopes()
        ->whereHas('session', fn ($query) => $query->withoutGlobalScopes()->where('clinic_id', $theirClinic->id))
        ->where('status', '!=', 'pending')->count())->toBe(0)
        ->and($theirClinic->fresh()->display_name)->not->toBe('Vasant Vihar Dental');
});

// ─── The specialty the deal sold ────────────────────────────

/**
 * What a provisioned trial clinic is set up as: its stored specialties (JSON
 * and the canonical rows), its primary branch, the onboarding template and
 * step titles, the suggested service codes, and what it may use at runtime.
 *
 * @return array<string, mixed>
 */
function trialSetupOf(Trial $trial): array
{
    $clinic = Clinic::withoutGlobalScopes()->findOrFail($trial->clinic_id);
    $session = OnboardingSession::withoutGlobalScopes()->where('clinic_id', $clinic->id)->sole();
    $view = app(OnboardingEngine::class)->view($session, null);
    $registry = app(SpecialtyRegistry::class);
    $practised = $registry->forClinic($clinic);

    return [
        'specialties' => $clinic->specialties,
        'rows' => ClinicSpecialty::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('status', ClinicSpecialty::ACTIVE)->pluck('specialty_key')->all(),
        'branch' => Branch::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_main', true)->value('specialties'),
        'template' => $session->template_key,
        'steps' => collect($view['steps'])->pluck('title')->all(),
        'service_codes' => ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinic->id)->pluck('code')->all(),
        'practised' => $practised,
        'extensions' => $registry->extensionsFor($practised),
        'modules' => app(EntitlementResolver::class)->forClinic($clinic)['modules'],
    ];
}

it('sets a Dental trial up as Dental: specialty, chairs, dental services and the dental chart', function () {
    $setup = trialSetupOf(($this->provision)(($this->trial)('Smile Dental', ['dental'], ['appointments', 'patients', 'billing', 'dental'])));

    expect($setup['specialties'])->toBe(['dental'])
        ->and($setup['rows'])->toBe(['dental'])
        ->and($setup['branch'])->toBe(['dental'])
        ->and($setup['template'])->toBe('dental')
        ->and($setup['steps'])->toContain('Dental chairs')
        ->and($setup['service_codes'])->toContain('DEN-CONS', 'DEN-RCT')
        ->and($setup['service_codes'])->not->toContain('PHY-SESS')
        ->and($setup['practised'])->toBe(['dental'])
        ->and($setup['extensions'])->toContain('odontogram')
        ->and($setup['modules'])->toContain('dental')->not->toContain('physiotherapy');
});

it('sets a Physiotherapy trial up as Physiotherapy, with beds and no dental chair or chart', function () {
    $setup = trialSetupOf(($this->provision)(($this->trial)('Move Physio', ['physiotherapy'], ['appointments', 'patients', 'billing', 'physiotherapy'])));

    expect($setup['specialties'])->toBe(['physiotherapy'])
        ->and($setup['rows'])->toBe(['physiotherapy'])
        ->and($setup['template'])->toBe('physiotherapy')
        ->and($setup['steps'])->toContain('Treatment beds')->not->toContain('Dental chairs')
        ->and(collect($setup['steps'])->filter(fn (string $title) => str_contains(strtolower($title), 'dental') || str_contains(strtolower($title), 'tooth'))->all())->toBe([])
        ->and($setup['service_codes'])->toContain('PHY-ASMT', 'PHY-SESS')
        ->and(collect($setup['service_codes'])->filter(fn (?string $code) => str_starts_with((string) $code, 'DEN-'))->all())->toBe([])
        ->and($setup['practised'])->toBe(['physiotherapy'])
        ->and($setup['extensions'])->not->toContain('odontogram')
        ->and($setup['modules'])->toContain('physiotherapy')->not->toContain('dental');
});

it('falls back deliberately when the deal names no specialty: the product bought, else the general setup', function () {
    // A generic plan and no specialty: nothing is guessed; the general setup, clinic left unset.
    $generic = trialSetupOf(($this->provision)(($this->trial)('Unnamed Clinic', ['other'], ['appointments', 'patients', 'billing'])));
    expect($generic['specialties'])->toBeNull()
        ->and($generic['rows'])->toBe([])
        ->and($generic['template'])->toBe('general_medicine')
        ->and($generic['steps'])->not->toContain('Dental chairs', 'Treatment beds');

    // A Physiotherapy plan and no specialty: set up for the product that was bought.
    $bought = trialSetupOf(($this->provision)(($this->trial)('Unnamed Physio', [], ['appointments', 'patients', 'billing', 'physiotherapy'])));
    expect($bought['specialties'])->toBeNull()
        ->and($bought['template'])->toBe('physiotherapy')
        ->and($bought['practised'])->toBe(['physiotherapy']);
});

it('never sets a clinic up for a specialty its plan does not sell', function () {
    // The deal says Dental; the plan sells only Physiotherapy. The record of
    // what was sold stays as it is, and setup follows what the clinic can use.
    $setup = trialSetupOf(($this->provision)(($this->trial)('Mixed Signals', ['dental'], ['appointments', 'patients', 'billing', 'physiotherapy'])));

    expect($setup['specialties'])->toBe(['dental'])
        ->and($setup['practised'])->toBe(['physiotherapy'])
        ->and($setup['template'])->toBe('physiotherapy')
        ->and($setup['steps'])->not->toContain('Dental chairs')
        ->and($setup['extensions'])->not->toContain('odontogram');
});

it('keeps the specialty and the finished setup on retry and on conversion to paid', function () {
    $trial = ($this->provision)(($this->trial)('Keep Dental', ['dental'], ['appointments', 'patients', 'billing', 'dental']));
    $this->actingAs(($this->owner)($trial));
    ($this->completeSetup)($trial);

    // Retries change nothing, even after the deal's specialty is edited.
    $trial->lead->update(['healthcare_specialties' => ['physiotherapy']]);
    app(TrialProvisioningService::class)->provision($trial->fresh());
    app(TrialProvisioningService::class)->retry($trial->fresh());
    app(CustomerProvisioningEngine::class)->provision($trial->fresh()->lead->fresh(), 'Main Branch', ProvisioningIntent::sale());

    $clinic = Clinic::withoutGlobalScopes()->findOrFail($trial->clinic_id);
    $session = OnboardingSession::withoutGlobalScopes()->where('clinic_id', $clinic->id)->sole();
    expect($clinic->specialties)->toBe(['dental'])
        ->and($session->status)->toBe(OnboardingSessionStatus::Completed)
        ->and($session->template_key)->toBe('dental')
        ->and(SecurityAuditLog::where('event', 'onboarding.completed')->where('clinic_id', $clinic->id)->count())->toBe(1)
        ->and(SecurityAuditLog::where('event', 'clinic.activated')->where('clinic_id', $clinic->id)->count())->toBe(1);
});

it('reads the deal\'s specialties the way Deal-Won always has', function () {
    $lead = Lead::factory()->make(['healthcare_specialties' => ['Dental', 'physiotherapy', 'other', ''], 'healthcare_specialty' => 'physiotherapy']);

    expect(app(DealSpecialties::class)->fromLead($lead))->toBe(['dental', 'physiotherapy']);
});
