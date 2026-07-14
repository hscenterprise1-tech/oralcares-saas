<?php

namespace Database\Seeders;

use App\Models\User;
use App\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MasterAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $name = config('auth.master_admin.name');
        $email = config('auth.master_admin.email');
        $password = config('auth.master_admin.password');

        if (empty($password)) {
            throw new \RuntimeException('MASTER_ADMIN_PASSWORD configuration must be set.');
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'user_type' => UserType::MasterAdmin,
                'email_verified_at' => now(),
            ]);
        } else {
            $user->update([
                'name' => $name,
                'user_type' => UserType::MasterAdmin,
            ]);
        }
    }
}
