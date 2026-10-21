<?php

use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\User;
use App\Services\ClinicContext;
use Database\Seeders\IntakeModulesBrowserTestSeeder;

beforeEach(function () {
    foreach ([['general_dentistry', 'dental'], ['physiotherapy', 'rehabilitation']] as [$key, $category]) {
        HealthcareSpecialtyMaster::query()->firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'category' => $category, 'category_label' => str($category)->headline(), 'is_active' => true]);
    }
    $this->asMember = function (string $email) {
        $user = User::where('email', $email)->firstOrFail();
        $membership = ClinicMembership::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $branchId = Clinic::withoutGlobalScopes()->findOrFail($membership->clinic_id)->branches()->withoutGlobalScopes()->value('id');

        return $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $branchId]);
    };
});

test('the intake QA seeder is idempotent and creates no patients or bills', function () {
    $this->seed(IntakeModulesBrowserTestSeeder::class);
    $counts = [Clinic::withoutGlobalScopes()->count(), User::count(), ClinicEmployee::withoutGlobalScopes()->count(), ClinicMembership::withoutGlobalScopes()->count()];
    $this->seed(IntakeModulesBrowserTestSeeder::class);

    expect([Clinic::withoutGlobalScopes()->count(), User::count(), ClinicEmployee::withoutGlobalScopes()->count(), ClinicMembership::withoutGlobalScopes()->count()])->toBe($counts)
        ->and(Patient::withoutGlobalScopes()->count())->toBe(0)
        ->and(PatientBilling::withoutGlobalScopes()->count())->toBe(0)
        ->and(User::where('email', 'not like', '%@browser-test.test')->count())->toBe(0);
});

test('the Physiotherapy QA clinic offers only Physiotherapy; the combined one both, with the fee it will charge', function () {
    $this->seed(IntakeModulesBrowserTestSeeder::class);

    $physio = ($this->asMember)('intake-physio-reception@browser-test.test')->getJson('/clinic/reception/intake')->assertOk()->json();
    expect(collect($physio['specialties'])->pluck('key')->all())->toBe(['physiotherapy'])
        ->and(collect($physio['doctors'])->pluck('name')->sort()->values()->all())->toBe(['Dr. Arjun Menon (QA, no login)', 'Dr. Neha Shah (QA)'])
        ->and($physio['check_in']['payment'])->toBe('after_consultation');

    $combined = ($this->asMember)('intake-combined-reception@browser-test.test')->getJson('/clinic/reception/intake')->assertOk()->json();
    expect(collect($combined['specialties'])->pluck('key')->sort()->values()->all())->toBe(['dental', 'physiotherapy'])
        ->and(collect($combined['doctors'])->firstWhere('name', 'Dr. Kavya Iyer (QA)')['fee'])->toEqual(500)
        ->and($combined['check_in']['payment'])->toBe('at_check_in');
});

test('the intake QA seeder refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new IntakeModulesBrowserTestSeeder)->run())->toThrow(RuntimeException::class, 'local or testing');
    expect(Clinic::withoutGlobalScopes()->where('slug', 'like', 'qa-intake-%')->count())->toBe(0);
});
