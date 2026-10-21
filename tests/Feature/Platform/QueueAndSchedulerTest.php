<?php

use App\Mail\ClinicPasswordResetMailable;
use App\Mail\OwnerInvitationMail;
use App\Mail\StaffInvitationMail;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\User;
use App\Shared\Search\Jobs\IndexDocumentJob;
use App\Shared\Search\Models\SearchDocument;
use App\Shared\Search\SearchProviders\PatientSearchProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Finder\Finder;

/*
 * Invariants for running on more than one server against a real queue.
 */

it('runs every scheduled task on one server only', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events)->not->toBeEmpty();

    $events->each(fn ($event) => expect($event->onOneServer)->toBeTrue("{$event->getSummaryForDisplay()} would run once per app server"));
});

it('never lets a queue hand a running job to a second worker', function () {
    /*
     * A connection whose `retry_after` is shorter than a job's timeout
     * re-delivers that job while it is still running. Redis used the 90s
     * framework default while the import jobs run for up to 600s.
     */
    $declared = collect(Finder::create()->files()->in([app_path('Jobs'), app_path('Shared')])->name('*.php'))
        ->map(fn ($file) => preg_match('/public int \$timeout = (\d+);/', $file->getContents(), $match) ? (int) $match[1] : 0)
        ->max();

    expect($declared)->toBeLessThanOrEqual(config('queue.max_job_timeout'));

    foreach (['database', 'redis'] as $connection) {
        expect(config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan(config('queue.max_job_timeout'))
            ->and(config("queue.connections.{$connection}.after_commit"))->toBeTrue();
    }
});

it('encrypts mail carrying a sign-in link and sends it on the critical queue', function (Closure $mailable) {
    Queue::fake();

    Mail::to('someone@clinic.test')->send($mailable());

    Queue::assertPushedOn(config('queue.queues.critical'), SendQueuedMailable::class, fn (SendQueuedMailable $job) => $job->shouldBeEncrypted);
})->with([
    'owner invitation' => fn () => new OwnerInvitationMail('Dr Owner', 'Smile Clinic', 'https://clinic.test/accept/secret', 7),
    'staff invitation' => fn () => new StaffInvitationMail(
        ClinicEmployee::withoutGlobalScopes()->create(['clinic_id' => Clinic::factory()->create()->id, 'name' => 'New Hire', 'email' => 'hire@clinic.test', 'status' => 'active']),
        'https://clinic.test/invitation/secret',
        'Dr Owner',
    ),
    'password reset' => fn () => new ClinicPasswordResetMailable(User::factory()->create(), 'https://clinic.test/reset/secret'),
]);

it('drops a search index job whose record was deleted before it ran', function () {
    $organization = Organization::factory()->create();
    $patient = Patient::factory()->create(['organization_id' => $organization->id]);
    SearchDocument::where('model_type', Patient::class)->delete();
    $job = new IndexDocumentJob($patient, PatientSearchProvider::class);
    Patient::withoutGlobalScopes()->whereKey($patient->id)->delete();

    dispatch($job);

    expect(SearchDocument::where('model_type', Patient::class)->where('model_id', $patient->id)->exists())->toBeFalse();
});
