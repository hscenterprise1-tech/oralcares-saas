<?php

use App\Jobs\GenerateInstagramPostJob;
use App\Jobs\MaintainInstagramBufferJob;
use App\Models\MarketingInstagramAccount;
use App\Models\MarketingInstagramPost;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\OpenAiContentService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');

    $this->org = Organization::factory()->create();
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    grantMarketingPermissions($this->user);
});

test('saving automation settings creates an account row even without instagram credentials', function () {
    $response = $this->actingAs($this->user)->post(route('company.marketing.instagram.settings.automation'), [
        'buffer_target' => 15,
        'auto_fill_enabled' => true,
    ]);

    $response->assertRedirect();

    $account = MarketingInstagramAccount::where('organization_id', $this->org->id)->first();
    expect($account)->not->toBeNull();
    expect($account->buffer_target)->toBe(15);
    expect($account->auto_fill_enabled)->toBeTrue();
    expect($account->instagram_business_account_id)->toBeNull();
});

test('uploading a logo stores the file and replaces a previous one', function () {
    // Real (tiny) PNG bytes — UploadedFile::fake()->image() requires the GD extension,
    // which isn't installed in this environment, but a valid file is still needed to
    // pass the `image` validation rule.
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

    $tmpPath1 = tempnam(sys_get_temp_dir(), 'logo1').'.png';
    file_put_contents($tmpPath1, $pngBytes);
    $file1 = new UploadedFile($tmpPath1, 'logo1.png', 'image/png', null, true);

    $this->actingAs($this->user)->post(route('company.marketing.instagram.settings.logo'), ['logo' => $file1]);

    $account = MarketingInstagramAccount::where('organization_id', $this->org->id)->first();
    $firstPath = $account->logo_path;
    expect($firstPath)->not->toBeNull();
    Storage::disk('public')->assertExists($firstPath);

    $tmpPath2 = tempnam(sys_get_temp_dir(), 'logo2').'.png';
    file_put_contents($tmpPath2, $pngBytes);
    $file2 = new UploadedFile($tmpPath2, 'logo2.png', 'image/png', null, true);

    $this->actingAs($this->user)->post(route('company.marketing.instagram.settings.logo'), ['logo' => $file2]);

    $account->refresh();
    expect($account->logo_path)->not->toBe($firstPath);
    Storage::disk('public')->assertExists($account->logo_path);
    Storage::disk('public')->assertMissing($firstPath);
});

test('buffer maintenance tops up the queue to the configured target', function () {
    $account = MarketingInstagramAccount::create([
        'organization_id' => $this->org->id,
        'buffer_target' => 5,
        'auto_fill_enabled' => true,
    ]);

    // Two already "ready" posts (belonging to this account) should count toward the target.
    MarketingInstagramPost::create(['organization_id' => $this->org->id, 'instagram_account_id' => $account->id, 'status' => 'pending_approval', 'topic' => 'Existing draft 1', 'topic_source' => 'auto']);
    MarketingInstagramPost::create(['organization_id' => $this->org->id, 'instagram_account_id' => $account->id, 'status' => 'scheduled', 'topic' => 'Existing draft 2', 'topic_source' => 'auto']);
    // A published post should NOT count toward the ready buffer.
    MarketingInstagramPost::create(['organization_id' => $this->org->id, 'instagram_account_id' => $account->id, 'status' => 'published', 'topic' => 'Old post', 'topic_source' => 'auto']);

    Queue::fake();

    (new MaintainInstagramBufferJob($account->id))->handle();

    // 5 target - 2 already ready = 3 new posts created, each dispatching a generate job.
    expect(MarketingInstagramPost::where('status', 'generating')->count())->toBe(3);
    Queue::assertPushed(GenerateInstagramPostJob::class, 3);
});

