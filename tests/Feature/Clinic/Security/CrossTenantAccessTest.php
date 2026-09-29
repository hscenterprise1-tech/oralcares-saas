<?php

use App\ClinicMembershipStatus;
use App\Models\Appointment;
use App\Models\AttachedDocument;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicDepartment;
use App\Models\ClinicEmployee;
use App\Models\ClinicEmployeeInvitation;
use App\Models\ClinicMembership;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\ClinicRoom;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Deliberate cross-tenant ID manipulation, through HTTP, against every routed
 * clinic endpoint that takes a record id.
 *
 * The attacker is the primary owner of Clinic A, the account that passes every
 * permission check. Anything they cannot reach in Clinic B is therefore held
 * back by tenancy, not by a missing permission. Each request must be refused
 * (403/404), and nothing in Clinic B may change.
 */

/**
 * @return array{user: User, clinic: Clinic, branch: Branch, membership: ClinicMembership}
 */
function tenantWithOwner(string $slug): array
{
    $organization = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id, 'slug' => $slug]);
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $organization->id]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $organization->id]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
        'is_primary_owner' => true,
    ]);

    return compact('user', 'clinic', 'branch', 'membership');
}

/**
 * Everything a clinic holds that an id in a URL can point at.
 *
 * @return array<string, mixed>
 */
function tenantRecords(Clinic $clinic, Branch $branch, User $owner): array
{
    $patient = Patient::withoutGlobalScopes()->create([
        'clinic_id' => $clinic->id,
        'organization_id' => $clinic->organization_id,
        'uhid' => 'UH-'.Str::upper(Str::random(8)),
        'first_name' => 'Record',
        'last_name' => 'Owner',
        'name' => 'Record Owner',
        'phone' => '9'.random_int(100000000, 999999999),
        'gender' => 'female',
    ]);

    $appointment = Appointment::withoutGlobalScopes()->create([
        'organization_id' => $clinic->organization_id,
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'scheduled_at' => now()->addDay(),
        'duration_minutes' => 15,
        'status' => 'scheduled',
    ]);

    $queue = ClinicQueue::withoutGlobalScopes()->create(['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'name' => 'Main', 'queue_date' => today()]);
    $queueItem = ClinicQueueItem::create(['clinic_queue_id' => $queue->id, 'position' => 1]);

    $employee = ClinicEmployee::withoutGlobalScopes()->create(['clinic_id' => $clinic->id, 'name' => 'Staff Member', 'email' => Str::random(8).'@clinic.test', 'status' => 'active']);
    $invitation = ClinicEmployeeInvitation::withoutGlobalScopes()->create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'email' => $employee->email,
        'token' => hash('sha256', Str::random(40)),
        'expires_at' => now()->addDays(3),
        'created_by' => $owner->id,
    ]);

    $department = ClinicDepartment::withoutGlobalScopes()->create(['clinic_id' => $clinic->id, 'name' => 'Ortho', 'key' => 'ortho-'.Str::random(4), 'is_active' => true]);
    $room = ClinicRoom::create(['branch_id' => $branch->id, 'name' => 'Room 1', 'key' => 'room-'.Str::random(4), 'type' => 'consultation', 'is_active' => true]);

    Storage::disk('private')->put("clinic_{$clinic->id}/patients/{$patient->id}/documents/scan.pdf", '%PDF-1.4 secret');
    $document = AttachedDocument::withoutGlobalScopes()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'file_name' => 'scan.pdf',
        'file_path' => "clinic_{$clinic->id}/patients/{$patient->id}/documents/scan.pdf",
        'file_size_bytes' => 15,
        'document_type' => 'xray',
        // Downloads are served only for documents that passed a malware scan.
        'scan_status' => AttachedDocument::SCAN_CLEAN,
    ]);

    return compact('patient', 'appointment', 'queueItem', 'employee', 'invitation', 'department', 'room', 'document');
}

