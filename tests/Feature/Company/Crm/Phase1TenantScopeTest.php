<?php

use App\LeadStatus;
use App\Models\CommercialDocument;
use App\Models\Contact;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Support\CrmScope;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Phase 1 item 4 — one tenant boundary.
 *
 * CrmScope is the authority. It resolves three levels from permissions:
 *
 *   all           master admin — the platform operator, no tenant boundary
 *   organization  manager — every record inside their own organization
 *   assigned      rep — only what is assigned to them
 *
 * LeadWorkspaceService used to bypass it entirely, filtering on
 * `resolveOrganizationId()` with no rep-level narrowing, so a rep saw the whole
 * organization's book on the lists while the Overview showed only their own.
 *
 * The `orWhereNull` branch is also gone. It existed because pre-Won leads once
 * carried a null organization_id; Phase 0's createLead() always stamps the
 * tenant, so the branch is now only a cross-tenant read of every other
 * tenant's open pipeline.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->orgA = Organization::factory()->create(['name' => 'Tenant A']);
    $this->orgB = Organization::factory()->create(['name' => 'Tenant B']);

    $this->managerA = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->orgA->id]);
    $this->managerA->assignRole('CRM Manager');

    $this->repA = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->orgA->id]);
    $this->repA->assignRole('Sales Representative');

    $this->repA2 = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->orgA->id]);
    $this->repA2->assignRole('Sales Representative');

    $this->managerB = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->orgB->id]);
    $this->managerB->assignRole('CRM Manager');

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->leadA = Lead::factory()->create([
        'company_name' => 'Alpha Dental',
        'organization_id' => $this->orgA->id,
        'assigned_user_id' => $this->repA->id,
        'status' => LeadStatus::Contacted,
    ]);

    $this->leadA2 = Lead::factory()->create([
        'company_name' => 'Alpha Second',
        'organization_id' => $this->orgA->id,
        'assigned_user_id' => $this->repA2->id,
        'status' => LeadStatus::Contacted,
    ]);

    $this->leadB = Lead::factory()->create([
        'company_name' => 'Beta Dental',
        'organization_id' => $this->orgB->id,
        'assigned_user_id' => null,
        'status' => LeadStatus::Contacted,
    ]);

    $this->orphanLead = Lead::factory()->create([
        'company_name' => 'Orphan Legacy',
        'organization_id' => null,
        'assigned_user_id' => null,
        'status' => LeadStatus::New,
    ]);
});

function leadNamesVisibleTo(User $user): array
{
    return CrmScope::leads($user)->pluck('company_name')->all();
}

// ─────────────────────────────────────────────────────────────────────────────
// The scope itself
// ─────────────────────────────────────────────────────────────────────────────

it('does not let one tenant read another tenant leads', function () {
    expect(leadNamesVisibleTo($this->managerA))
        ->toContain('Alpha Dental')
        ->not->toContain('Beta Dental');

    expect(leadNamesVisibleTo($this->managerB))
        ->toContain('Beta Dental')
        ->not->toContain('Alpha Dental');
});

it('no longer exposes tenantless leads to every manager', function () {
    // The orWhereNull hole: an orphaned record must not be readable by an
    // arbitrary tenant just because it has no owner.
    expect(leadNamesVisibleTo($this->managerA))->not->toContain('Orphan Legacy')
        ->and(leadNamesVisibleTo($this->managerB))->not->toContain('Orphan Legacy');
});

it('keeps tenantless leads visible to the platform operator', function () {
    // Deliberate: master admin is the only level with no tenant boundary, so
    // orphaned records stay reachable for backfill rather than disappearing.
    expect(leadNamesVisibleTo($this->admin))->toContain('Orphan Legacy');
});

it('narrows a rep to their own assigned leads', function () {
    expect(leadNamesVisibleTo($this->repA))
        ->toContain('Alpha Dental')
        ->not->toContain('Alpha Second')
        ->not->toContain('Beta Dental');
});

// ─────────────────────────────────────────────────────────────────────────────
// The lists must agree with the scope
// ─────────────────────────────────────────────────────────────────────────────

it('scopes the leads directory to the tenant', function () {
    $names = collect(
        app(LeadWorkspaceService::class)
            ->getLeadsDirectoryPayload([], $this->managerA)['leads']['data'] ?? []
    )->pluck('company_name');

    expect($names)->toContain('Alpha Dental')->not->toContain('Beta Dental');
});

it('scopes the leads directory to the rep', function () {
    $names = collect(
        app(LeadWorkspaceService::class)
            ->getLeadsDirectoryPayload([], $this->repA)['leads']['data'] ?? []
    )->pluck('company_name');

    expect($names)->toContain('Alpha Dental')->not->toContain('Alpha Second');
});

it('scopes the pipeline board to the tenant', function () {
    $columns = app(LeadWorkspaceService::class)
        ->getPipelinePayload([], $this->managerA)['columns'];

    $names = collect($columns)->flatMap(fn ($c) => collect($c['cards'])->pluck('company_name'));

    expect($names)->toContain('Alpha Dental')->not->toContain('Beta Dental');
});

