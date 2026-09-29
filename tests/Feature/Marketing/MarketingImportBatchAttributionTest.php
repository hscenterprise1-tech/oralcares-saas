<?php

use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingContactImport;
use App\Models\MarketingContactImportRow;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\User;
use App\Services\Marketing\ContactImportService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * A batch has to be able to answer "what happened to the contacts in this
 * file" — including the ones it turned out already existed.
 *
 * Before the membership table those were counted and discarded: the batch knew
 * it had rejected 42 rows but not who they were, so its progress figures
 * silently described only the people it happened to create.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['user_type' => UserType::CompanyUser]);
    grantMarketingPermissions($this->user);
    $this->service = app(ContactImportService::class);
});

/** A batch record to import into. */
function aBatch(string $file = 'batch.csv'): MarketingContactImport
{
    return MarketingContactImport::create([
        'original_filename' => $file,
        'batch_name' => $file,
        'status' => 'pending',
        'total_rows' => 0,
    ]);
}

/** One valid row in the shape `processImport` expects. */
function aRow(string $name, string $phone, array $extra = []): array
{
    return array_merge([
        'name' => $name,
        'phone' => $phone,
        'organization' => $name.' Clinic',
        'city' => 'Vadodara',
        'state' => 'Gujarat',
        'area' => 'Karelibaug',
        'tags' => '',
        'status' => 'VALID',
    ], $extra);
}

test('Scenario A — a brand new contact is created and recorded as this batch\'s', function () {
    $batch = aBatch('first.csv');

    $result = $this->service->processImport([aRow('Dr. Rahul', '919876543210')], null, $this->user->id, $batch->id);

    expect($result['imported_count'])->toBe(1)
        ->and($result['duplicate_count'])->toBe(0);

    $contact = MarketingContact::first();

    expect($contact->import_id)->toBe($batch->id)
        ->and($batch->members()->count())->toBe(1)
        ->and($batch->rows()->where('outcome', MarketingContactImportRow::OUTCOME_CREATED)->count())->toBe(1);
});

test('Scenario B — the same person in a later file is not duplicated, but the batch still remembers them', function () {
    $first = aBatch('first.csv');
    $this->service->processImport([aRow('Dr. Rahul', '919876543210')], null, $this->user->id, $first->id);

    $second = aBatch('second.csv');
    $result = $this->service->processImport([aRow('Dr. Rahul', '919876543210')], null, $this->user->id, $second->id);

    // No second contact record — that was always right and stays right.
    expect(MarketingContact::count())->toBe(1)
        ->and($result['imported_count'])->toBe(0)
        ->and($result['duplicate_count'])->toBe(1);

    $contact = MarketingContact::first();

    // The contact still belongs to the file that first added them …
    expect($contact->import_id)->toBe($first->id)
        // … and both files can now say they contained them.
        ->and($first->members()->count())->toBe(1)
        ->and($second->members()->count())->toBe(1)
        ->and($second->rows()->where('outcome', MarketingContactImportRow::OUTCOME_EXISTING)->count())->toBe(1);
});

test('Scenario C — a mixed file reports new, existing and invalid separately', function () {
    $first = aBatch('first.csv');
    $this->service->processImport([aRow('Dr. Rahul', '919876543210')], null, $this->user->id, $first->id);

    $mixed = aBatch('mixed.csv');
    $result = $this->service->processImport([
        aRow('Dr. Rahul', '919876543210'),   // already on the list
        aRow('Dr. Priya', '919812345678'),   // new
        aRow('', '919800000000'),            // no name — unreadable
    ], null, $this->user->id, $mixed->id);

    expect($result['imported_count'])->toBe(1)
        ->and($result['duplicate_count'])->toBe(1)
        ->and($result['invalid_count'])->toBe(1);

    // Two real people were in this file; the third row was never anybody.
    expect($mixed->members()->count())->toBe(2)
        ->and($mixed->rows()->where('outcome', MarketingContactImportRow::OUTCOME_CREATED)->count())->toBe(1)
        ->and($mixed->rows()->where('outcome', MarketingContactImportRow::OUTCOME_EXISTING)->count())->toBe(1)
        ->and($mixed->rows()->where('outcome', MarketingContactImportRow::OUTCOME_INVALID)->count())->toBe(1);

    // An invalid row keeps what the file said, so it can be shown back later.
    $invalid = $mixed->rows()->where('outcome', MarketingContactImportRow::OUTCOME_INVALID)->first();
    expect($invalid->contact_id)->toBeNull()
        ->and($invalid->reason)->not->toBeNull();
});