beforeEach(function () {
    Storage::fake('private');

    $this->a = tenantWithOwner('alpha-dental');
    $this->b = tenantWithOwner('beta-dental');
    $this->aRecords = tenantRecords($this->a['clinic'], $this->a['branch'], $this->a['user']);
    $this->bRecords = tenantRecords($this->b['clinic'], $this->b['branch'], $this->b['user']);

    // Fixtures are built across both clinics first; only then is the tenant
    // scope switched on, so the requests below run under real-request rules.
    app()->instance('enforce_tenant_scope_in_tests', true);
});

function asClinicA($test)
{
    return $test->actingAs($test->a['user'])->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $test->a['membership']->id,
        ClinicContext::SESSION_BRANCH_ID => $test->a['branch']->id,
    ]);
}

it('refuses every id-bearing clinic endpoint when the id belongs to another clinic', function () {
    $b = $this->bRecords;
    $patient = $b['patient']->id;

    $requests = [
        ['GET', "/clinic/patients/{$patient}"],
        ['GET', "/clinic/patients/{$patient}/edit"],
        ['GET', "/clinic/patients/{$patient}/timeline"],
        ['GET', "/clinic/patients/{$patient}/billing"],
        ['GET', "/clinic/patients/{$patient}/clinical"],
        ['PUT', "/clinic/patients/{$patient}", ['first_name' => 'Hacked', 'last_name' => 'Name', 'phone' => '9000000000', 'gender' => 'male']],
        ['POST', "/clinic/patients/{$patient}/transfer", ['to_practitioner_id' => $b['employee']->id]],
        ['GET', "/clinic/patients/{$patient}/documents/{$b['document']->id}"],
        ['PATCH', "/clinic/appointments/{$b['appointment']->id}/status", ['status' => 'cancelled']],
        ['PATCH', "/clinic/reception/queue/{$b['queueItem']->id}/status", ['status' => 'cancelled']],
        ['PATCH', "/clinic/reception/queue/{$b['queueItem']->id}/priority", ['priority' => 'emergency']],
        ['GET', "/clinic/admin/employees/{$b['employee']->id}/edit"],
        ['PUT', "/clinic/admin/employees/{$b['employee']->id}", ['name' => 'Hacked', 'email' => 'hacked@clinic.test', 'status' => 'active']],
        ['DELETE', "/clinic/admin/employees/{$b['employee']->id}"],
        ['POST', "/clinic/admin/invitations/{$b['invitation']->id}/revoke"],
        ['PUT', "/clinic/admin/branches/{$this->b['branch']->id}", ['name' => 'Hacked Branch']],
        ['DELETE', "/clinic/admin/branches/{$this->b['branch']->id}"],
        ['PUT', "/clinic/admin/departments/{$b['department']->id}", ['name' => 'Hacked']],
        ['DELETE', "/clinic/admin/departments/{$b['department']->id}"],
        ['PUT', "/clinic/admin/rooms/{$b['room']->id}", ['name' => 'Hacked', 'branch_id' => $this->b['branch']->id, 'type' => 'consultation']],
        ['DELETE', "/clinic/admin/rooms/{$b['room']->id}"],
    ];

    $leaks = [];
    foreach ($requests as $request) {
        [$method, $uri] = $request;
        $status = asClinicA($this)->json($method, $uri, $request[2] ?? [])->status();

        if (! in_array($status, [403, 404], true)) {
            $leaks[] = "{$method} {$uri} answered {$status}";
        }
    }

    expect($leaks)->toBe([]);
});

