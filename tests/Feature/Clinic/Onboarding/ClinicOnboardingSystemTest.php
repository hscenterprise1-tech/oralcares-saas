<?php

use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\Jobs\ExpireOwnerInvitationsJob;
use App\Jobs\ProvisionClinicFromDeal;
use App\LeadStatus;
use App\Mail\OwnerInvitationMail;
use App\Mail\StaffInvitationMail;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\ClinicOperatingHour;
use App\Models\ClinicOwnerInvitation;
use App\Models\ClinicProvisioningRun;
use App\Models\ClinicRoom;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\LeadBranch;
use App\Models\OnboardingProgress;
use App\Models\OnboardingSession;
use App\Models\OnboardingSessionStep;
use App\Models\OnboardingStepSubmission;
use App\Models\Organization;
use App\Models\Practitioner;
use App\Models\PractitionerQualification;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\OnboardingSessionStatus;
use App\OwnerInvitationStatus;
use App\ProvisioningRunStatus;
use App\Services\LeadWorkspaceService;
use App\Services\Onboarding\OnboardingEngine;
use App\Services\Onboarding\OwnerInvitationService;
use App\Services\Provisioning\ClinicProvisioning;
use App\Services\Security\SecurityAudit;
use App\UserType;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Deal Won → clinic provisioned → primary branch → owner invitation →
 * acceptance → resumable onboarding → staff invitations → completion.
 */
beforeEach(function () {
    Mail::fake();

    $vendor = Organization::factory()->create();
    $this->rep = grantCrmPermissions(User::factory()->companyUser()->create(['organization_id' => $vendor->id]), ['view-crm', 'view-leads']);
});

/**
 * A deal marked Won by a rep.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<array<string, mixed>>  $branches
 */
function wonDeal(array $attributes = [], array $branches = []): Lead
{
    $lead = Lead::factory()->create($attributes + [
        'organization_id' => null,
        'company_name' => 'Smile Studio '.Str::random(4),
        'contact_name' => 'Dr. Meera Iyer',
        'email' => 'meera.'.Str::lower(Str::random(6)).'@clinic.test',
        'phone' => '+919800000001',
        'healthcare_specialties' => ['dental'],
    ]);

    foreach ($branches as $position => $branch) {
        LeadBranch::create($branch + ['lead_id' => $lead->id, 'position' => $position]);
    }

    app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Won->value, test()->rep);

    return $lead->fresh();
}

function runFor(Lead $lead): ClinicProvisioningRun
{
    return ClinicProvisioningRun::where('lead_id', $lead->id)->sole();
}

function clinicFor(Lead $lead): Clinic
{
    return Clinic::withoutGlobalScopes()->findOrFail(runFor($lead)->clinic_id);
}

function ownerFor(Lead $lead): User
{
    return User::where('email', $lead->email)->sole();
}

/**
 * The accept link from the latest owner invitation email (to one owner).
 */
function ownerInviteUrl(?string $email = null): string
{
    $url = null;
    Mail::assertQueued(OwnerInvitationMail::class, function (OwnerInvitationMail $mail) use (&$url, $email) {
        if ($email === null || $mail->hasTo($email)) {
            $url = $mail->acceptUrl;
        }

        return true;
    });

    return $url;
}

/**
 * Accept the owner invitation with a new password and confirm the account.
 */
function acceptAsOwner(Lead $lead): User
{
    // From here on requests run under real-request tenant rules.
    app()->instance('enforce_tenant_scope_in_tests', true);

    test()->post(ownerInviteUrl($lead->email), ['password' => 'Str0ng-pass-123', 'password_confirmation' => 'Str0ng-pass-123'])
        ->assertRedirect(route('clinic.onboarding.account'));

    test()->post(route('clinic.onboarding.account.store'), ['name' => 'Dr. Meera Iyer', 'phone' => '9800000001'])
        ->assertRedirect(route('clinic.onboarding.index'));

    return ownerFor($lead);
}

function submitStep(string $step, array $values, ?string $key = null)
{
    return test()->post(route('clinic.onboarding.submit', ['step' => $step]), ['idempotency_key' => $key ?? (string) Str::uuid(), 'values' => $values]);
}

/**
 * Valid values for every required clinic step of the dental template.
 *
 * @return array<string, array<string, mixed>>
 */
function requiredStepValues(Clinic $clinic): array
{
    $services = ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinic->id)->get()
        ->map(fn ($service) => ['id' => $service->id, 'name' => $service->name, 'category' => $service->category, 'duration' => 30, 'price' => 500, 'active' => true])->all();

    return [
        'clinic_identity' => ['display_name' => 'Smile Studio', 'legal_name' => 'Smile Studio LLP', 'contact_email' => 'hello@smile.test', 'contact_phone' => '9800000002'],
        'primary_branch' => ['name' => 'Indiranagar', 'address_line1' => '12 100 Feet Road', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'pincode' => '560038', 'phone' => '9800000003'],
        'working_hours' => ['days' => collect(range(0, 6))->map(fn ($day) => ['day' => $day, 'open' => $day !== 0, 'opens' => '09:00', 'closes' => '18:00'])->all(), 'holidays' => ['2026-12-25']],
        'clinical_resources' => ['count' => 3],
        'services' => ['services' => $services],
        'appointments' => ['slot_minutes' => 15, 'default_duration_minutes' => 30, 'buffer_minutes' => 5, 'cancellation_notice_hours' => 4, 'no_show_after_minutes' => 20, 'online_booking_enabled' => false],
        'billing_reception' => ['invoice_prefix' => 'SS-', 'tax_mode' => 'none', 'tax_percent' => 0, 'payment_methods' => ['cash', 'upi'], 'registration_fee' => 100, 'receipt_footer' => 'Thank you'],
    ];
}

