<?php

use App\Models\User;
use Database\Seeders\CompanyPeopleFinanceSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * No seeder may create a login with a password anyone could know. The demo
 * HQ seeder is local-only behind an explicit switch and gives its accounts
 * random passwords; every other seeder takes passwords from configuration.
 */
it('refuses to run the demo seeder in production, even with the switch on', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['security.demo_seeding' => true]);

    // Called directly: in production `db:seed` would first ask "are you
    // sure?", and a `--force` run must be refused by the seeder itself.
    expect(fn () => app(CompanyPeopleFinanceSeeder::class)->run())->toThrow(RuntimeException::class, 'local demo data');
    expect(User::count())->toBe(0);
});

it('refuses to run the demo seeder locally unless it is switched on', function () {
    config(['security.demo_seeding' => false]);

    expect(fn () => $this->seed(CompanyPeopleFinanceSeeder::class))->toThrow(RuntimeException::class);
    expect(User::count())->toBe(0);
});

it('gives each demo account its own random password, never a known default', function () {
    config(['security.demo_seeding' => true]);

    $this->seed(CompanyPeopleFinanceSeeder::class);

    $accounts = User::where('user_type', 'company_user')->get();
    expect($accounts)->not->toBeEmpty();

    foreach ($accounts as $account) {
        foreach (['password', 'Password', 'password123', 'secret', 'admin', 'changeme', ''] as $known) {
            expect(Hash::check($known, $account->password))->toBeFalse();
        }
    }

    expect($accounts->pluck('password')->unique())->toHaveCount($accounts->count());
});

it('leaves an existing account and its password untouched when demo data is seeded', function () {
    config(['security.demo_seeding' => true]);
    $this->seed(CompanyPeopleFinanceSeeder::class);
    $existing = User::where('user_type', 'company_user')->first();
    $existing->update(['password' => Hash::make('the-owners-own-password')]);

    $this->seed(CompanyPeopleFinanceSeeder::class);

    expect(Hash::check('the-owners-own-password', $existing->fresh()->password))->toBeTrue();
});

it('has no seeder that hashes or stores a literal password', function () {
    $offenders = collect(glob(database_path('seeders/*.php')))
        ->filter(fn (string $file) => preg_match(
            "/(bcrypt|Hash::make|password_hash)\\(\\s*['\"]|['\"]password['\"]\\s*=>\\s*['\"]/i",
            file_get_contents($file),
        ))
        ->map(fn (string $file) => basename($file))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