test('a file listing the same number twice counts that person once', function () {
    $batch = aBatch('repeats.csv');

    $result = $this->service->processImport([
        aRow('Dr. Rahul', '919876543210'),
        aRow('Dr. Rahul Again', '919876543210'),
    ], null, $this->user->id, $batch->id);

    expect($result['imported_count'])->toBe(1)
        ->and($result['duplicate_count'])->toBe(1)
        // One person, one membership row — a repeat inside the file must not
        // inflate the batch.
        ->and($batch->members()->count())->toBe(1);
});

test('the batch summary is computed in the database, over its membership', function () {
    /* Campaign scaffolding, so recipients can exist. */
    $account = MarketingWhatsAppAccount::create([
        'provider' => 'meta', 'business_account_id' => 'X', 'phone_number_id' => 'Y',
        'display_phone_number' => '+91 90000 00000', 'verified_name' => 'T',
        'access_token_encrypted' => Crypt::encryptString('t'), 'status' => 'connected',
    ]);
    $template = MarketingWhatsAppTemplate::create([
        'whatsapp_account_id' => $account->id, 'name' => 't', 'language' => 'en',
        'category' => 'MARKETING', 'status' => 'approved', 'body' => 'Hi {{1}}', 'variables' => ['1'],
    ]);
    $audience = MarketingAudience::create(['name' => 'A', 'rules' => []]);

    $campaign = fn (string $n) => MarketingCampaign::create([
        'whatsapp_account_id' => $account->id, 'audience_id' => $audience->id,
        'template_id' => $template->id, 'name' => $n, 'status' => 'completed',
    ]);

    $first = aBatch('first.csv');
    $this->service->processImport([
        aRow('Dr. Rahul', '919876543210'),
        aRow('Dr. Priya', '919812345678'),
    ], null, $this->user->id, $first->id);

    $second = aBatch('second.csv');
    $this->service->processImport([
        aRow('Dr. Rahul', '919876543210'),   // already exists — still in this file
        aRow('Dr. Vikram', '919898765432'),  // new
    ], null, $this->user->id, $second->id);

    $rahul = MarketingContact::where('normalized_phone', '9876543210')->first();
    $priya = MarketingContact::where('normalized_phone', '9812345678')->first();

    /*
     * Rahul has been through two campaigns. His current state is the second
     * one, and he must be counted once per batch he belongs to — not once per
     * message he has ever received.
     */
    foreach ([['sent', 0], ['replied', 5]] as $i => [$status, $minutes]) {
        $r = MarketingCampaignRecipient::create([
            'campaign_id' => $campaign('c'.$i)->id,
            'contact_id' => $rahul->id,
            'phone' => $rahul->phone,
            'normalized_phone' => $rahul->normalized_phone,
            'status' => $status,
        ]);
        $r->forceFill(['updated_at' => now()->addMinutes($minutes)])->saveQuietly();
    }

    $priya->update(['marketing_status' => 'opted_out']);

    $firstSummary = $first->fresh()->marketingStatusSummary();
    $secondSummary = $second->fresh()->marketingStatusSummary();

    // First file: Rahul (replied) and Priya (opted out).
    expect($firstSummary['total'])->toBe(2)
        ->and($firstSummary['replied'])->toBe(1)
        ->and($firstSummary['opted_out'])->toBe(1)
        ->and($firstSummary['sent'])->toBe(0);

    // Second file: Rahul again — counted here too — plus Vikram, untouched.
    expect($secondSummary['total'])->toBe(2)
        ->and($secondSummary['replied'])->toBe(1)
        ->and($secondSummary['never_contacted'])->toBe(1);

    // Every bucket adds up to the total, in both.
    foreach ([$firstSummary, $secondSummary] as $summary) {
        $counted = collect($summary)->except('total')->sum();
        expect($counted)->toBe($summary['total']);
    }
});

test('the summary does not load contact identifiers into php', function () {
    $batch = aBatch('big.csv');
    $rows = collect(range(1, 40))
        ->map(fn (int $i) => aRow('Dr. Probe '.$i, '9198'.str_pad((string) $i, 8, '0', STR_PAD_LEFT)))
        ->all();

    $this->service->processImport($rows, null, $this->user->id, $batch->id);

    $fresh = $batch->fresh();

    DB::enableQueryLog();
    $summary = $fresh->marketingStatusSummary();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($summary['total'])->toBe(40)
        ->and($summary['never_contacted'])->toBe(40)
        // One grouped query, and no forty-element IN list inside it.
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['bindings'])->toHaveCount(3);
});
