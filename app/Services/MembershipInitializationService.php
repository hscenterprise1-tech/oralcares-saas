<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
use App\UserType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MembershipInitializationService
{
    public function __construct(
        protected AssignPrimaryOwnerService $assignPrimaryOwnerService
    ) {}

    /**
     * Safely initialize/create the owner user and assign clinic membership.
     */
    public function initializeOwnerUserAndMembership(Clinic $clinic, array $ownerData): ClinicMembership
    {
        $user = User::where('email', $ownerData['email'])->first();

        if (! $user) {
            $user = User::create([
                'name' => $ownerData['name'],
                'email' => $ownerData['email'],
                'password' => Hash::make(Str::random(16)),
                'user_type' => UserType::ClinicUser,
            ]);
        }

        return $this->assignPrimaryOwnerService->execute($clinic, $user);
    }
}