// ─── Provisioning ───────────────────────────────────────────

it('provisions the clinic, primary branch, owner, onboarding and invitation when a deal is won', function () {
    $lead = wonDeal();
    $run = runFor($lead);
    $clinic = clinicFor($lead);
    $owner = ownerFor($lead);

    expect($run->status)->toBe(ProvisioningRunStatus::Succeeded)
        ->and($run->completed_stages)->toBe(ClinicProvisioningRun::STAGES)
        ->and($clinic->status)->toBe(ClinicStatus::Active)
        ->and($clinic->specialties)->toBe(['dental'])
        ->and(Branch::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_main', true)->count())->toBe(1)
        ->and(ClinicMembership::where('clinic_id', $clinic->id)->where('user_id', $owner->id)->value('is_primary_owner'))->toBeTrue();

    $session = OnboardingSession::withoutGlobalScopes()->where('clinic_id', $clinic->id)->sole();
    expect($session->template_key)->toBe('dental')
        ->and($session->status)->toBe(OnboardingSessionStatus::Pending)
        ->and(OnboardingSessionStep::withoutGlobalScopes()->where('onboarding_session_id', $session->id)->orderBy('position')->pluck('step_key')->all())
        ->toBe(['clinic_identity', 'primary_branch', 'working_hours', 'clinical_resources', 'services', 'appointments', 'billing_reception', 'communication', 'staff_invitations', 'review']);

    $invitation = ClinicOwnerInvitation::withoutGlobalScopes()->where('clinic_id', $clinic->id)->sole();
    expect($invitation->status)->toBe(OwnerInvitationStatus::Sent)
        ->and($invitation->send_count)->toBe(1)
        ->and(strlen($invitation->token_hash))->toBe(64);
    Mail::assertQueued(OwnerInvitationMail::class, fn (OwnerInvitationMail $mail) => $mail->hasTo($lead->email));

    // Standard services are suggested, switched off until the owner confirms them.
    expect(ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('code', 'DEN-RCT')->value('is_active'))->toBeFalse();
});

it('runs provisioning on the queue instead of inside the Won request', function () {
    Queue::fake();

    $lead = wonDeal();

    expect(runFor($lead)->status)->toBe(ProvisioningRunStatus::Queued)
        ->and(Clinic::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertPushedOn('provisioning', ProvisionClinicFromDeal::class);
});

it('never provisions the same deal twice', function () {
    $lead = wonDeal();

    app(ClinicProvisioning::class)->requestForWonDeal($lead);
    app(ClinicProvisioning::class)->requestForWonDeal($lead);
    app(LeadWorkspaceService::class)->transitionStage($lead->fresh(), LeadStatus::Won->value, $this->rep);

    expect(ClinicProvisioningRun::where('lead_id', $lead->id)->count())->toBe(1)
        ->and(Clinic::withoutGlobalScopes()->where('organization_id', $lead->customer_organization_id)->count())->toBe(1)
        ->and(ClinicOwnerInvitation::withoutGlobalScopes()->count())->toBe(1)
        ->and(OnboardingSession::withoutGlobalScopes()->count())->toBe(1);
    Mail::assertQueuedCount(1);
});

it('creates exactly one primary branch when CRM has no branch details', function () {
    $clinic = clinicFor(wonDeal());

    $branches = Branch::withoutGlobalScopes()->where('clinic_id', $clinic->id)->get();
    expect($branches)->toHaveCount(1)
        ->and($branches->first()->is_main)->toBeTrue()
        ->and($branches->first()->name)->toBe('Main Branch');
});

it('uses the branch CRM recorded as the primary branch', function () {
    $lead = wonDeal([], [
        ['name' => 'Koramangala', 'is_main' => true, 'address' => '80 Feet Road', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'postal_code' => '560034', 'phone' => '9800000009'],
    ]);
    $clinic = clinicFor($lead);

    $branch = Branch::withoutGlobalScopes()->where('clinic_id', $clinic->id)->sole();
    expect($branch->name)->toBe('Koramangala')
        ->and($branch->is_main)->toBeTrue()
        ->and($branch->city)->toBe('Bengaluru')
        ->and($branch->pincode)->toBe('560034')
        ->and($branch->specialties)->toBe(['dental']);
});

it('records a failed stage, then resumes from it on retry without duplicating the tenant', function () {
    app()->bind(OwnerInvitationService::class, fn ($app) => new class($app->make(SecurityAudit::class)) extends OwnerInvitationService
    {
        public static int $calls = 0;

        public function send(ClinicOwnerInvitation $invitation, ?User $actor = null): string
        {
            if (self::$calls++ === 0) {
                throw new RuntimeException('Mail relay unavailable');
            }

            return parent::send($invitation, $actor);
        }
    });

    $lead = Lead::factory()->create(['organization_id' => null, 'email' => 'retry@clinic.test', 'contact_name' => 'Dr. Retry', 'healthcare_specialties' => ['physiotherapy']]);

    expect(fn () => app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Won->value, $this->rep))
        ->toThrow(RuntimeException::class, 'Mail relay unavailable');

    $run = runFor($lead);
    expect($run->status)->toBe(ProvisioningRunStatus::Failed)
        ->and($run->current_stage)->toBe('owner_invitation')
        ->and($run->completed_stages)->toBe(['tenant', 'primary_branch', 'specialty', 'onboarding_session'])
        ->and($run->last_error)->toContain('Mail relay unavailable');

    app(ClinicProvisioning::class)->retry($run);

    $run->refresh();
    expect($run->status)->toBe(ProvisioningRunStatus::Succeeded)
        ->and($run->attempts)->toBe(2)
        ->and(Clinic::withoutGlobalScopes()->where('organization_id', $lead->fresh()->customer_organization_id)->count())->toBe(1)
        ->and(OnboardingSession::withoutGlobalScopes()->where('clinic_id', $run->clinic_id)->count())->toBe(1)
        ->and(OnboardingSession::withoutGlobalScopes()->where('clinic_id', $run->clinic_id)->value('template_key'))->toBe('physiotherapy');
    Mail::assertQueuedCount(1);

    expect(SecurityAuditLog::where('event', 'provisioning.stage_failed')->exists())->toBeTrue()
        ->and(SecurityAuditLog::where('event', 'provisioning.succeeded')->exists())->toBeTrue();
});

it('only updates the tenant when a deal for an existing customer is won', function () {
    $first = wonDeal();
    $renewal = Lead::factory()->create(['organization_id' => null, 'customer_organization_id' => $first->customer_organization_id, 'email' => null, 'healthcare_specialties' => []]);

    app(LeadWorkspaceService::class)->transitionStage($renewal, LeadStatus::Won->value, $this->rep);

    $run = runFor($renewal);
    expect($run->source)->toBe(ClinicProvisioningRun::SOURCE_EXISTING_CUSTOMER)
        ->and($run->status)->toBe(ProvisioningRunStatus::Succeeded)
        ->and($run->completed_stages)->toBe(['tenant'])
        ->and($run->clinic_id)->toBe(clinicFor($first)->id)
        ->and(clinicFor($first)->specialties)->toBe(['dental'])
        ->and(ClinicOwnerInvitation::withoutGlobalScopes()->count())->toBe(1)
        ->and(OnboardingSession::withoutGlobalScopes()->count())->toBe(1);
    Mail::assertQueuedCount(1);
});

it('fails visibly when the deal has no owner email', function () {
    $lead = Lead::factory()->create(['organization_id' => null, 'email' => null]);

    expect(fn () => app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Won->value, $this->rep))->toThrow(DomainException::class);

    expect(runFor($lead)->status)->toBe(ProvisioningRunStatus::Failed)
        ->and(runFor($lead)->current_stage)->toBe('tenant')
        ->and(runFor($lead)->last_error)->toContain('no owner email');
});

