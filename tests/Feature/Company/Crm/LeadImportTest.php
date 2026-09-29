<?php

use App\Jobs\ProcessLeadImportJob;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\Organization;
use App\Models\User;
use App\Services\CRM\LeadImportService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Area 2 (Lead import) of the Final Gap Closure — bulk lead creation from a
 * CSV, built on `LeadWorkspaceService::findDuplicateLead()`/`createLead()`
 * rather than a second dedup/creation path. See `LeadImportService`.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->org = Organization::factory()->create();

    $this->rep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->rep->assignRole('Sales Representative');
});

it('refuses a user with no CRM permission to preview an import', function () {
    $outsider = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);

    $csv = "company_name,contact_name,phone,city,healthcare_specialty\nApex Dental,Dr Mehta,9876543210,Vadodara,Dentist\n";
    $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

    $this->actingAs($outsider)
        ->postJson(route('company.crm.leads.import.preview'), ['file' => $file])
        ->assertForbidden();
});

it('previews a CSV and classifies every row as valid, a duplicate, or invalid', function () {
    $existing = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Apex Dental',
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
    ]);

    $csv = "company_name,contact_name,phone,city,healthcare_specialty\n"
        ."Apex Dental,Dr Mehta,9876543210,Vadodara,Dentist\n" // duplicate: already on file
        ."Shah Hospital,Dr Shah,9825012345,Ahmedabad,Orthodontist\n" // valid
        ."Shah Hospital,Dr Shah,9825012345,Ahmedabad,Orthodontist\n" // in-file duplicate
        .",No Company,9998887776,Surat,Dentist\n"; // invalid: missing company_name

    $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

    $response = $this->actingAs($this->rep)
        ->postJson(route('company.crm.leads.import.preview'), ['file' => $file]);

    $response->assertOk()
        ->assertJson([
            'total_rows' => 4,
            'valid_rows' => 1,
            'duplicate_rows' => 2,
            'invalid_rows' => 1,
            'missing_columns' => [],
        ])
        ->assertJsonPath('rows.0.status', 'duplicate')
        ->assertJsonPath('rows.1.status', 'valid')
        ->assertJsonPath('rows.2.status', 'duplicate')
        ->assertJsonPath('rows.3.status', 'invalid');

    expect($existing)->not->toBeNull();
});

it('reports missing required columns without crashing on a malformed file', function () {
    $csv = "name,mobile\nSomething,123\n";
    $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

    $response = $this->actingAs($this->rep)
        ->postJson(route('company.crm.leads.import.preview'), ['file' => $file]);

    $response->assertOk()
        ->assertJsonPath('total_rows', 1)
        ->assertJsonPath('invalid_rows', 1);

    expect($response->json('missing_columns'))->toContain('company_name', 'contact_name', 'phone', 'city', 'healthcare_specialty');
});

it('processes a small batch inline, creates leads only for valid rows, and records every row outcome', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Existing Clinic',
        'phone' => '9111111111',
        'normalized_phone' => '9111111111',
    ]);

    $rows = [
        [
            'company_name' => 'New Dental Co',
            'contact_name' => 'Dr New',
            'phone' => '9222222222',
            'city' => 'Rajkot',
            'healthcare_specialty' => 'Dentist',
            'email' => 'new@dental.test',
            'row_number' => 2,
        ],
        [
            'company_name' => 'Existing Clinic',
            'contact_name' => 'Dr Existing',
            'phone' => '9111111111',
            'city' => 'Surat',
            'healthcare_specialty' => 'Dentist',
            'row_number' => 3,
        ],
    ];

    $response = $this->actingAs($this->rep)
        ->postJson(route('company.crm.leads.import.process'), [
            'rows' => $rows,
            'original_filename' => 'leads.csv',
        ]);

    $response->assertOk()
        ->assertJson([
            'queued' => false,
            'status' => 'completed',
            'result' => [
                'imported_count' => 1,
                'duplicate_count' => 1,
                'invalid_count' => 0,
            ],
        ]);

    $this->assertDatabaseHas('leads', [
        'organization_id' => $this->org->id,
        'company_name' => 'New Dental Co',
        'source' => 'import',
    ]);

    // Only the valid row created a lead — the duplicate did not.
    expect(Lead::where('organization_id', $this->org->id)->count())->toBe(2);

    $importId = $response->json('import_id');
    $this->assertDatabaseHas('lead_import_rows', ['import_id' => $importId, 'outcome' => 'created']);
    $this->assertDatabaseHas('lead_import_rows', ['import_id' => $importId, 'outcome' => 'duplicate']);
});