test('buffer maintenance does nothing when auto-fill is disabled', function () {
    $account = MarketingInstagramAccount::create([
        'organization_id' => $this->org->id,
        'buffer_target' => 10,
        'auto_fill_enabled' => false,
    ]);

    Queue::fake();

    (new MaintainInstagramBufferJob($account->id))->handle();

    expect(MarketingInstagramPost::count())->toBe(0);
    Queue::assertNotPushed(GenerateInstagramPostJob::class);
});

test('buffer maintenance does not over-provision when already at target', function () {
    $account = MarketingInstagramAccount::create([
        'organization_id' => $this->org->id,
        'buffer_target' => 2,
        'auto_fill_enabled' => true,
    ]);

    MarketingInstagramPost::create(['organization_id' => $this->org->id, 'instagram_account_id' => $account->id, 'status' => 'pending_approval', 'topic' => 'One', 'topic_source' => 'auto']);
    MarketingInstagramPost::create(['organization_id' => $this->org->id, 'instagram_account_id' => $account->id, 'status' => 'pending_approval', 'topic' => 'Two', 'topic_source' => 'auto']);

    Queue::fake();

    (new MaintainInstagramBufferJob($account->id))->handle();

    Queue::assertNotPushed(GenerateInstagramPostJob::class);
});

test('buffer count is scoped to the account being topped up, not the whole organization', function () {
    // Two accounts in the same organization — the schema allows it even
    // though the product only exposes one through the UI today. The
    // dispatching command already loops per account; the count inside the
    // job must match that, not silently count the other account's posts.
    $accountA = MarketingInstagramAccount::create([
        'organization_id' => $this->org->id,
        'buffer_target' => 3,
        'auto_fill_enabled' => true,
    ]);
    $accountB = MarketingInstagramAccount::create([
        'organization_id' => $this->org->id,
        'buffer_target' => 3,
        'auto_fill_enabled' => true,
    ]);

    // Account B's queue is already full — must not count toward Account A's target.
    MarketingInstagramPost::create(['organization_id' => $this->org->id, 'instagram_account_id' => $accountB->id, 'status' => 'pending_approval', 'topic' => 'B-1', 'topic_source' => 'auto']);
    MarketingInstagramPost::create(['organization_id' => $this->org->id, 'instagram_account_id' => $accountB->id, 'status' => 'pending_approval', 'topic' => 'B-2', 'topic_source' => 'auto']);
    MarketingInstagramPost::create(['organization_id' => $this->org->id, 'instagram_account_id' => $accountB->id, 'status' => 'pending_approval', 'topic' => 'B-3', 'topic_source' => 'auto']);

    Queue::fake();

    (new MaintainInstagramBufferJob($accountA->id))->handle();

    // Account A has zero posts of its own, so the full target (3) must be created for it.
    Queue::assertPushed(GenerateInstagramPostJob::class, 3);
    expect(MarketingInstagramPost::where('instagram_account_id', $accountA->id)->where('status', 'generating')->count())->toBe(3);
});

test('an auto-fill post with no topic gets one picked by the AI (sandbox rotation) before generating', function () {
    $account = MarketingInstagramAccount::create(['organization_id' => $this->org->id]);

    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'instagram_account_id' => $account->id,
        'topic' => null,
        'topic_source' => 'auto',
        'status' => 'generating',
    ]);

    (new GenerateInstagramPostJob($post->id))->handle(app(OpenAiContentService::class));

    $post->refresh();
    expect($post->status)->toBe('pending_approval');
    expect($post->topic)->not->toBeEmpty();
    expect($post->caption)->not->toBeEmpty();
});

test('the instagram:maintain-buffers command dispatches a job per auto-fill-enabled account only', function () {
    MarketingInstagramAccount::create(['organization_id' => $this->org->id, 'auto_fill_enabled' => true]);

    $otherOrg = Organization::factory()->create();
    MarketingInstagramAccount::create(['organization_id' => $otherOrg->id, 'auto_fill_enabled' => false]);

    Queue::fake();

    $this->artisan('instagram:maintain-buffers')->assertSuccessful();

    Queue::assertPushed(MaintainInstagramBufferJob::class, 1);
});