it('reports tenant-correct headline counts on the leads directory', function () {
    $payload = app(LeadWorkspaceService::class)
        ->getLeadsDirectoryPayload([], $this->managerA);

    // Two Alpha leads, and neither Beta nor the orphan.
    expect($payload['metrics']['total_leads'])->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Contacts
// ─────────────────────────────────────────────────────────────────────────────

it('does not leak contacts across tenants', function () {
    Contact::create([
        'organization_id' => $this->orgA->id, 'lead_id' => $this->leadA->id,
        'first_name' => 'Alpha', 'last_name' => 'Person', 'email' => 'a@alpha.test',
    ]);
    Contact::create([
        'organization_id' => $this->orgB->id, 'lead_id' => $this->leadB->id,
        'first_name' => 'Beta', 'last_name' => 'Person', 'email' => 'b@beta.test',
    ]);

    /*
     * Exercised at the scope rather than through a route: the standalone
     * contacts directory is gone, and deal contacts are read as part of the
     * Deal Profile payload for one deal at a time. CrmScope::contacts is what
     * that payload and every other contact read go through, so it is where the
     * tenant boundary actually lives.
     */
    $names = CrmScope::contacts($this->managerA)->pluck('first_name')->all();

    expect($names)->toContain('Alpha')->not->toContain('Beta');
});

// ─────────────────────────────────────────────────────────────────────────────
// Commercial documents
// ─────────────────────────────────────────────────────────────────────────────

it('does not leak commercial documents across tenants', function () {
    // Exercised at the scope rather than through the route: `CommercialDocument`
    // has no policy and no Gate::define, so `Gate::authorize('viewAny', ...)`
    // denies every non-master-admin. That authorization gap is pre-existing and
    // separate from the unscoped-query defect this item closes.
    CommercialDocument::factory()->create(['lead_id' => $this->leadA->id, 'document_number' => 'DOC-ALPHA']);
    CommercialDocument::factory()->create(['lead_id' => $this->leadB->id, 'document_number' => 'DOC-BETA']);

    $visible = CrmScope::commercialDocuments($this->managerA)->pluck('document_number');

    expect($visible)->toContain('DOC-ALPHA')->not->toContain('DOC-BETA');
});

it('narrows commercial documents to a rep own leads', function () {
    CommercialDocument::factory()->create(['lead_id' => $this->leadA->id, 'document_number' => 'DOC-REP']);
    CommercialDocument::factory()->create(['lead_id' => $this->leadA2->id, 'document_number' => 'DOC-OTHER-REP']);

    $visible = CrmScope::commercialDocuments($this->repA)->pluck('document_number');

    expect($visible)->toContain('DOC-REP')->not->toContain('DOC-OTHER-REP');
});

it('does not offer another tenant leads to a lead picker', function () {
    // The pickers are built from CrmScope, so a tenant user only ever sees
    // their own leads — and it is now capped rather than loading the platform.
    // This was checked through the document create form, which CC3 retired;
    // the scope is the thing under test either way.
    $names = CrmScope::leads($this->managerA)
        ->select('id', 'company_name', 'contact_name')
        ->limit(200)
        ->pluck('company_name');

    expect($names)->toContain('Alpha Dental')->not->toContain('Beta Dental');
});

// ─────────────────────────────────────────────────────────────────────────────
// Demos — visibility follows the real role model, not a column that never existed
// ─────────────────────────────────────────────────────────────────────────────

it('shows a manager every demo in their tenant regardless of presenter', function () {
    // `$user->is_admin` does not exist on users, so this check was always
    // falsy and narrowed every company user to demos they personally ran.
    Demo::factory()->create([
        'lead_id' => $this->leadA->id,
        'presenter_id' => $this->repA->id,
        'scheduled_at' => now()->addDay(),
    ]);

    $payload = app(LeadWorkspaceService::class)
        ->getDemoWorkspacePayload($this->managerA);

    expect($payload['allDemos'])->toHaveCount(1);
});

it('narrows a rep to demos on their own leads', function () {
    Demo::factory()->create([
        'lead_id' => $this->leadA->id, 'presenter_id' => $this->repA->id, 'scheduled_at' => now()->addDay(),
    ]);
    Demo::factory()->create([
        'lead_id' => $this->leadA2->id, 'presenter_id' => $this->repA2->id, 'scheduled_at' => now()->addDay(),
    ]);

    $payload = app(LeadWorkspaceService::class)
        ->getDemoWorkspacePayload($this->repA);

    expect($payload['allDemos'])->toHaveCount(1);
});

it('does not leak demos across tenants', function () {
    Demo::factory()->create([
        'lead_id' => $this->leadB->id, 'presenter_id' => $this->managerB->id, 'scheduled_at' => now()->addDay(),
    ]);

    $payload = app(LeadWorkspaceService::class)
        ->getDemoWorkspacePayload($this->managerA);

    expect($payload['allDemos'])->toHaveCount(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Platform functionality must not be narrowed by accident
// ─────────────────────────────────────────────────────────────────────────────

it('leaves the platform operator able to read every tenant', function () {
    expect(leadNamesVisibleTo($this->admin))
        ->toContain('Alpha Dental')
        ->toContain('Beta Dental')
        ->toContain('Orphan Legacy');
});
