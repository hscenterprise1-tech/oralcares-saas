<?php

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase 2, item 9 — the sources Phase 4's trial-health metrics need.
 *
 * No metric is computed here. The point is that the raw facts start being
 * recorded now, so that when the trial dashboard is built it reports something
 * measured rather than something inferred.
 */
it('records a login so trial activity can be measured at all', function () {
    /*
     * `login_histories` existed with the right columns and no writer, so it
     * held nothing — which is why "has this trial customer actually logged in?"
     * could not be answered.
     */
    $user = User::factory()->create();

    event(new Login('web', $user, false));

    $row = LoginHistory::where('user_id', $user->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('success')
        ->and($row->login_time)->not->toBeNull();
});

it('does not stop a login when telemetry cannot be written', function () {
    /*
     * A person signing in must never be blocked by a bookkeeping failure, so
     * the listener swallows its own errors.
     */
    $user = User::factory()->create();
    Schema::drop('login_histories');

    event(new Login('web', $user, false));
})->throwsNoExceptions();

it('can measure storage across every attachment table', function () {
    /*
     * Two of the five carried no size column, so any storage figure would have
     * silently omitted clinical and lab files — wrong in the direction that
     * matters when the number is compared against a sold allowance.
     */
    $tables = ['attached_documents', 'crm_documents', 'xray_attachments', 'clinical_attachments', 'lab_order_attachments'];

    foreach ($tables as $table) {
        expect(Schema::hasColumn($table, 'file_size_bytes'))
            ->toBeTrue("{$table} cannot report its size");
    }
});

it('keeps sizes nullable so an unmeasured file is not reported as empty', function () {
    /* An unknown size and a zero-byte file are not the same claim. */
    $column = collect(Schema::getColumns('clinical_attachments'))
        ->firstWhere('name', 'file_size_bytes');

    expect($column['nullable'])->toBeTrue();
});
