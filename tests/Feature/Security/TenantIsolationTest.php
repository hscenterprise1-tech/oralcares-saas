<?php

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\CompanyQuotation;
use App\Models\CompanySubscription;
use App\Models\CompanySupportTicket;
use App\Models\Contact;
use App\Models\CrmSavedView;
use App\Models\CustomerSuccessSnapshot;
use App\Models\DiagnosticResult;
use App\Models\DoctorProfile;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\License;
use App\Models\MarketingAiSetting;
use App\Models\MarketingAudience;
use App\Models\MarketingAutomation;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingAutomationExecution;
use App\Models\MarketingCampaign;
use App\Models\MarketingContact;
use App\Models\MarketingContactImport;
use App\Models\MarketingContactSuppressionLog;
use App\Models\MarketingInstagramAccount;
use App\Models\MarketingInstagramPost;
use App\Models\MarketingSettings;
use App\Models\MarketingTag;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\MarketingWhatsAppMessage;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\MarketingWhatsAppWebhookEvent;
use App\Models\NumberingSequence;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\Practitioner;
use App\Models\SubscriptionEvent;
use App\Models\Task;
use App\Models\Team;
use App\Models\ToothMaster;
use App\Models\ToothSurfaceMaster;
use App\Models\TrialUsageSnapshot;
use App\Models\User;
use App\OrganizationRole;
use App\Scopes\OrganizationScope;
use App\Traits\OrganizationScoped;
use App\UserType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The organisation boundary, proved rather than assumed.
 *
 * `OrganizationScoped` used to fail open: it filtered when a tenant could be
 * resolved and applied nothing when it could not, so a guest — or an
 * authenticated user carrying a null `organization_id` — received every
 * organisation's rows instead of none. Four of eight company users held a null
 * tenant when this was found, so for half the back office there was no boundary.
 *
 * These tests are the thing that makes the fix stay fixed. They force the scope
 * on through `entersOrganizationScope()`, because the suite runs in console and
 * `OrganizationScope` lifts itself there so artisan, queue workers and seeders
 * can act across tenants — without that call every assertion below would pass
 * while proving nothing.
 */

/** Two tenants and a row of each model in each. Built before the scope is armed. */
function seedTwoTenants(): array
{
    $provider = Organization::factory()->create([
        'name' => 'OralCares HQ',
        'role' => OrganizationRole::Provider,
    ]);

    $other = Organization::factory()->create([
        'name' => 'Rival Provider',
        'role' => OrganizationRole::Provider,
    ]);

    foreach ([$provider, $other] as $organization) {
        Lead::factory()->create([
            'organization_id' => $organization->id,
            'company_name' => "Deal for {$organization->name}",
        ]);

        Branch::factory()->create(['organization_id' => $organization->id]);

        MarketingContact::create([
            'organization_id' => $organization->id,
            'name' => "Contact for {$organization->name}",
            'phone' => '90000000'.$organization->id,
            'normalized_phone' => '9190000000'.$organization->id,
        ]);

        /*
         * A campaign needs a sending account, an audience and a template —
         * `marketing_campaigns` has foreign keys on all three, so a campaign
         * cannot be conjured with placeholder ids. Each tenant gets its own
         * set, which is also closer to the real shape: the leak this test
         * guards against is one organisation reading another's campaigns.
         */
        $account = MarketingWhatsAppAccount::create([
            'organization_id' => $organization->id,
            'name' => "Sender for {$organization->name}",
        ]);

        $audience = MarketingAudience::create([
            'organization_id' => $organization->id,
            'name' => "Audience for {$organization->name}",
            'rules' => [],
        ]);

        $template = MarketingWhatsAppTemplate::create([
            'organization_id' => $organization->id,
            'whatsapp_account_id' => $account->id,
            'name' => "template_{$organization->id}",
            'body' => 'Hello {{1}}',
        ]);

        MarketingCampaign::create([
            'organization_id' => $organization->id,
            'name' => "Campaign for {$organization->name}",
            'whatsapp_account_id' => $account->id,
            'audience_id' => $audience->id,
            'template_id' => $template->id,
        ]);
    }

    return [$provider, $other];
}

/** The four models this change put behind the boundary. */
function scopedModels(): array
{
    return [
        'Lead' => Lead::class,
        'Branch' => Branch::class,
        'MarketingContact' => MarketingContact::class,
        'MarketingCampaign' => MarketingCampaign::class,
    ];
}

/* ═══════════════════ 1. fail closed, with nothing to resolve ═════════════ */

