<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Every form on Deal Detail, against the endpoint it posts to.
 *
 * Three bugs of the same shape were found here by reading the code rather than
 * by anything failing: a field name that did not match, on a form that
 * typechecked perfectly and silently did the wrong thing. None of them threw;
 * each just quietly discarded what the user typed.
 *
 * These tests assert the payloads the forms actually send, so a rename on
 * either side breaks a test instead of a customer's data.
 */
beforeEach(function () {
    $this->provider = Organization::factory()->provider()->create();

    $this->admin = User::factory()->create([
        'user_type' => UserType::MasterAdmin,
        'organization_id' => $this->provider->id,
    ]);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->provider->id,
        'status' => LeadStatus::Negotiation,
    ]);

    $this->actingAs($this->admin);
});

it('keeps the due date the user picked on a scheduled task', function () {
    /*
     * The form sent `due_date` and the endpoint validated `due_at`, so the
     * chosen date was dropped and every task silently defaulted to two days
     * out. Picking a date and being ignored is worse than having no date field.
     */
    $due = now()->addDays(9)->startOfMinute();

    $this->post(route('company.crm.leads.tasks.store', $this->lead), [
        'title' => 'Call back about pricing',
        'type' => 'follow_up',
        'due_at' => $due->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    expect($this->lead->tasks()->first()->due_at->startOfMinute()->eq($due))->toBeTrue();
});

it('accepts an uploaded document under the name the form sends', function () {
    // The form sent `file`; the endpoint required `document`. Every upload
    // failed with "The document field is required" and nothing said why.
    Storage::fake('local');

    $this->post(route('company.crm.leads.documents.store', $this->lead), [
        'document' => UploadedFile::fake()->create('agreement.pdf', 40),
    ])->assertSessionHasNoErrors();

    expect($this->lead->documents()->count())->toBe(1);
});

it('files a document under the title the user gave it', function () {
    // The title box existed and the endpoint ignored it, so everything was
    // filed under whatever the file happened to be called on someone's laptop.
    Storage::fake('local');

    $this->post(route('company.crm.leads.documents.store', $this->lead), [
        'document' => UploadedFile::fake()->create('scan_0042_final_v3.pdf', 40),
        'title' => 'Signed agreement',
    ])->assertSessionHasNoErrors();

    expect($this->lead->documents()->first()->file_name)->toBe('Signed agreement');
});

it('records who the deal was lost to and when to try again', function () {
    /*
     * The Mark Lost form collected a competitor and a win-back date and the
     * endpoint validated neither, so both were discarded — while Loss triage,
     * the page built to answer "who are we losing to", read those very columns.
     */
    $winback = now()->addMonths(4)->startOfDay();

    $this->post(route('company.crm.leads.transition', $this->lead), [
        'stage' => LeadStatus::Lost->value,
        'reason' => 'Price',
        'competitor' => 'Practo',
        'winback_scheduled_at' => $winback->toDateString(),
        'notes' => 'Budget approved for next financial year.',
    ])->assertSessionHasNoErrors();

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Lost)
        ->and($lead->lost_competitor)->toBe('Practo')
        ->and($lead->winback_scheduled_at->startOfDay()->eq($winback))->toBeTrue()
        ->and($lead->followup_notes)->toContain('next financial year');
});

it('does not stamp a competitor on a stage that is not a loss', function () {
    // A competitor name belongs to the moment a deal was lost. Attaching one to
    // a deal moving forward would be a fact about the wrong event.
    $this->post(route('company.crm.leads.transition', $this->lead), [
        'stage' => LeadStatus::QuotationSent->value,
        'competitor' => 'Practo',
    ]);

    expect($this->lead->fresh()->lost_competitor)->toBeNull();
});

it('logs a note under the name the timeline form sends', function () {
    // `content` versus `description` — the same class of mismatch, already
    // fixed, kept covered so it cannot come back.
    $this->post(route('company.crm.leads.activities.store', $this->lead), [
        'description' => 'Spoke to the practice manager.',
        'type' => 'note',
    ])->assertSessionHasNoErrors();

    expect($this->lead->activities()->where('description', 'Spoke to the practice manager.')->exists())
        ->toBeTrue();
});