it('re-derives each row at process time rather than trusting the client-sent status', function () {
    // Not a duplicate at preview time, but somebody else creates the same
    // lead before this batch is processed — process() must still catch it.
    $rows = [[
        'company_name' => 'Race Condition Clinic',
        'contact_name' => 'Dr Race',
        'phone' => '9444444444',
        'city' => 'Pune',
        'healthcare_specialty' => 'Dentist',
        'status' => 'valid',
        'row_number' => 2,
    ]];

    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Race Condition Clinic',
        'phone' => '9444444444',
        'normalized_phone' => '9444444444',
    ]);

    $response = $this->actingAs($this->rep)
        ->postJson(route('company.crm.leads.import.process'), ['rows' => $rows]);

    $response->assertOk()->assertJsonPath('result.imported_count', 0)
        ->assertJsonPath('result.duplicate_count', 1);

    expect(Lead::where('organization_id', $this->org->id)->count())->toBe(1);
});

it('dispatches a large batch to the background queue instead of processing it inline', function () {
    Queue::fake();

    $rows = collect(range(1, 501))->map(fn ($i) => [
        'company_name' => "Bulk Clinic {$i}",
        'contact_name' => "Dr {$i}",
        'phone' => '9'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
        'city' => 'Surat',
        'healthcare_specialty' => 'Dentist',
        'row_number' => $i + 1,
    ])->all();

    $response = $this->actingAs($this->rep)
        ->postJson(route('company.crm.leads.import.process'), ['rows' => $rows]);

    $response->assertOk()->assertJson(['queued' => true]);

    Queue::assertPushed(ProcessLeadImportJob::class);

    $this->assertDatabaseHas('lead_imports', [
        'status' => 'pending',
        'total_rows' => 501,
    ]);

    expect(Lead::where('organization_id', $this->org->id)->count())->toBe(0);
});

it('processes a queued import job the same way as the inline path', function () {
    $import = LeadImport::create([
        'organization_id' => $this->org->id,
        'created_by_user_id' => $this->rep->id,
        'status' => LeadImport::STATUS_PENDING,
        'total_rows' => 1,
    ]);

    $rows = [[
        'company_name' => 'Queued Clinic',
        'contact_name' => 'Dr Queued',
        'phone' => '9555555555',
        'city' => 'Nashik',
        'healthcare_specialty' => 'Dentist',
        'row_number' => 2,
    ]];

    (new ProcessLeadImportJob($import->id, $rows, $this->org->id, $this->rep->id))->handle(app(LeadImportService::class));

    $import->refresh();

    expect($import->status)->toBe(LeadImport::STATUS_COMPLETED)
        ->and($import->imported_count)->toBe(1);

    $this->assertDatabaseHas('leads', ['company_name' => 'Queued Clinic', 'organization_id' => $this->org->id]);
});

it('does not double-import when the same queued job is delivered twice', function () {
    // A DB-queue visibility timeout redelivering a still-running job, or a
    // manual retry, hands the identical job payload to a second worker while
    // the first has already claimed the row. Only the winner should import.
    $import = LeadImport::create([
        'organization_id' => $this->org->id,
        'created_by_user_id' => $this->rep->id,
        'status' => LeadImport::STATUS_PENDING,
        'total_rows' => 1,
    ]);

    $rows = [[
        'company_name' => 'Redelivered Clinic',
        'contact_name' => 'Dr Redelivered',
        'phone' => '9666666666',
        'city' => 'Pune',
        'healthcare_specialty' => 'Dentist',
        'row_number' => 2,
    ]];

    $job = new ProcessLeadImportJob($import->id, $rows, $this->org->id, $this->rep->id);
    $job->handle(app(LeadImportService::class));
    $job->handle(app(LeadImportService::class));

    expect(Lead::where('company_name', 'Redelivered Clinic')->count())->toBe(1);
});

it('lets the owning organisation poll import status but refuses a different tenant', function () {
    $import = LeadImport::create([
        'organization_id' => $this->org->id,
        'created_by_user_id' => $this->rep->id,
        'status' => LeadImport::STATUS_COMPLETED,
        'total_rows' => 1,
        'imported_count' => 1,
    ]);

    $this->actingAs($this->rep)
        ->getJson(route('company.crm.leads.import.status', $import))
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('result.imported_count', 1);

    $otherOrg = Organization::factory()->create();
    $otherRep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $otherOrg->id,
    ]);
    $otherRep->assignRole('Sales Representative');

    $this->actingAs($otherRep)
        ->getJson(route('company.crm.leads.import.status', $import))
        ->assertNotFound();
});