// ─── Owner invitation ───────────────────────────────────────

it('lets the owner accept with a new password, then confirm their account', function () {
    $lead = wonDeal();
    $url = ownerInviteUrl();

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Clinic/Auth/AcceptInvitation', true)->where('status', 'valid')->where('roleName', 'Clinic owner'));
    expect(ClinicOwnerInvitation::withoutGlobalScopes()->sole()->status)->toBe(OwnerInvitationStatus::Opened);

    acceptAsOwner($lead);

    $invitation = ClinicOwnerInvitation::withoutGlobalScopes()->sole();
    expect($invitation->status)->toBe(OwnerInvitationStatus::ProfileCompleted)
        ->and($invitation->accepted_at)->not->toBeNull()
        ->and(auth()->id())->toBe(ownerFor($lead)->id)
        ->and(ownerFor($lead)->phone)->toBe('9800000001');

    // The link is spent.
    auth()->logout();
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('status', 'already_member'));
});

it('asks an owner who already has a login to confirm their own password', function () {
    $existing = User::factory()->create(['email' => 'existing.owner@clinic.test', 'password' => 'Existing-pass-1', 'email_verified_at' => now(), 'user_type' => UserType::ClinicUser]);
    $lead = wonDeal(['email' => $existing->email]);

    $this->get(ownerInviteUrl())->assertInertia(fn (Assert $page) => $page->where('status', 'existing'));
    $this->post(ownerInviteUrl(), ['password' => 'wrong-password'])->assertSessionHasErrors('password');
    $this->post(ownerInviteUrl(), ['password' => 'Existing-pass-1'])->assertRedirect(route('clinic.onboarding.account'));

    expect(Hash::check('Existing-pass-1', $existing->fresh()->password))->toBeTrue()
        ->and(User::where('email', $lead->email)->count())->toBe(1);
});

it('expires the invitation and lets it be resent with a new link', function () {
    wonDeal();
    $oldUrl = ownerInviteUrl();
    $invitation = ClinicOwnerInvitation::withoutGlobalScopes()->sole();

    $this->travel(8)->days();
    $this->get($oldUrl)->assertInertia(fn (Assert $page) => $page->where('status', 'invalid'));
    (new ExpireOwnerInvitationsJob)->handle(app(OwnerInvitationService::class));
    expect($invitation->fresh()->status)->toBe(OwnerInvitationStatus::Expired);

    $token = app(OwnerInvitationService::class)->send($invitation->fresh());
    $newUrl = app(OwnerInvitationService::class)->url($token);

    expect($invitation->fresh()->status)->toBe(OwnerInvitationStatus::Sent)
        ->and($invitation->fresh()->send_count)->toBe(2);
    $this->get($newUrl)->assertInertia(fn (Assert $page) => $page->where('status', 'valid'));
    expect(SecurityAuditLog::where('event', 'owner_invitation.resent')->exists())->toBeTrue();
});

