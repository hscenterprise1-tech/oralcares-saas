<?php

use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
use App\UserType;
use Illuminate\Support\Facades\Broadcast;

/*
 * A clinic's private channels carry that clinic's live events. Only an active
 * member of an active clinic may subscribe; both callbacks used to return true
 * for any signed-in user.
 */

function clinicChannel(string $pattern): Closure
{
    return Broadcast::driver()->getChannels()->get($pattern);
}

function broadcastMember(Clinic $clinic, ClinicMembershipStatus $status = ClinicMembershipStatus::Active): User
{
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    ClinicMembership::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $user->id, 'status' => $status]);

    return $user;
}

it('lets an active member listen to their clinic', function () {
    $clinic = Clinic::factory()->create();
    $member = broadcastMember($clinic);

    expect(clinicChannel('clinic.{clinicId}')($member, $clinic->id))->toBeTrue()
        ->and(clinicChannel('clinic.{clinicId}.user.{userId}')($member, $clinic->id, $member->id))->toBeTrue();
});

it('refuses a member of another clinic', function () {
    $clinic = Clinic::factory()->create();
    $other = Clinic::factory()->create();
    $outsider = broadcastMember($other);

    expect(clinicChannel('clinic.{clinicId}')($outsider, $clinic->id))->toBeFalse()
        ->and(clinicChannel('clinic.{clinicId}.user.{userId}')($outsider, $clinic->id, $outsider->id))->toBeFalse();
});

it('refuses another member\'s personal channel', function () {
    $clinic = Clinic::factory()->create();
    $member = broadcastMember($clinic);
    $colleague = broadcastMember($clinic);

    expect(clinicChannel('clinic.{clinicId}.user.{userId}')($member, $clinic->id, $colleague->id))->toBeFalse();
});

it('refuses a suspended membership and a suspended clinic', function () {
    $clinic = Clinic::factory()->create();
    $suspendedMember = broadcastMember($clinic, ClinicMembershipStatus::Suspended);
    $suspendedClinic = Clinic::factory()->create(['status' => ClinicStatus::Suspended]);
    $memberOfSuspended = broadcastMember($suspendedClinic);

    expect(clinicChannel('clinic.{clinicId}')($suspendedMember, $clinic->id))->toBeFalse()
        ->and(clinicChannel('clinic.{clinicId}')($memberOfSuspended, $suspendedClinic->id))->toBeFalse();
});

it('refuses head office', function (UserType $type) {
    $clinic = Clinic::factory()->create();
    $user = User::factory()->create(['user_type' => $type]);

    expect(clinicChannel('clinic.{clinicId}')($user, $clinic->id))->toBeFalse();
})->with([
    'company user' => UserType::CompanyUser,
    'master admin' => UserType::MasterAdmin,
]);
