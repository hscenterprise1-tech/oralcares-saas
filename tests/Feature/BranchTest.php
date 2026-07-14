<?php

use App\BranchStatus;
use App\Models\Branch;
use App\Models\Clinic;
use Illuminate\Database\QueryException;

test('a branch can be created for a clinic', function () {
    $clinic = Clinic::factory()->create();

    $branch = Branch::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'Main Branch',
        'slug' => 'main',
    ]);

    expect($branch)->not->toBeNull()
        ->and($branch->clinic_id)->toBe($clinic->id)
        ->and($branch->name)->toBe('Main Branch')
        ->and($branch->slug)->toBe('main');
});

test('a clinic can access its branches', function () {
    $clinic = Clinic::factory()->create();
    $branch1 = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $branch2 = Branch::factory()->create(['clinic_id' => $clinic->id]);

    expect($clinic->branches)->toHaveCount(2)
        ->and($clinic->branches->pluck('id'))->toContain($branch1->id, $branch2->id);
});

test('a branch can access its clinic', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);

    expect($branch->clinic->id)->toBe($clinic->id);
});

test('branch status is correctly cast to the enum', function () {
    $activeBranch = Branch::factory()->create(['status' => BranchStatus::Active]);
    $inactiveBranch = Branch::factory()->inactive()->create();
    $suspendedBranch = Branch::factory()->suspended()->create();

    expect($activeBranch->status)->toBe(BranchStatus::Active)
        ->and($inactiveBranch->status)->toBe(BranchStatus::Inactive)
        ->and($suspendedBranch->status)->toBe(BranchStatus::Suspended);
});

test('default status is active and is_main is false by default', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::create([
        'clinic_id' => $clinic->id,
        'name' => 'City Branch',
        'slug' => 'city',
    ]);

    $branch->refresh();

    expect($branch->status)->toBe(BranchStatus::Active)
        ->and($branch->is_main)->toBeFalse();
});

test('is_main is correctly cast to boolean', function () {
    $clinic = Clinic::factory()->create();
    $mainBranch = Branch::factory()->main()->create(['clinic_id' => $clinic->id]);
    $subBranch = Branch::factory()->create(['clinic_id' => $clinic->id, 'is_main' => false]);

    expect($mainBranch->is_main)->toBeTrue()
        ->and($subBranch->is_main)->toBeFalse();
});

test('the same branch slug can exist in different clinics', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $branchA = Branch::factory()->create(['clinic_id' => $clinicA->id, 'slug' => 'main']);
    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id, 'slug' => 'main']);

    expect($branchA)->not->toBeNull()
        ->and($branchB)->not->toBeNull();
});

test('duplicate branch slugs within the same clinic are rejected by the database', function () {
    $clinic = Clinic::factory()->create();
    Branch::factory()->create(['clinic_id' => $clinic->id, 'slug' => 'main']);

    $this->expectException(QueryException::class);

    Branch::factory()->create(['clinic_id' => $clinic->id, 'slug' => 'main']);
});