it('returns no rows at all to an unauthenticated query', function () {
    seedTwoTenants();

    /* The rows exist — this is a boundary test, not an empty-database test. */
    expect(Lead::withoutOrganizationScope()->count())->toBe(2);

    entersOrganizationScope();
    Auth::logout();

    foreach (scopedModels() as $name => $class) {
        expect($class::count())->toBe(0, "{$name} returned rows to a guest.");
    }
});

it('returns no rows to an authenticated user carrying a null organization_id', function () {
    seedTwoTenants();

    /*
     * The exact shape of the finding. Four company users held a null tenant,
     * and under the old scope each of them was handed every organisation's
     * rows. The migration stamps existing ones, but a null can be reintroduced
     * — by a seeder, an import, a future column default — and the boundary must
     * not depend on that never happening.
     */
    $stateless = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => null,
    ]);

    entersOrganizationScope();
    $this->actingAs($stateless);

    foreach (scopedModels() as $name => $class) {
        expect($class::count())->toBe(0, "{$name} returned rows to a user with no tenant.");
    }
});

it('produces a filter that cannot match, not a filter that is absent', function () {
    /*
     * `whereRaw('1 = 0')` rather than no clause. The distinction is the whole
     * finding: an absent clause reads as success and returns everything.
     */
    entersOrganizationScope();
    Auth::logout();

    expect(Lead::query()->toSql())->toContain('1 = 0')
        ->and(MarketingContact::query()->toSql())->toContain('1 = 0');
});

/* ═══════════════════ 2. one tenant never sees another's rows ═════════════ */

it('never hands a user rows belonging to another organization', function () {
    [$provider, $other] = seedTwoTenants();

    $user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $provider->id,
    ]);

    entersOrganizationScope();
    $this->actingAs($user);

    foreach (scopedModels() as $name => $class) {
        $rows = $class::all();

        expect($rows)->toHaveCount(1, "{$name} did not return exactly its own tenant's row.");
        expect($rows->pluck('organization_id')->unique()->all())
            ->toBe([$provider->id], "{$name} leaked a row from another organization.");
    }

    /* And the other tenant's rows are genuinely there to have been leaked. */
    expect(Lead::withoutOrganizationScope()->where('organization_id', $other->id)->count())->toBe(1);
});

it('lets a master admin cross the boundary, because every gate already does', function () {
    /*
     * Not a hole. `Gate::before` authorizes a master admin for every ability and
     * `CrmScope::LEVEL_ALL` hands them every CRM row, so filtering here would
     * only make the data disagree with the gate. Two master admins in this
     * database carry a null tenant, which under the fail-closed rule would have
     * shown the platform operator an empty product.
     */
    seedTwoTenants();

    $admin = User::factory()->create([
        'user_type' => UserType::MasterAdmin,
        'organization_id' => null,
    ]);

    entersOrganizationScope();
    $this->actingAs($admin);

    expect(Lead::count())->toBe(2)
        ->and(MarketingContact::count())->toBe(2);
});

it('lets an explicitly opened system context through, and closes it again', function () {
    /*
     * The WhatsApp webhook is public and unauthenticated by design, and its
     * payload handler creates leads and contacts. Without this bypass it would
     * have matched nothing and reported success — inbound lead capture would
     * have stopped silently.
     */
    seedTwoTenants();

    entersOrganizationScope();
    Auth::logout();

    expect(Lead::count())->toBe(0);

    $insideCount = OrganizationScope::asSystem(fn () => Lead::count());

    expect($insideCount)->toBe(2)
        /* Restored in a `finally`, so a throwing callback cannot leave it open. */
        ->and(OrganizationScope::inSystemContext())->toBeFalse()
        ->and(Lead::count())->toBe(0);
});