it('refuses a tampered, revoked or foreign invitation link', function () {
    wonDeal();
    $url = ownerInviteUrl();

    $this->get($url.'x')->assertInertia(fn (Assert $page) => $page->where('status', 'invalid'));

    app(OwnerInvitationService::class)->revoke(ClinicOwnerInvitation::withoutGlobalScopes()->sole());
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('status', 'invalid'));
});

// ─── Onboarding ─────────────────────────────────────────────

it('shows the owner the wizard with plan and specialty locked, and keeps them there until done', function () {
    $lead = wonDeal();
    acceptAsOwner($lead);

    $this->get(route('dashboard'))->assertRedirect(route('clinic.onboarding.index'));
    $this->get(route('clinic.onboarding.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/Onboarding/Wizard', false)
        ->where('mode', 'edit')
        ->where('session.template', 'dental')
        ->where('step.key', 'clinic_identity')
        ->where('step.meta.locked.1.value', 'Dental'));
});

it('auto-saves drafts without touching the clinic, and resumes where the owner left off', function () {
    $lead = wonDeal();
    acceptAsOwner($lead);
    $clinic = clinicFor($lead);

    $this->putJson(route('clinic.onboarding.draft', ['step' => 'clinic_identity']), ['values' => ['display_name' => 'Smile Draft', 'unknown_key' => 'dropped']])
        ->assertOk()->assertJsonStructure(['saved_at']);

    $step = OnboardingSessionStep::withoutGlobalScopes()->where('step_key', 'clinic_identity')->sole();
    expect($step->draft)->toBe(['display_name' => 'Smile Draft'])
        ->and($clinic->fresh()->display_name)->not->toBe('Smile Draft');

    $this->post(route('clinic.onboarding.pause'))->assertRedirect();
    expect(OnboardingSession::withoutGlobalScopes()->sole()->status)->toBe(OnboardingSessionStatus::Paused);
    $this->get(route('clinic.onboarding.index'))->assertInertia(fn (Assert $page) => $page->where('mode', 'paused')->where('session.status', 'paused'));

    $this->post(route('clinic.onboarding.resume'))->assertRedirect(route('clinic.onboarding.index'));
    $this->get(route('clinic.onboarding.index'))->assertInertia(fn (Assert $page) => $page
        ->where('mode', 'edit')
        ->where('session.status', 'in_progress')
        ->where('step.values.display_name', 'Smile Draft')
        ->where('step.has_draft', true));
});

it('applies a step once per idempotency key and refuses a reused key with different data', function () {
    $lead = wonDeal();
    acceptAsOwner($lead);
    $values = requiredStepValues(clinicFor($lead))['clinic_identity'];

    submitStep('clinic_identity', $values, 'key-identity-1')->assertRedirect(route('clinic.onboarding.index', ['step' => 'primary_branch']));
    submitStep('clinic_identity', $values, 'key-identity-1')->assertSessionHas('success', 'Already saved.');

    $step = OnboardingSessionStep::withoutGlobalScopes()->where('step_key', 'clinic_identity')->sole();
    expect($step->submission_count)->toBe(1)
        ->and(OnboardingStepSubmission::withoutGlobalScopes()->count())->toBe(1)
        ->and(clinicFor($lead)->display_name)->toBe('Smile Studio');

    submitStep('clinic_identity', [...$values, 'display_name' => 'Other'], 'key-identity-1')->assertSessionHasErrors('step');
    expect(clinicFor($lead)->display_name)->toBe('Smile Studio');
});

it('never lets onboarding change the plan or the specialty', function () {
    $plan = CompanyPlan::factory()->create();
    $lead = wonDeal();
    acceptAsOwner($lead);
    $clinic = clinicFor($lead);
    $planBefore = CompanySubscription::where('clinic_id', $clinic->id)->value('company_plan_id');

    submitStep('clinic_identity', [...requiredStepValues($clinic)['clinic_identity'], 'company_plan_id' => $plan->id, 'plan' => 'enterprise', 'specialties' => ['physiotherapy']])->assertSessionHasNoErrors();

    expect(CompanySubscription::where('clinic_id', $clinic->id)->value('company_plan_id'))->toBe($planBefore)
        ->and($clinic->fresh()->specialties)->toBe(['dental']);
});

it('writes the primary branch on the server and ignores a branch id from the browser', function () {
    $lead = wonDeal();
    $other = clinicFor(wonDeal());
    $otherBranch = Branch::withoutGlobalScopes()->where('clinic_id', $other->id)->sole();
    acceptAsOwner($lead);

    submitStep('primary_branch', ['name' => 'Indiranagar'])->assertSessionHasErrors(['address_line1' => 'The address field is required.', 'pincode' => 'The PIN code field is required.']);
    submitStep('primary_branch', [...requiredStepValues(clinicFor($lead))['primary_branch'], 'branch_id' => $otherBranch->id])->assertSessionHasNoErrors();

    expect(Branch::withoutGlobalScopes()->where('clinic_id', clinicFor($lead)->id)->sole()->name)->toBe('Indiranagar')
        ->and($otherBranch->fresh()->name)->toBe('Main Branch');
});

