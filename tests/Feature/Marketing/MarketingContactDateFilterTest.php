<?php

use App\Models\MarketingContact;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The "Added between" filter reads its dates in the business timezone.
 *
 * Timestamps are stored in UTC, but the list stamps each row with the day the
 * business saw. Those are not the same day for anything added after 18:30 UTC,
 * which in India is the whole evening. Comparing the picked date against the
 * stored date meant a filter for "the 24th" returned nothing while every row on
 * screen read "24 Aug" — a filter that looks broken to the only people who
 * would ever use it.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
    ]);
    grantMarketingPermissions($this->user);
});

/** A contact added at a given UTC instant. */
function contactAddedAt(User $user, string $name, string $utc): MarketingContact
{
    $contact = MarketingContact::create([
        'organization_id' => $user->organization_id,
        'name' => $name,
        'phone' => '+91 90000 0'.substr(md5($name), 0, 4),
        'normalized_phone' => '9'.substr(preg_replace('/\D/', '', md5($name)).'000000000', 0, 9),
        'marketing_status' => 'active',
    ]);

    $contact->forceFill(['created_at' => Carbon::parse($utc, 'UTC')])->saveQuietly();

    return $contact->fresh();
}

it('matches the day the business saw, not the day UTC stored', function () {
    /*
     * 20:38 UTC on the 23rd is 02:08 IST on the 24th. The row is stamped
     * "24 Aug", so filtering for the 24th has to find it.
     */
    $evening = contactAddedAt($this->user, 'Dr. Late Evening', '2026-08-23 20:38:00');

    $matched = MarketingContact::query()
        ->filterImportedBetween('2026-08-24', '2026-08-24')
        ->pluck('id');

    expect($matched)->toContain($evening->id);

    /* And it must not also answer to the previous business day. */
    $previous = MarketingContact::query()
        ->filterImportedBetween('2026-08-23', '2026-08-23')
        ->pluck('id');

    expect($previous)->not->toContain($evening->id);
});

it('includes the whole of the final day', function () {
    /* 18:20 UTC is 23:50 IST — the last minutes of the business day. */
    $lastMinute = contactAddedAt($this->user, 'Dr. Last Minute', '2026-08-24 18:20:00');

    /* 18:35 UTC is 00:05 IST the next morning, and belongs to the next day. */
    $justAfter = contactAddedAt($this->user, 'Dr. Just After', '2026-08-24 18:35:00');

    $matched = MarketingContact::query()
        ->filterImportedBetween('2026-08-24', '2026-08-24')
        ->pluck('id');

    expect($matched)->toContain($lastMinute->id)
        ->and($matched)->not->toContain($justAfter->id);
});

it('accepts either bound on its own', function () {
    $early = contactAddedAt($this->user, 'Dr. Early', '2026-08-20 06:00:00');
    $late = contactAddedAt($this->user, 'Dr. Late', '2026-08-26 06:00:00');

    $since = MarketingContact::query()
        ->filterImportedBetween('2026-08-25', null)
        ->pluck('id');

    expect($since)->toContain($late->id)
        ->and($since)->not->toContain($early->id);

    $until = MarketingContact::query()
        ->filterImportedBetween(null, '2026-08-21')
        ->pluck('id');

    expect($until)->toContain($early->id)
        ->and($until)->not->toContain($late->id);
});

it('narrows nothing when neither bound is given', function () {
    $one = contactAddedAt($this->user, 'Dr. One', '2026-08-20 06:00:00');
    $two = contactAddedAt($this->user, 'Dr. Two', '2026-08-26 06:00:00');

    $all = MarketingContact::query()
        ->filterImportedBetween(null, null)
        ->pluck('id');

    expect($all)->toContain($one->id)->toContain($two->id);
});

it('honours an organisation timezone that is not the default', function () {
    /*
     * 23:30 UTC on the 24th is 05:00 on the 25th in Kolkata but still 19:30 on
     * the 24th in New York. The same contact therefore belongs to a different
     * business day depending on whose business is asking, which is why the
     * timezone is resolved per organisation rather than assumed.
     */
    $contact = contactAddedAt($this->user, 'Dr. Meridian', '2026-08-24 23:30:00');

    expect(
        MarketingContact::query()
            ->filterImportedBetween('2026-08-25', '2026-08-25')
            ->pluck('id')
    )->toContain($contact->id);

    expect(
        MarketingContact::query()
            ->filterImportedBetween('2026-08-24', '2026-08-24', 'America/New_York')
            ->pluck('id')
    )->toContain($contact->id);
});

it('counts All without the status filter the list is under', function () {
    /*
     * Three contacts in one city, one of them replied. With "Replied" selected
     * the list holds one row — but the All button has to promise three, since
     * three is what pressing it produces. Reading All off the list's own total
     * showed "1" and then returned three rows.
     */
    foreach (['Dr. Quiet One', 'Dr. Quiet Two'] as $name) {
        contactAddedAt($this->user, $name, '2026-08-20 06:00:00')
            ->forceFill(['city' => 'Vadodara'])->saveQuietly();
    }

    $answered = contactAddedAt($this->user, 'Dr. Answered', '2026-08-20 06:00:00');
    $answered->forceFill(['city' => 'Vadodara', 'marketing_status' => 'opted_out'])->saveQuietly();

    $this->actingAs($this->user)
        ->get('/company/marketing/contacts?city=Vadodara&status=opted_out')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('contacts.total', 1)
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('lifecycle.opted_out', 1)
                ->where('lifecycle.never_contacted', 2)
                /* Everyone in Vadodara, regardless of the status filter. */
                ->where('lifecycle.all', 3)
            )
        );
});

it('applies the same dates to the list and to the lifecycle counts', function () {
    contactAddedAt($this->user, 'Dr. In Range', '2026-08-23 20:38:00');
    contactAddedAt($this->user, 'Dr. Out Of Range', '2026-08-20 06:00:00');

    $this->actingAs($this->user)
        ->get('/company/marketing/contacts?imported_from=2026-08-24&imported_to=2026-08-24')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('contacts.total', 1)
            /* The counts are deferred, so they are not in the first response. */
            ->missing('lifecycle')
            ->loadDeferredProps(fn ($reload) => $reload
                /*
                 * The headline count and the list have to be the same
                 * population. A strip reading "2 never contacted" over a single
                 * row is the defect the shared expression exists to prevent —
                 * and the follow-up request must apply the same date bounds.
                 */
                ->where('lifecycle.never_contacted', 1)
            )
        );
});
