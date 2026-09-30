<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\CaseNumberSequence;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSettingAudit;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\CaseNumberGenerator;
use App\UserType;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function seedRoleForCaseNumberSettings(ClinicRole $role): void
{
    setPermissionsTeamId(null);

    $seeded = Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null]);
    $seeded->syncPermissions(collect($role->permissions())
        ->map(fn (string $perm) => Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']))
        ->all());
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function caseNumberSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'prefix' => 'OPD',
        'show_year' => true,
        'year_style' => 'YYYY',
        'separator' => '-',
        'digits' => 6,
        'start_number' => 1,
        'reset_yearly' => true,
    ], $overrides);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 11:00', 'Asia/Kolkata'));

    $this->clinic = Clinic::factory()->create(['slug' => 'case-number-settings']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id, 'is_main' => true, 'status' => BranchStatus::Active,
    ]);

    $this->owner = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $this->owner->id, 'is_primary_owner' => true,
        'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);

    $this->session = [
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ];
});

function saveCaseNumberSettings(array $overrides = [])
{
    $test = test();

    return $test->actingAs($test->owner)->withSession($test->session)
        ->put('/clinic/admin/settings/case-number', caseNumberSettingsPayload($overrides));
}

test('an admin changes the prefix and the next number uses it', function () {
    saveCaseNumberSettings(['prefix' => 'dc', 'separator' => '/', 'digits' => 5])->assertSessionHasNoErrors();

    $number = app(CaseNumberGenerator::class)->forPatient($this->clinic->fresh(), Patient::factory()->create(['clinic_id' => $this->clinic->id]));

    expect($number->case_number)->toBe('DC/2026/00001');
});

test('every change is audited with who made it, the old values and the new', function () {
    saveCaseNumberSettings(['prefix' => 'DC']);

    $audit = ClinicSettingAudit::sole();

    expect($audit->user_id)->toBe($this->owner->id)
        ->and($audit->section)->toBe('case_number')
        ->and($audit->old_values['active']['prefix'])->toBe('OPD')
        ->and($audit->new_values['active']['prefix'])->toBe('DC');
});

test('saving without changes writes no audit row', function () {
    saveCaseNumberSettings();

    expect(ClinicSettingAudit::count())->toBe(0);
});

test('a year-style change waits for the next period', function () {
    saveCaseNumberSettings(['year_style' => 'FY_SHORT', 'separator' => '/'])->assertSessionHasNoErrors();

    $config = $this->clinic->fresh()->case_number_config;
    $generator = app(CaseNumberGenerator::class);
    $now = $generator->forPatient($this->clinic->fresh(), Patient::factory()->create(['clinic_id' => $this->clinic->id]));
    $later = $generator->forPatient($this->clinic->fresh(), Patient::factory()->create(['clinic_id' => $this->clinic->id]), CarbonImmutable::create(2027, 1, 2));

    expect($config['active']['year_style'])->toBe('YYYY')
        ->and($config['pending']['effective_from'])->toBe('2027-01-01')
        ->and($now->case_number)->toBe('OPD/2026/000001')
        ->and($later->case_number)->toBe('OPD/26-27/000001');
});

test('the settings page shows the format, the pending change and the recent audit trail', function () {
    saveCaseNumberSettings(['reset_yearly' => false]);

    $this->actingAs($this->owner)->withSession($this->session)->get('/clinic/admin/settings')
        ->assertInertia(fn ($page) => $page
            ->where('caseNumber.active.prefix', 'OPD')
            ->where('caseNumber.pending.reset_yearly', false)
            ->where('caseNumber.pending.effective_from', '2027-01-01')
            ->where('caseNumber.next_period_start', '2027-01-01')
            ->has('caseNumber.recent_changes', 1)
        );
});

test('the prefix must be 1 to 10 letters or numbers', function (string $prefix) {
    saveCaseNumberSettings(['prefix' => $prefix])
        ->assertSessionHasErrors(['prefix' => 'The prefix must be 1 to 10 letters or numbers, with no spaces or symbols.']);
})->with(['OPD-1', 'ABCDEFGHIJK', 'OP D']);

test('the number part must be 4 to 8 digits', function (int $digits) {
    saveCaseNumberSettings(['digits' => $digits])
        ->assertSessionHasErrors(['digits' => 'The number part must be between 4 and 8 digits long.']);
})->with([3, 9]);

test('hiding the year while restarting yearly is refused', function () {
    saveCaseNumberSettings(['show_year' => false, 'reset_yearly' => true])
        ->assertSessionHasErrors(['reset_yearly' => 'Turn off the yearly restart when the year is hidden, otherwise the same number would repeat every year.']);
});

test('the start number cannot go at or below a number already issued', function () {
    $generator = app(CaseNumberGenerator::class);
    foreach (range(1, 3) as $ignored) {
        $generator->forPatient($this->clinic->fresh(), Patient::factory()->create(['clinic_id' => $this->clinic->id]));
    }

    saveCaseNumberSettings(['start_number' => 3])
        ->assertSessionHasErrors(['start_number' => 'The start number must be higher than 3, the highest number already issued.']);
});

test('raising the start number makes the next number jump to it', function () {
    app(CaseNumberGenerator::class)->forPatient($this->clinic->fresh(), Patient::factory()->create(['clinic_id' => $this->clinic->id]));

    saveCaseNumberSettings(['start_number' => 1000])->assertSessionHasNoErrors();
    $next = app(CaseNumberGenerator::class)->forPatient($this->clinic->fresh(), Patient::factory()->create(['clinic_id' => $this->clinic->id]));

    expect($next->case_number)->toBe('OPD-2026-001000')
        ->and(CaseNumberSequence::where('clinic_id', $this->clinic->id)->value('last_sequence'))->toBe(1000);
});

test('staff without settings permission cannot see or change settings', function () {
    seedRoleForCaseNumberSettings(ClinicRole::Receptionist);
    $receptionist = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $receptionist->id, 'is_primary_owner' => false,
        'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);
    app(ClinicRoleAssignmentService::class)->syncRoles($receptionist, [ClinicRole::Receptionist->value], [$this->branch]);
    $session = [
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ];

    $this->actingAs($receptionist)->withSession($session)->get('/clinic/admin/settings')->assertForbidden();
    $this->actingAs($receptionist)->withSession($session)
        ->put('/clinic/admin/settings/case-number', caseNumberSettingsPayload(['prefix' => 'HACK']))
        ->assertForbidden();

    expect($this->clinic->fresh()->case_number_config)->toBeNull();
});