it('keeps each owner inside their own clinic', function () {
    $mine = wonDeal();
    $theirs = wonDeal();
    $theirClinic = clinicFor($theirs);
    acceptAsOwner($mine);

    $this->get(route('clinic.onboarding.index', ['clinic' => $theirClinic->slug]))->assertStatus(403);

    // Their session is untouched whatever this owner submits.
    submitStep('clinic_identity', requiredStepValues(clinicFor($mine))['clinic_identity']);
    expect(OnboardingSessionStep::withoutGlobalScopes()->whereHas('session', fn ($q) => $q->withoutGlobalScopes()->where('clinic_id', $theirClinic->id))->where('status', 'completed')->count())->toBe(0);
});

it('renders template-specific sections from the specialty CRM sold', function (array $specialties, string $template, ?string $resource) {
    $clinic = clinicFor(wonDeal(['healthcare_specialties' => $specialties]));
    $session = OnboardingSession::withoutGlobalScopes()->where('clinic_id', $clinic->id)->sole();
    $steps = OnboardingSessionStep::withoutGlobalScopes()->where('onboarding_session_id', $session->id)->pluck('step_key');

    expect($session->template_key)->toBe($template)
        ->and($steps->contains('clinical_resources'))->toBe($resource !== null);

    if ($resource) {
        $view = app(OnboardingEngine::class)->view($session, null, 'clinical_resources');
        expect($view['step']['title'])->toBe($resource);
    }
})->with([
    'dental' => [['dental'], 'dental', 'Dental chairs'],
    'physiotherapy' => [['physiotherapy'], 'physiotherapy', 'Treatment beds'],
    'general medicine' => [['general_hospital'], 'general_medicine', null],
    'multi-specialty' => [['dental', 'physiotherapy'], 'multi_specialty', 'Consultation rooms'],
]);

it('suggests the services of every specialty a multi-specialty clinic was sold', function () {
    $clinic = clinicFor(wonDeal(['healthcare_specialties' => ['dental', 'physiotherapy']]));

    $codes = ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinic->id)->pluck('code');
    expect($codes)->toContain('DEN-RCT')->toContain('PHY-SESS');
});