it('changes nothing in the other clinic after a manipulation attempt', function () {
    $b = $this->bRecords;

    asClinicA($this)->putJson("/clinic/patients/{$b['patient']->id}", ['first_name' => 'Hacked', 'last_name' => 'Name', 'phone' => '9000000000', 'gender' => 'male']);
    asClinicA($this)->patchJson("/clinic/appointments/{$b['appointment']->id}/status", ['status' => 'cancelled']);
    asClinicA($this)->patchJson("/clinic/reception/queue/{$b['queueItem']->id}/status", ['status' => 'cancelled']);
    asClinicA($this)->deleteJson("/clinic/admin/employees/{$b['employee']->id}");
    asClinicA($this)->postJson("/clinic/admin/invitations/{$b['invitation']->id}/revoke");
    asClinicA($this)->deleteJson("/clinic/admin/branches/{$this->b['branch']->id}");
    asClinicA($this)->deleteJson("/clinic/admin/departments/{$b['department']->id}");
    asClinicA($this)->deleteJson("/clinic/admin/rooms/{$b['room']->id}");

    app()->forgetInstance('enforce_tenant_scope_in_tests');

    expect(Patient::withoutGlobalScopes()->find($b['patient']->id)->first_name)->toBe('Record')
        ->and(Appointment::withoutGlobalScopes()->find($b['appointment']->id)->status->value ?? Appointment::withoutGlobalScopes()->find($b['appointment']->id)->status)->toBe('scheduled')
        ->and(ClinicEmployee::withoutGlobalScopes()->whereKey($b['employee']->id)->exists())->toBeTrue()
        ->and(ClinicEmployeeInvitation::withoutGlobalScopes()->find($b['invitation']->id)->revoked_at)->toBeNull()
        ->and(Branch::withoutGlobalScopes()->whereKey($this->b['branch']->id)->exists())->toBeTrue()
        ->and(ClinicDepartment::withoutGlobalScopes()->whereKey($b['department']->id)->exists())->toBeTrue()
        ->and(ClinicRoom::whereKey($b['room']->id)->exists())->toBeTrue();
});

it('does not let one patient\'s document be fetched through another patient in the same clinic', function () {
    $ownPatient = $this->aRecords['patient'];
    $otherPatient = Patient::withoutGlobalScopes()->create([
        'clinic_id' => $this->a['clinic']->id,
        'organization_id' => $this->a['clinic']->organization_id,
        'uhid' => 'UH-'.Str::upper(Str::random(8)),
        'first_name' => 'Second',
        'last_name' => 'Patient',
        'name' => 'Second Patient',
        'phone' => '9111111111',
        'gender' => 'male',
    ]);

    asClinicA($this)
        ->get("/clinic/patients/{$otherPatient->id}/documents/{$this->aRecords['document']->id}")
        ->assertNotFound();

    asClinicA($this)
        ->get("/clinic/patients/{$ownPatient->id}/documents/{$this->aRecords['document']->id}")
        ->assertOk();
});

it('refuses a document download without a signed-in clinic session, and audits a real one', function () {
    $document = $this->aRecords['document'];
    $patient = $this->aRecords['patient'];

    // A guest never reaches the file. The tenant scope finds no patient for
    // a request with no clinic session (404), before the portal's own
    // sign-in redirect would apply.
    $guest = $this->get("/clinic/patients/{$patient->id}/documents/{$document->id}");
    expect($guest->status())->toBeIn([302, 404])
        ->and($guest->baseResponse)->not->toBeInstanceOf(StreamedResponse::class);

    asClinicA($this)->get("/clinic/patients/{$patient->id}/documents/{$document->id}")->assertOk();

    $this->assertDatabaseHas('security_audit_logs', [
        'event' => 'document.downloaded',
        'actor_user_id' => $this->a['user']->id,
        'clinic_id' => $this->a['clinic']->id,
        'subject_id' => $document->id,
    ]);
});

it('does not switch the session into another clinic\'s branch', function () {
    asClinicA($this)->post('/clinic/branches/switch', ['branch_id' => $this->b['branch']->id]);

    expect(session(ClinicContext::SESSION_BRANCH_ID))->not->toBe($this->b['branch']->id);
});

it('does not book an appointment for another clinic\'s patient', function () {
    $before = Appointment::withoutGlobalScopes()->where('patient_id', $this->bRecords['patient']->id)->count();

    asClinicA($this)->postJson('/clinic/appointments', [
        'patient_id' => $this->bRecords['patient']->id,
        'scheduled_at' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
        'duration_minutes' => 15,
    ]);

    expect(Appointment::withoutGlobalScopes()->where('patient_id', $this->bRecords['patient']->id)->count())->toBe($before);
});