it('still ingests a WhatsApp webhook with the boundary armed', function () {
    /*
     * The regression this change could most easily have caused, and the one
     * that would have been hardest to notice.
     *
     * `/webhooks/whatsapp` is public and unauthenticated — Meta posts to it —
     * and the handler reads and writes `MarketingContact` to turn an inbound
     * message into a conversation. Under the fail-closed rule that path
     * resolves no tenant, so without the explicit bypass in the controller it
     * would match nothing, create nothing, and return `processed: 0` with a
     * 200. Inbound lead capture would have stopped and the endpoint would have
     * gone on reporting success.
     *
     * Every other webhook test in the suite runs with the scope lifted by the
     * console rule, so this is the only one that exercises the bypass.
     */
    $organization = Organization::factory()->create(['role' => OrganizationRole::Provider]);

    MarketingWhatsAppAccount::create([
        'organization_id' => $organization->id,
        'provider' => 'meta',
        'business_account_id' => '1029384756',
        'phone_number_id' => '9876543210123',
        'status' => 'connected',
    ]);

    MarketingContact::create([
        'organization_id' => $organization->id,
        'name' => 'Rahul Patel',
        'phone' => '+91 9876543210',
        'normalized_phone' => '9876543210',
    ]);

    entersOrganizationScope();
    Auth::logout();

    /* With the boundary armed and nobody signed in, a plain read sees nothing. */
    expect(MarketingContact::count())->toBe(0);

    $response = $this->postJson(route('webhooks.whatsapp.handle'), [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => '9876543210123'],
                    'messages' => [[
                        'from' => '919876543210',
                        'id' => 'wamid.HBgLIsolationProof',
                        'timestamp' => time(),
                        'type' => 'text',
                        'text' => ['body' => 'Hello OralCares team!'],
                    ]],
                ],
            ]],
        ]],
    ]);

    $response->assertOk()->assertJson(['status' => 'success', 'processed' => 1]);

    expect(MarketingWhatsAppConversation::withoutGlobalScopes()->count())->toBe(1)
        /* And the bypass closed again behind it. */
        ->and(OrganizationScope::inSystemContext())->toBeFalse();
});

/* ═══════════════════ 3. a lead's tenant is always the seller ═════════════ */

it('refuses to stamp a lead with a customer organization', function () {
    /*
     * Finding T-2, made unrepeatable. Four leads carried customer
     * organisations 18, 19 and 22 because the legacy tenant backfill resolved a
     * missing tenant from the assigned user's `organization_id`, and a master
     * admin in this database was stamped with a customer account.
     */
    /*
     * A provider has to exist for the rule to mean anything: "the seller, not
     * the buyer" is only a distinction an installation can draw once it knows
     * who the seller is. The guard is deliberately quiet on a database that
     * cannot express it — see the comment on `Lead::boot()`.
     */
    Organization::factory()->create(['role' => OrganizationRole::Provider]);
    $customer = Organization::factory()->create(['role' => OrganizationRole::Customer]);

    expect(fn () => Lead::factory()->create(['organization_id' => $customer->id]))
        ->toThrow(InvalidArgumentException::class, 'must reference a provider organization');
});

it('stays quiet when the installation has no provider to compare against', function () {
    /*
     * The other half of that decision, asserted rather than assumed.
     *
     * Sixty-plus test fixtures predate the `role` column and create a single
     * organisation that defaults to `customer` while standing in for the
     * company itself. Throwing there would have been the guard asserting a fact
     * the data does not contain — and, because one of those fixtures sits
     * behind a `dd()`, it took the entire suite down with it.
     */
    $customer = Organization::factory()->create(['role' => OrganizationRole::Customer]);

    $lead = Lead::factory()->create(['organization_id' => $customer->id]);

    expect($lead->organization_id)->toBe($customer->id);
});

it('accepts a lead stamped with the provider organization', function () {
    $provider = Organization::factory()->create(['role' => OrganizationRole::Provider]);

    $lead = Lead::factory()->create(['organization_id' => $provider->id]);

    expect($lead->organization_id)->toBe($provider->id);
});

/* ═══════════════════ 4. architecture: the rule, enforced ════════════════ */

/**
 * Models whose table carries `organization_id` and which are deliberately not
 * scoped, each with the reason it would be wrong to scope them.
 *
 * Two kinds sit here and they are not the same kind of entry.
 *
 * MUST NEVER BE SCOPED — scoping these breaks the product:
 *
 *   User          the login lookup runs *before* anyone is authenticated, so a
 *                 scope resolving the tenant from `auth()->user()` would fail
 *                 closed on the query that produces that user. Nobody could
 *                 sign in.
 *
 *   Clinic, License, CompanySubscription, CompanyQuotation, SubscriptionEvent,
 *   CustomerSuccessSnapshot, OnboardingProgress, TrialUsageSnapshot,
 *   CompanySupportTicket, NumberingSequence, Contact
 *                 these are the company's view *of its customers*. A company
 *                 user's own tenant is the provider organisation, so scoping
 *                 them to it would hide every customer account from the people
 *                 whose job is to manage them. `CrmScope` is what bounds these,
 *                 through the customer accounts a user may read.
 *
 *   ToothMaster, ToothSurfaceMaster
 *                 clinical reference data, shared by every tenant.
 *
 * NOT YET SCOPED — these should be, and are tracked rather than hidden:
 *
 *   The Marketing tables below are provider-owned and belong behind the
 *   boundary. They are not in this change because adding fifteen global scopes
 *   in the same patch as the fail-closed inversion would make a regression
 *   impossible to attribute. `Lead`, `MarketingContact` and `MarketingCampaign`
 *   — the three highest-volume — went first.
 *
 * The list is deliberately explicit. A model added tomorrow with an
 * `organization_id` and no scope fails this test, which is the point: the
 * default is closed, and every exception is written down.
 *
 * @var list<class-string<Model>>
 */