it('completes onboarding end to end, invites staff, and activates the clinic', function () {
    $this->seed(SystemRoleTemplateSeeder::class);
    $lead = wonDeal();
    acceptAsOwner($lead);
    $clinic = clinicFor($lead);

    // The review refuses to finish while required steps are open.
    submitStep('review', ['confirmed' => true])->assertSessionHasErrors('step');

    foreach (requiredStepValues($clinic) as $step => $values) {
        submitStep($step, $values)->assertSessionHasNoErrors();
    }

    $doctorRole = Role::whereNull('branch_id')->where('name', 'Doctor')->value('id');
    $branchId = Branch::withoutGlobalScopes()->where('clinic_id', $clinic->id)->value('id');
    submitStep('staff_invitations', ['invites' => [
        ['name' => 'Dr. Arjun Rao', 'email' => 'arjun@clinic.test', 'staff_type' => 'dentist', 'role_id' => $doctorRole, 'branch_ids' => [$branchId]],
    ]])->assertSessionHasNoErrors();

    $this->post(route('clinic.onboarding.skip', ['step' => 'communication']))->assertRedirect();
    submitStep('review', ['confirmed' => true])->assertRedirect(route('clinic.onboarding.done'));

    // Configuration landed in the real stores.
    expect(ClinicOperatingHour::withoutGlobalScopes()->where('branch_id', $branchId)->where('day_of_week', 0)->value('is_closed'))->toBeTrue()
        ->and(ClinicRoom::where('branch_id', $branchId)->where('type', 'chair')->where('is_active', true)->count())->toBe(3)
        ->and(ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_active', true)->count())->toBe(6)
        ->and($clinic->fresh()->appointment_config['holidays'])->toBe(['2026-12-25'])
        ->and($clinic->fresh()->appointment_config['buffer_minutes'])->toBe(5);

    // The doctor's invitation carries clinic, branch, role, type and inviter.
    $doctor = ClinicEmployee::withoutGlobalScopes()->where('email', 'arjun@clinic.test')->sole();
    expect($doctor->clinic_id)->toBe($clinic->id)
        ->and($doctor->staff_type)->toBe('dentist')
        ->and($doctor->branches()->pluck('branches.id')->all())->toBe([$branchId])
        ->and($doctor->roles()->pluck('clinic_employee_roles.role_id')->all())->toBe([$doctorRole])
        ->and($doctor->invitations()->withoutGlobalScopes()->value('created_by'))->toBe(ownerFor($lead)->id);
    Mail::assertQueued(StaffInvitationMail::class, fn (StaffInvitationMail $mail) => $mail->hasTo('arjun@clinic.test'));

    // The clinic is live.
    $session = OnboardingSession::withoutGlobalScopes()->where('clinic_id', $clinic->id)->sole();
    expect($session->status)->toBe(OnboardingSessionStatus::Completed)
        ->and(ClinicOwnerInvitation::withoutGlobalScopes()->sole()->status)->toBe(OwnerInvitationStatus::Active)
        ->and(OnboardingProgress::where('organization_id', $clinic->organization_id)->value('status'))->toBe('completed');
    $this->get(route('clinic.onboarding.done'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Onboarding/Done', false));
    $this->get(route('clinic.dashboard'))->assertOk();
    $this->get(route('clinic.onboarding.index'))->assertRedirect(route('dashboard'));
    // A late auto-save is answered, not redirected and replayed.
    $this->putJson(route('clinic.onboarding.draft', ['step' => 'review']), ['values' => []])->assertStatus(409);

    foreach (['onboarding.session_created', 'onboarding.started', 'onboarding.step_completed', 'onboarding.step_skipped', 'onboarding.completed', 'owner_invitation.accepted', 'clinic.activated', 'invitation.created'] as $event) {
        expect(SecurityAuditLog::where('event', $event)->exists())->toBeTrue("missing audit event {$event}");
    }
});

it('refuses staff invitations to another clinic\'s branch and owner-level roles', function () {
    $this->seed(SystemRoleTemplateSeeder::class);
    $lead = wonDeal();
    $foreignBranch = Branch::withoutGlobalScopes()->where('clinic_id', clinicFor(wonDeal())->id)->value('id');
    acceptAsOwner($lead);
    $doctorRole = Role::whereNull('branch_id')->where('name', 'Doctor')->value('id');

    submitStep('staff_invitations', ['invites' => [['name' => 'X', 'email' => 'x@clinic.test', 'staff_type' => 'dentist', 'role_id' => $doctorRole, 'branch_ids' => [$foreignBranch]]]])
        ->assertSessionHasErrors('invites.0.branch_ids.0');

    $ownerRole = Role::firstOrCreate(['name' => 'Organization Admin', 'guard_name' => 'web', 'branch_id' => null]);
    submitStep('staff_invitations', ['invites' => [['name' => 'X', 'email' => 'x@clinic.test', 'staff_type' => 'admin', 'role_id' => $ownerRole->id, 'branch_ids' => [Branch::withoutGlobalScopes()->where('clinic_id', clinicFor($lead)->id)->value('id')]]]])
        ->assertSessionHasErrors('invites.0.role_id');

    expect(ClinicEmployee::withoutGlobalScopes()->where('email', 'x@clinic.test')->exists())->toBeFalse();
});

it('shows staff a waiting screen and refuses their changes until the clinic is set up', function () {
    $lead = wonDeal();
    $clinic = clinicFor($lead);
    $receptionist = User::factory()->create(['user_type' => UserType::ClinicUser]);
    ClinicMembership::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $receptionist->id, 'status' => ClinicMembershipStatus::Active, 'has_all_branches_access' => true]);

    app()->instance('enforce_tenant_scope_in_tests', true);
    $this->actingAs($receptionist)->get(route('clinic.onboarding.index'))->assertInertia(fn (Assert $page) => $page->where('mode', 'waiting'));
    $this->actingAs($receptionist)->post(route('clinic.onboarding.submit', ['step' => 'clinic_identity']), ['idempotency_key' => 'abcdefgh', 'values' => requiredStepValues($clinic)['clinic_identity']])->assertForbidden();
});

it('gives an invited doctor their own practitioner onboarding after accepting', function () {
    $this->seed(SystemRoleTemplateSeeder::class);
    $lead = wonDeal();
    acceptAsOwner($lead);
    $clinic = clinicFor($lead);
    foreach (requiredStepValues($clinic) as $step => $values) {
        submitStep($step, $values);
    }
    $branchId = Branch::withoutGlobalScopes()->where('clinic_id', $clinic->id)->value('id');
    $doctorRole = Role::whereNull('branch_id')->where('name', 'Doctor')->value('id');
    $receptionRole = Role::whereNull('branch_id')->where('name', 'Receptionist')->value('id');
    submitStep('staff_invitations', ['invites' => [
        ['name' => 'Dr. Arjun Rao', 'email' => 'arjun@clinic.test', 'staff_type' => 'dentist', 'role_id' => $doctorRole, 'branch_ids' => [$branchId]],
        ['name' => 'Priya Desk', 'email' => 'priya@clinic.test', 'staff_type' => 'receptionist', 'role_id' => $receptionRole, 'branch_ids' => [$branchId]],
    ]]);
    submitStep('review', ['confirmed' => true]);
    auth()->logout();

    $urls = [];
    Mail::assertQueued(StaffInvitationMail::class, function (StaffInvitationMail $mail) use (&$urls) {
        $urls[$mail->employee->email] = $mail->acceptUrl;

        return true;
    });

    $this->post($urls['arjun@clinic.test'], ['password' => 'Doctor-pass-123', 'password_confirmation' => 'Doctor-pass-123'])->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('clinic.onboarding.index'));
    $this->get(route('clinic.onboarding.index'))->assertInertia(fn (Assert $page) => $page
        ->where('session.kind', 'staff')
        ->where('session.template', 'staff_clinical')
        ->where('steps.1.key', 'my_professional'));

    $doctor = ClinicEmployee::withoutGlobalScopes()->where('email', 'arjun@clinic.test')->sole();
    $service = ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_active', true)->value('id');
    submitStep('my_profile', ['name' => 'Dr. Arjun Rao', 'phone' => '9800000011', 'gender' => 'male'])->assertSessionHasNoErrors();
    submitStep('my_professional', ['profession' => 'Dentist', 'specialty' => 'Orthodontics', 'qualification' => 'MDS', 'degree_type' => 'master', 'specialization' => 'Orthodontics', 'institution' => 'GDC Bengaluru', 'year' => 2015, 'registration_number' => 'KA-12345', 'registration_authority' => 'Karnataka State Dental Council'])->assertSessionHasNoErrors();
    submitStep('my_services', ['service_ids' => [$service]])->assertSessionHasNoErrors();
    submitStep('my_schedule', ['days' => collect(range(0, 6))->map(fn ($day) => ['day' => $day, 'open' => $day >= 1 && $day <= 5, 'start' => '10:00', 'end' => '17:00'])->all()])->assertSessionHasNoErrors();
    submitStep('my_review', ['confirmed' => true])->assertRedirect(route('clinic.onboarding.done'));

    expect($doctor->services()->pluck('clinical_procedures.id')->all())->toBe([$service])
        ->and(Practitioner::withoutGlobalScopes()->where('clinic_employee_id', $doctor->id)->value('registration_number'))->toBe('KA-12345')
        ->and(PractitionerQualification::withoutGlobalScopes()->where('clinic_id', $clinic->id)->value('qualification'))->toBe('MDS');
    // Onboarded, the doctor's home is the Dashboard, carrying their own day.
    $this->followingRedirects()->get(route('clinic.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Dashboard', true)
            ->where('widgets', fn ($widgets) => collect($widgets)->pluck('key')->contains('my_waiting')));

    // A receptionist only sees the fields that apply to them.
    auth()->logout();
    $this->post($urls['priya@clinic.test'], ['password' => 'Recep-pass-123', 'password_confirmation' => 'Recep-pass-123']);
    $this->get(route('clinic.onboarding.index'))->assertInertia(fn (Assert $page) => $page
        ->where('session.template', 'staff_general')
        ->has('steps', 3));
});

