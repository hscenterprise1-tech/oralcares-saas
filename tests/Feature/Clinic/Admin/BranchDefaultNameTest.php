<?php

use App\BranchStatus;
use App\Models\Branch;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Services\CustomerProvisioningEngine;
use App\Services\Provisioning\ProvisioningIntent;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * A branch's name is either provisioning's fallback or one somebody chose
 * (`branches.name_is_default`). The Clinic Portal names a single-branch
 * clinic's branch only when it was chosen; renaming makes a name chosen, and
 * editing anything else leaves the answer as it was.
 */
beforeEach(function () {
    $this->f = ReceptionFixture::make('branch-name-clinic');
    $this->f->branch->update(['name' => Branch::DEFAULT_NAME, 'name_is_default' => true]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->activeBranch = function (): array {
        $clinic = [];
        ($this->asOwner)()->get('/clinic/support')->assertOk()->assertInertia(function (Assert $page) use (&$clinic) {
            $clinic = $page->toArray()['props']['clinic'];
        });

        return $clinic;
    };
    $this->save = fn (array $changes) => ($this->asOwner)()->put(route('admin.branches.update', $this->f->branch), $changes + [
        'name' => $this->f->branch->name,
        'slug' => $this->f->branch->slug,
        'status' => $this->f->branch->status->value,
        'is_main' => $this->f->branch->is_main,
    ])->assertRedirect(route('admin.branches.index'));
});

test('a single branch with the fallback name is sent as such, so the sidebar leaves it out', function () {
    $clinic = ($this->activeBranch)();

    expect($clinic['accessible_branch_count'])->toBe(1)
        ->and($clinic['active_branch']['name'])->toBe('Main Branch')
        ->and($clinic['active_branch']['name_is_default'])->toBeTrue();
});

test('renaming the branch makes its name a chosen one', function () {
    ($this->save)(['name' => 'Powai Branch']);

    expect($this->f->branch->fresh())->name->toBe('Powai Branch')->name_is_default->toBeFalse()
        ->and(($this->activeBranch)()['active_branch']['name_is_default'])->toBeFalse();
});

test('editing anything but the name leaves the answer as it was', function () {
    ($this->save)(['phone' => '9876500099', 'city' => 'Mumbai']);

    expect($this->f->branch->fresh())->name->toBe('Main Branch')->name_is_default->toBeTrue()->phone->toBe('9876500099');
});

test('a branch the owner creates carries the name they gave it', function () {
    // Branch limits are read from the subscription; without one, creating a branch is refused.
    CompanySubscription::factory()->create(['clinic_id' => $this->f->clinic->id]);

    ($this->asOwner)()->post(route('admin.branches.store'), [
        'name' => 'Main Branch', 'slug' => 'second-main', 'status' => BranchStatus::Active->value, 'is_main' => false,
    ]);

    expect(Branch::withoutGlobalScopes()->where('slug', 'second-main')->sole()->name_is_default)->toBeFalse();
});

test('with several branches the selected one is sent, and the owner\'s all-branches view has none selected', function () {
    Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id, 'name' => 'Bandra', 'status' => BranchStatus::Active]);

    $clinic = ($this->activeBranch)();
    expect($clinic['accessible_branch_count'])->toBe(2)
        ->and($clinic['active_branch']['name'])->toBe('Main Branch');

    ($this->asOwner)()->post('/clinic/branches/switch', ['branch_id' => null])->assertRedirect();
    $this->get('/clinic/support')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('clinic.active_branch', null)
        ->where('clinic.accessible_branch_count', 2)
        ->where('clinic.is_owner', true));
});

test('provisioning marks a fallback name and keeps a supplied one as chosen', function () {
    $branchOf = fn ($organization) => Branch::withoutOrganizationScope()->where('organization_id', $organization->id)->sole();

    $fallback = $branchOf(app(CustomerProvisioningEngine::class)->provision(Lead::factory()->create(['company_name' => 'Fallback Dental'])));
    expect($fallback)->name->toBe('Main Branch')->name_is_default->toBeTrue();

    $supplied = $branchOf(app(CustomerProvisioningEngine::class)->provision(Lead::factory()->create(['company_name' => 'Supplied Dental']), 'Powai Branch'));
    expect($supplied)->name->toBe('Powai Branch')->name_is_default->toBeFalse();

    // A trial's branch is named after its company only because it has no name of its own.
    $trial = $branchOf(app(CustomerProvisioningEngine::class)->provision(Lead::factory()->create(['company_name' => 'Trial Dental']), 'Trial Dental', ProvisioningIntent::sale(), branchNameIsFallback: true));
    expect($trial)->name->toBe('Trial Dental')->name_is_default->toBeTrue();
});
