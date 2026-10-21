<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicOperatingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * `queue:close-day` runs every 15 minutes across every tenant. Checking
 * branches whose day is still running must cost the same few queries however
 * many tenants have bookings; it used to load every awaiting booking of every
 * clinic and look each branch up one at a time.
 */

function openBranchesWithBookingsToday(int $count): void
{
    $clinic = Clinic::factory()->create();

    for ($i = 0; $i < $count; $i++) {
        $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);
        ClinicOperatingHour::create(['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'day_of_week' => 1,
            'open_time' => '09:00:00', 'close_time' => '21:00:00', 'is_closed' => false]);
        DB::table('appointments')->insert([
            'organization_id' => $clinic->organization_id, 'clinic_id' => $clinic->id, 'branch_id' => $branch->id,
            'scheduled_at' => '2026-09-28 18:00:00', 'duration_minutes' => 15, 'status' => 'scheduled',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function queriesToCloseDay(): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->artisan('queue:close-day')->assertSuccessful();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

it('checks open branches in a constant number of queries', function () {
    // 2026-09-28 is a Monday; at noon every branch is still open.
    $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00', 'Asia/Kolkata'));

    openBranchesWithBookingsToday(3);
    $few = queriesToCloseDay();

    openBranchesWithBookingsToday(40);
    $many = queriesToCloseDay();

    expect($many)->toBe($few)
        ->and(DB::table('appointments')->where('status', 'no_show')->count())->toBe(0);
});