// ─── Onboarding pages ───────────────────────────────────────
//
// The screens behind each route exist on disk (an unbuilt page falls back to
// the Unavailable placeholder, which would strand a new owner here), and the
// server keeps every rule the screens rely on.

it('renders real Account, Wizard and Done pages, never the Unavailable placeholder', function () {
    $this->seed(SystemRoleTemplateSeeder::class);
    $lead = wonDeal();
    app()->instance('enforce_tenant_scope_in_tests', true);
    test()->post(ownerInviteUrl($lead->email), ['password' => 'Str0ng-pass-123', 'password_confirmation' => 'Str0ng-pass-123']);

    $this->get(route('clinic.onboarding.index'))->assertRedirect(route('clinic.onboarding.account'));
    $this->get(route('clinic.onboarding.account'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/Onboarding/Account', true)
        ->where('account.email', $lead->email)
        ->where('links.account', route('clinic.onboarding.account')));

    $this->post(route('clinic.onboarding.account.store'), ['name' => 'Dr. Meera Iyer', 'phone' => '9800000001'])->assertRedirect(route('clinic.onboarding.index'));
    $this->get(route('clinic.onboarding.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/Onboarding/Wizard', true)
        ->where('mode', 'edit')
        ->where('step.component', 'clinic_identity')
        ->has('links.submit')
        ->has('links.draft'));

    foreach (requiredStepValues(clinicFor($lead)) as $step => $values) {
        submitStep($step, $values)->assertSessionHasNoErrors();
    }
    submitStep('review', ['confirmed' => true])->assertRedirect(route('clinic.onboarding.done'));

    $this->get(route('clinic.onboarding.done'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/Onboarding/Done', true)
        ->where('kind', 'clinic')
        ->where('links.dashboard', route('dashboard'))
        // Staff management has no page yet, so it is not offered.
        ->where('links.staff', null));
});

it('every section the templates use has a form on the Wizard', function () {
    $wizard = file_get_contents(resource_path('js/pages/ClinicPortal/Onboarding/Wizard.tsx'));

    foreach (config('clinic_onboarding.sections') as $key => $class) {
        $component = app($class)->component();
        expect($component === 'review' || str_contains($wizard, "  {$component}: "))->toBeTrue("no form for the {$component} step");
    }
});

it('validates the owner\'s account details on the server and keeps them on the account step', function () {
    $lead = wonDeal();
    app()->instance('enforce_tenant_scope_in_tests', true);
    test()->post(ownerInviteUrl($lead->email), ['password' => 'Str0ng-pass-123', 'password_confirmation' => 'Str0ng-pass-123']);

    $this->from(route('clinic.onboarding.account'))->post(route('clinic.onboarding.account.store'), ['name' => '', 'phone' => 'call me'])
        ->assertRedirect(route('clinic.onboarding.account'))
        ->assertSessionHasErrors(['name', 'phone']);

    expect(ClinicOwnerInvitation::withoutGlobalScopes()->sole()->status)->toBe(OwnerInvitationStatus::Accepted)
        ->and(ownerFor($lead)->name)->not->toBe('');
    // Still on the account step: the wizard sends them back to it.
    $this->get(route('clinic.onboarding.index'))->assertRedirect(route('clinic.onboarding.account'));

    $this->post(route('clinic.onboarding.account.store'), ['name' => 'Dr. Meera I.', 'phone' => '+91 98000-00009'])->assertSessionHasNoErrors();
    expect(ownerFor($lead)->name)->toBe('Dr. Meera I.')
        ->and(ownerFor($lead)->phone)->toBe('+91 98000-00009')
        ->and(ClinicOwnerInvitation::withoutGlobalScopes()->sole()->status)->toBe(OwnerInvitationStatus::ProfileCompleted);
});

it('keeps an unfinished clinic on onboarding whichever portal page is opened directly', function () {
    $lead = wonDeal();
    acceptAsOwner($lead);

    foreach ([route('dashboard'), route('clinic.dashboard'), route('clinic.appointments.index'), route('clinic.notifications.index')] as $url) {
        $this->get($url)->assertRedirect(route('clinic.onboarding.index'));
    }
    // The completion page is not reachable before completion either.
    $this->get(route('clinic.onboarding.done'))->assertRedirect(route('clinic.onboarding.index'));
});

it('keeps progress across a refresh and never marks a failed save as done', function () {
    $lead = wonDeal();
    acceptAsOwner($lead);
    $values = requiredStepValues(clinicFor($lead));

    // A failed save: nothing completed, nothing applied.
    submitStep('clinic_identity', [...$values['clinic_identity'], 'display_name' => '', 'contact_email' => 'not-an-email'])
        ->assertSessionHasErrors(['display_name', 'contact_email']);
    expect(OnboardingSessionStep::withoutGlobalScopes()->where('step_key', 'clinic_identity')->sole()->status->value)->not->toBe('completed')
        ->and(OnboardingStepSubmission::withoutGlobalScopes()->count())->toBe(0);

    submitStep('clinic_identity', $values['clinic_identity'])->assertSessionHasNoErrors();

    // A refresh shows the next step, with the first counted as done.
    $this->get(route('clinic.onboarding.index'))->assertInertia(fn (Assert $page) => $page
        ->where('step.key', 'primary_branch')
        ->where('session.progress.done', 1)
        ->where('steps.0.status', 'completed'));
    // Choosing a step by name opens it with what was saved.
    $this->get(route('clinic.onboarding.index', ['step' => 'clinic_identity']))->assertInertia(fn (Assert $page) => $page
        ->where('step.key', 'clinic_identity')
        ->where('step.values.display_name', 'Smile Studio'));

    // Finishing with required steps open is refused and leaves the session open.
    submitStep('review', ['confirmed' => true])->assertSessionHasErrors('step');
    submitStep('review', ['confirmed' => false])->assertSessionHasErrors();
    expect(OnboardingSession::withoutGlobalScopes()->sole()->isCompleted())->toBeFalse();
    $this->get(route('dashboard'))->assertRedirect(route('clinic.onboarding.index'));
});

it('finishes once however often the last step is sent, then never loops', function () {
    $lead = wonDeal();
    acceptAsOwner($lead);
    foreach (requiredStepValues(clinicFor($lead)) as $step => $values) {
        submitStep($step, $values);
    }

    // A double click, then a fresh key: once complete, the gate answers every
    // further setup write with the dashboard before it reaches the engine.
    submitStep('review', ['confirmed' => true], 'finish-key-1')->assertRedirect(route('clinic.onboarding.done'));
    submitStep('review', ['confirmed' => true], 'finish-key-1')->assertRedirect(route('dashboard'));
    submitStep('review', ['confirmed' => true], 'finish-key-2')->assertRedirect(route('dashboard'));

    expect(SecurityAuditLog::where('event', 'onboarding.completed')->count())->toBe(1)
        ->and(SecurityAuditLog::where('event', 'clinic.activated')->count())->toBe(1)
        ->and(OnboardingSessionStep::withoutGlobalScopes()->where('step_key', 'review')->sole()->submission_count)->toBe(1);

    // Done shows once complete; onboarding then sends to the dashboard, and the dashboard opens.
    $this->get(route('clinic.onboarding.done'))->assertOk();
    $this->get(route('clinic.onboarding.index'))->assertRedirect(route('dashboard'));
    $this->get(route('clinic.onboarding.account'))->assertRedirect(route('dashboard'));
    $this->followingRedirects()->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Dashboard', true));
});

it('lets only the signed-in clinic owner change setup: not a guest, not staff, not another clinic', function () {
    $lead = wonDeal();
    $clinic = clinicFor($lead);
    $values = requiredStepValues($clinic)['clinic_identity'];

    // A guest is sent to sign in and changes nothing.
    $this->get(route('clinic.onboarding.index'))->assertRedirect();
    $this->post(route('clinic.onboarding.submit', ['step' => 'clinic_identity']), ['idempotency_key' => 'guest-key-1', 'values' => $values])->assertRedirect();

    // A member without settings.manage can watch, not edit, and has no account step.
    $member = User::factory()->create(['user_type' => UserType::ClinicUser]);
    ClinicMembership::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $member->id, 'status' => ClinicMembershipStatus::Active, 'has_all_branches_access' => true]);
    app()->instance('enforce_tenant_scope_in_tests', true);
    $this->actingAs($member)->putJson(route('clinic.onboarding.draft', ['step' => 'clinic_identity']), ['values' => $values])->assertForbidden();
    $this->actingAs($member)->post(route('clinic.onboarding.skip', ['step' => 'communication']))->assertForbidden();
    $this->actingAs($member)->post(route('clinic.onboarding.account.store'), ['name' => 'Taken Over', 'phone' => '9800000099'])->assertNotFound();
    expect($member->fresh()->name)->not->toBe('Taken Over');
    auth()->logout();

    // Another clinic's owner cannot reach this clinic's setup.
    $other = wonDeal();
    acceptAsOwner($other);
    $this->get(route('clinic.onboarding.index', ['clinic' => $clinic->slug]))->assertStatus(403);
    submitStep('clinic_identity', $values)->assertSessionHasNoErrors();

    expect(OnboardingSessionStep::withoutGlobalScopes()
        ->whereHas('session', fn ($q) => $q->withoutGlobalScopes()->where('clinic_id', $clinic->id))
        ->whereNotIn('status', ['pending'])->count())->toBe(0)
        ->and($clinic->fresh()->display_name)->not->toBe('Smile Studio');
});
