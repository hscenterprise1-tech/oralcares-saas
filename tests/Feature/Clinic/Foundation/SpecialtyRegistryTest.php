<?php

use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Organization;
use App\Models\User;
use App\Services\ClinicContext;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\Specialty\SpecialtyRegistry;
use App\UserType;

/*
 * Specialty behaviour is configuration (`config/clinic_specialties.php`):
 * core code asks for an extension or a flag, never a specialty name.
 */

it('normalises sold and legacy specialty names to registry keys', function () {
    expect(app(SpecialtyRegistry::class)->normalize(['Dental', 'general_hospital', 'physio', 'unknown', 'dental', '']))
        ->toBe(['dental', 'general_medicine', 'physiotherapy']);
});

it('gives a branch its own specialties, else its clinic\'s, else the default', function () {
    $registry = app(SpecialtyRegistry::class);
    $clinic = Clinic::factory()->create(['specialties' => ['dental']]);
    $own = Branch::factory()->create(['clinic_id' => $clinic->id, 'specialties' => ['physiotherapy']]);
    $inherits = Branch::factory()->create(['clinic_id' => $clinic->id, 'specialties' => null]);
    $bare = Branch::factory()->create(['clinic_id' => Clinic::factory()->create(['specialties' => null])->id, 'specialties' => null]);

    expect($registry->forBranch($own))->toBe(['physiotherapy'])
        ->and($registry->forBranch($inherits))->toBe(['dental'])
        ->and($registry->forBranch($bare))->toBe(['general_medicine']);
});

it('makes a multi-specialty branch the union of its specialties\' extensions', function () {
    expect(app(SpecialtyRegistry::class)->extensionsFor(['dental', 'physiotherapy', 'multi_specialty']))
        ->toBe(['odontogram', 'dental_lab', 'dental_imaging', 'physio_assessment', 'soap_notes', 'exercise_plan', 'session_packages']);
});

it('withholds an extension whose plan capability is missing', function () {
    expect(app(SpecialtyRegistry::class)->availableExtensions(['dental'], ['xray']))
        ->toBe(['odontogram', 'dental_imaging']);
});

it('resolves extensions and case registration for the branch being worked in', function (array $specialties, array $expectedExtensions, bool $registersCases) {
    $clinic = Clinic::factory()->create(['organization_id' => Organization::factory()->create()->id, 'specialties' => ['dental']]);
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id, 'specialties' => $specialties]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active,
        'is_primary_owner' => true, 'has_all_branches_access' => true,
    ]);
    $this->actingAs($user);
    $context = app(ClinicContext::class);
    $context->setUser($user);
    $context->setMembership($membership);
    $context->setBranch($branch);

    $access = app(ClinicAccess::class);

    expect($access->extensions())->toBe($expectedExtensions)
        ->and($access->allows(['specialty_flag' => 'case_registration']))->toBe($registersCases)
        ->and($access->allows(['extensions' => ['odontogram']]))->toBe(in_array('odontogram', $expectedExtensions, true));
})->with([
    'dental branch (no plan: lab and imaging stay off)' => [['dental'], ['odontogram'], true],
    'physiotherapy branch of a dental clinic' => [['physiotherapy'], ['physio_assessment', 'soap_notes', 'exercise_plan', 'session_packages'], false],
    'general medicine branch' => [['general_medicine'], [], true],
]);

it('keeps specialty names out of core code paths', function () {
    $offenders = collect(['app/Services/BillingService.php', 'app/Services/PaymentService.php', 'app/Support/ClinicActions.php', 'app/Support/Dashboard/DashboardData.php'])
        ->filter(fn (string $path) => preg_match("/['\"](dental|physiotherapy|orthodontics)['\"]/", file_get_contents(base_path($path))));

    expect($offenders->all())->toBe([]);
});