const UNSCOPED_BY_DESIGN = [
    /* Authentication */
    User::class,

    /* The company's view of its customers — bounded by CrmScope, not by tenant */
    Clinic::class,
    License::class,
    CompanySubscription::class,
    CompanyQuotation::class,
    CompanySupportTicket::class,
    SubscriptionEvent::class,
    CustomerSuccessSnapshot::class,
    OnboardingProgress::class,
    TrialUsageSnapshot::class,
    NumberingSequence::class,
    Contact::class,

    /* Shared clinical reference data */
    ToothMaster::class,
    ToothSurfaceMaster::class,

    /* CRM routing configuration */
    Team::class,
    CrmSavedView::class,

    /* Marketing — provider-owned, scoped in a follow-up (see docblock) */
    MarketingAiSetting::class,
    MarketingAudience::class,
    MarketingAutomation::class,
    MarketingAutomationEvent::class,
    MarketingAutomationExecution::class,
    MarketingContactImport::class,
    MarketingContactSuppressionLog::class,
    MarketingInstagramAccount::class,
    MarketingInstagramPost::class,
    MarketingSettings::class,
    MarketingTag::class,
    MarketingWhatsAppAccount::class,
    MarketingWhatsAppConversation::class,
    MarketingWhatsAppMessage::class,
    MarketingWhatsAppTemplate::class,
    MarketingWhatsAppWebhookEvent::class,

    /* Clinic domain entities carrying organization_id for Tier 1 denormalization, scoped by ClinicScoped / TenantScope */
    Appointment::class,
    DiagnosticResult::class,
    DoctorProfile::class,
    Patient::class,
    PatientTimelineEvent::class,
    Practitioner::class,

    /* Platform imports and polymorphic tasks */
    LeadImport::class,
    Task::class,
];

/**
 * Every Eloquent model whose table carries an `organization_id` column.
 *
 * @return array<string, class-string<Model>>
 */
function tenantTableModels(): array
{
    $models = [];

    foreach (File::files(app_path('Models')) as $file) {
        $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $table = (new $class)->getTable();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
            continue;
        }

        $models[$file->getFilenameWithoutExtension()] = $class;
    }

    return $models;
}

it('scopes every model whose table carries a tenant, or says why not', function () {
    $unjustified = [];

    foreach (tenantTableModels() as $name => $class) {
        $isScoped = in_array(OrganizationScoped::class, class_uses_recursive($class), true);

        if (! $isScoped && ! in_array($class, UNSCOPED_BY_DESIGN, true)) {
            $unjustified[] = $name;
        }
    }

    expect($unjustified)->toBe(
        [],
        'These models carry an organization_id and neither use OrganizationScoped nor appear in '
        .'UNSCOPED_BY_DESIGN. Scope them, or add them to the list with the reason: '
        .implode(', ', $unjustified)
    );
});

it('keeps the exemption list honest', function () {
    /*
     * An allow-list nobody prunes becomes a place to hide things. If a model on
     * it gains the trait, or stops existing, the entry has to go — otherwise
     * the list slowly stops describing the codebase and stops being read.
     */
    $stale = [];

    foreach (UNSCOPED_BY_DESIGN as $class) {
        if (! class_exists($class)) {
            $stale[] = $class.' (no longer exists)';

            continue;
        }

        if (in_array(OrganizationScoped::class, class_uses_recursive($class), true)) {
            $stale[] = $class.' (is now scoped — remove it from the list)';
        }
    }

    expect($stale)->toBe([], implode('; ', $stale));
});

it('keeps the three highest-volume company tables behind the boundary', function () {
    /*
     * Named individually rather than counted, because these three are the
     * finding: they are the largest tables on the company side and carried no
     * global scope at all, so isolation rested on 58 controllers each
     * remembering a `where` clause.
     */
    foreach ([Lead::class, MarketingContact::class, MarketingCampaign::class] as $class) {
        expect(class_uses_recursive($class))->toContain(OrganizationScoped::class);
    }
});
