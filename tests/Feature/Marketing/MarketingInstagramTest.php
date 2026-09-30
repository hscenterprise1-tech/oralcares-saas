<?php

use App\Jobs\PublishInstagramPostJob;
use App\Models\MarketingAiSetting;
use App\Models\MarketingInstagramAccount;
use App\Models\MarketingInstagramPost;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\InstagramManager;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    grantMarketingPermissions($this->user);
});

test('user can connect an instagram business account and the token is encrypted at rest', function () {
    $response = $this->actingAs($this->user)->post(route('company.marketing.instagram.settings.store'), [
        'instagram_business_account_id' => '17841400000000000',
        'facebook_page_id' => '109283746592817',
        'username' => 'oralcares_official',
        'access_token' => 'secret_page_token_12345',
    ]);

    $response->assertRedirect();

    $account = MarketingInstagramAccount::first();
    expect($account)->not->toBeNull();
    expect($account->instagram_business_account_id)->toBe('17841400000000000');
    expect($account->access_token_encrypted)->not->toBe('secret_page_token_12345');
    expect(Crypt::decryptString($account->access_token_encrypted))->toBe('secret_page_token_12345');
});

test('inertia settings page never exposes the raw instagram token or openai key', function () {
    MarketingInstagramAccount::create([
        'organization_id' => $this->org->id,
        'instagram_business_account_id' => '17841400000000000',
        'facebook_page_id' => '109283746592817',
        'access_token_encrypted' => Crypt::encryptString('secret_token'),
        'status' => 'connected',
    ]);

    MarketingAiSetting::create([
        'organization_id' => $this->org->id,
        'provider' => 'openai',
        'api_key_encrypted' => Crypt::encryptString('sk-secret'),
    ]);

    $response = $this->actingAs($this->user)->get(route('company.marketing.instagram.settings'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Marketing/Instagram/Settings')
            ->where('account.masked_token', '••••••••••••••••')
            ->where('aiSettings.masked_key', '••••••••••••••••')
            ->missing('account.access_token')
            ->missing('account.access_token_encrypted')
            ->missing('aiSettings.api_key')
            ->missing('aiSettings.api_key_encrypted')
        );
});

test('user can save openai settings and the api key is encrypted at rest', function () {
    $response = $this->actingAs($this->user)->post(route('company.marketing.instagram.settings.ai'), [
        'api_key' => 'sk-test-secret-key',
        'image_model' => 'dall-e-3',
        'text_model' => 'gpt-4o-mini',
    ]);

    $response->assertRedirect();

    $settings = MarketingAiSetting::where('organization_id', $this->org->id)->where('provider', 'openai')->first();
    expect($settings)->not->toBeNull();
    expect(Crypt::decryptString($settings->api_key_encrypted))->toBe('sk-test-secret-key');
    expect($settings->isConfigured())->toBeTrue();
});

test('generating a post without an openai key runs in sandbox mode and produces a reviewable draft', function () {
    $response = $this->actingAs($this->user)->post(route('company.marketing.instagram.generate'), [
        'topic' => 'New Year teeth whitening offer',
    ]);

    $response->assertRedirect();

    $post = MarketingInstagramPost::where('organization_id', $this->org->id)->first();
    expect($post)->not->toBeNull();
    expect($post->topic)->toBe('New Year teeth whitening offer');
    // QUEUE_CONNECTION=sync in testing, so the generation job already ran.
    expect($post->status)->toBe('pending_approval');
    expect($post->caption)->not->toBeEmpty();
    expect($post->image_path)->not->toBeEmpty();
    expect($post->image_url)->not->toBeEmpty();
});

test('a pending post caption can be edited before approval', function () {
    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Dental hygiene tips',
        'caption' => 'Old caption',
        'status' => 'pending_approval',
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('company.marketing.instagram.update', $post->id), [
        'caption' => 'Brand new, human-edited caption.',
    ]);

    $response->assertRedirect();
    expect($post->fresh()->caption)->toBe('Brand new, human-edited caption.');
});

test('approving a pending post publishes it immediately in sandbox mode', function () {
    $account = MarketingInstagramAccount::create([
        'organization_id' => $this->org->id,
        'instagram_business_account_id' => '17841400000000000',
        'facebook_page_id' => '109283746592817',
        'status' => 'connected',
    ]);

    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'instagram_account_id' => $account->id,
        'topic' => 'Smile makeover before/after',
        'caption' => 'Check out this transformation!',
        'image_url' => 'https://example.test/image.png',
        'status' => 'pending_approval',
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->post(route('company.marketing.instagram.approve', $post->id), []);

    $response->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe('published');
    expect($post->approved_by_user_id)->toBe($this->user->id);
    expect($post->provider_media_id)->not->toBeEmpty();
    expect($post->published_at)->not->toBeNull();
});

test('approving with a future date schedules the post and dispatches a delayed publish job', function () {
    Queue::fake();

    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Weekend clinic hours',
        'caption' => 'We are open this Saturday!',
        'image_url' => 'https://example.test/image.png',
        'status' => 'pending_approval',
        'created_by_user_id' => $this->user->id,
    ]);

    $future = now()->addDay();

    $response = $this->actingAs($this->user)->post(route('company.marketing.instagram.approve', $post->id), [
        'scheduled_at' => $future->toDateTimeString(),
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe('scheduled');
    expect($post->scheduled_at)->not->toBeNull();

    Queue::assertPushed(PublishInstagramPostJob::class, fn ($job) => $job->postId === $post->id);
});

test('a post can only be approved once, from pending_approval status', function () {
    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Already published',
        'status' => 'published',
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->post(route('company.marketing.instagram.approve', $post->id), []);

    $response->assertRedirect();
    expect($post->fresh()->status)->toBe('published');
});

test('rejecting a pending post stores the reason and never publishes it', function () {
    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Off-brand suggestion',
        'status' => 'pending_approval',
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->post(route('company.marketing.instagram.reject', $post->id), [
        'rejection_reason' => 'Not on brand',
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe('rejected');
    expect($post->rejection_reason)->toBe('Not on brand');
    expect($post->published_at)->toBeNull();
});

test('concurrent double-click approval only lets one request win, and only one publish job is dispatched', function () {
    Queue::fake();

    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Double-click test',
        'caption' => 'Caption',
        'image_url' => 'https://example.test/image.png',
        'status' => 'pending_approval',
        'created_by_user_id' => $this->user->id,
    ]);

    // Simulates two near-simultaneous requests both reaching the atomic
    // conditional update before either has committed a status change — the
    // first UPDATE ... WHERE status = 'pending_approval' matches and wins;
    // the second finds nothing left to match. Asserted immediately after
    // each call — session flash data ages forward on the next request, so
    // checking $first only after $second has already been made would read
    // a stale/cleared flash bag.
    $first = $this->actingAs($this->user)->post(route('company.marketing.instagram.approve', $post->id), []);
    $first->assertSessionHas('success');

    $second = $this->actingAs($this->user)->post(route('company.marketing.instagram.approve', $post->id), []);
    $second->assertSessionHas('error');

    Queue::assertPushed(PublishInstagramPostJob::class, 1);
    expect($post->fresh()->status)->toBe('approved');
});

test('a post that already has a provider media id is never published again', function () {
    $account = MarketingInstagramAccount::create([
        'organization_id' => $this->org->id,
        'instagram_business_account_id' => '17841400000000000',
        'facebook_page_id' => '109283746592817',
        'access_token_encrypted' => Crypt::encryptString('test_token_for_sandbox'),
        'status' => 'connected',
    ]);

    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'instagram_account_id' => $account->id,
        'topic' => 'Already published',
        'caption' => 'Caption',
        'image_url' => 'https://example.test/image.png',
        // Deliberately left in 'publishing' — a genuinely completed run
        // would already show 'published', but this is exactly the state a
        // stale duplicate dispatch could land on mid-flight.
        'status' => 'publishing',
        'provider_media_id' => 'ig_already_sent_12345',
        'created_by_user_id' => $this->user->id,
    ]);

    (new PublishInstagramPostJob($post->id))->handle(app(InstagramManager::class));

    // The guard returns before ever touching status again — no re-publish,
    // no overwritten provider_media_id, status left exactly as it was.
    $post->refresh();
    expect($post->provider_media_id)->toBe('ig_already_sent_12345');
    expect($post->status)->toBe('publishing');
});

test('a published post cannot be edited', function () {
    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Live post',
        'caption' => 'Original caption',
        'status' => 'published',
        'published_at' => now(),
        'provider_media_id' => 'ig_live_123',
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('company.marketing.instagram.update', $post->id), [
        'caption' => 'Trying to sneak a change in',
    ]);

    $response->assertSessionHas('error');
    expect($post->fresh()->caption)->toBe('Original caption');
});

test('rejected and failed drafts can be deleted', function () {
    $rejected = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Rejected draft',
        'status' => 'rejected',
        'rejection_reason' => 'Not on brand',
        'created_by_user_id' => $this->user->id,
    ]);
    $failed = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Failed draft',
        'status' => 'failed',
        'error_message' => 'Generation failed',
        'created_by_user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)->delete(route('company.marketing.instagram.destroy', $rejected->id))
        ->assertSessionHas('success');
    $this->actingAs($this->user)->delete(route('company.marketing.instagram.destroy', $failed->id))
        ->assertSessionHas('success');

    expect(MarketingInstagramPost::find($rejected->id))->toBeNull();
    expect(MarketingInstagramPost::find($failed->id))->toBeNull();
});

test('a published post cannot be deleted, protecting its publishing history', function () {
    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Live post',
        'caption' => 'Caption',
        'status' => 'published',
        'published_at' => now(),
        'provider_media_id' => 'ig_live_456',
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->delete(route('company.marketing.instagram.destroy', $post->id));

    $response->assertSessionHas('error');
    expect(MarketingInstagramPost::find($post->id))->not->toBeNull();
    expect($post->fresh()->provider_media_id)->toBe('ig_live_456');
});

test('an approved-but-not-yet-scheduled post cannot be deleted either', function () {
    $post = MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Approved, about to publish',
        'status' => 'approved',
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->delete(route('company.marketing.instagram.destroy', $post->id));

    $response->assertSessionHas('error');
    expect(MarketingInstagramPost::find($post->id))->not->toBeNull();
});

test('the content queue paginates past the first 20 posts', function () {
    foreach (range(1, 25) as $i) {
        MarketingInstagramPost::create([
            'organization_id' => $this->org->id,
            'topic' => "Post {$i}",
            'status' => 'pending_approval',
            'created_by_user_id' => $this->user->id,
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $firstPage = $this->actingAs($this->user)->get(route('company.marketing.instagram.index'));
    $firstPage->assertInertia(fn ($page) => $page
        ->where('posts.data', fn ($data) => count($data) === 20)
        ->where('posts.total', 25)
        ->where('posts.last_page', 2)
        ->has('posts.links')
    );

    $secondPage = $this->actingAs($this->user)->get(route('company.marketing.instagram.index', ['page' => 2]));
    $secondPage->assertInertia(fn ($page) => $page
        ->where('posts.current_page', 2)
        ->where('posts.data', fn ($data) => count($data) === 5)
    );
});

test('a user cannot access or act on another tenant\'s instagram post', function () {
    $otherOrg = Organization::factory()->create();
    $otherPost = MarketingInstagramPost::create([
        'organization_id' => $otherOrg->id,
        'topic' => 'Other tenant post',
        'status' => 'pending_approval',
    ]);

    $this->actingAs($this->user)
        ->post(route('company.marketing.instagram.approve', $otherPost->id), [])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->put(route('company.marketing.instagram.update', $otherPost->id), ['caption' => 'hacked'])
        ->assertNotFound();
});

test('the active account identity is exposed on the queue page, scoped to the requesting organization', function () {
    MarketingInstagramAccount::create([
        'organization_id' => $this->org->id,
        'instagram_business_account_id' => '17841400000000000',
        'facebook_page_id' => '109283746592817',
        'username' => 'oralcares_official',
        'access_token_encrypted' => Crypt::encryptString('test_token'),
        'status' => 'connected',
    ]);

    $otherOrg = Organization::factory()->create();
    MarketingInstagramAccount::create([
        'organization_id' => $otherOrg->id,
        'instagram_business_account_id' => '99999999999999999',
        'username' => 'someone_elses_clinic',
        'access_token_encrypted' => Crypt::encryptString('other_token'),
        'status' => 'connected',
    ]);

    $response = $this->actingAs($this->user)->get(route('company.marketing.instagram.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('account.username', 'oralcares_official')
        ->where('account.instagram_business_account_id', '17841400000000000')
        ->where('account.status', 'connected')
        ->where('hasConnectedAccount', true)
        ->missing('account.access_token_encrypted')
    );
});

test('headline counts are organization-wide, not derived from the current page, and stay correct on page 2', function () {
    foreach (range(1, 25) as $i) {
        MarketingInstagramPost::create([
            'organization_id' => $this->org->id,
            'topic' => "Needs review {$i}",
            'status' => 'pending_approval',
            'created_at' => now()->subMinutes($i),
        ]);
    }

    foreach (range(1, 4) as $i) {
        MarketingInstagramPost::create([
            'organization_id' => $this->org->id,
            'topic' => "Approved {$i}",
            'status' => 'approved',
        ]);
    }
    foreach (range(1, 2) as $i) {
        MarketingInstagramPost::create([
            'organization_id' => $this->org->id,
            'topic' => "Scheduled {$i}",
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);
    }

    foreach (range(1, 3) as $i) {
        MarketingInstagramPost::create([
            'organization_id' => $this->org->id,
            'topic' => "Published {$i}",
            'status' => 'published',
            'published_at' => now(),
        ]);
    }
    MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Failed one',
        'status' => 'failed',
        'error_message' => 'Provider rejected the image',
    ]);
    MarketingInstagramPost::create([
        'organization_id' => $this->org->id,
        'topic' => 'Rejected one',
        'status' => 'rejected',
        'rejection_reason' => 'Not on brand',
    ]);

    $expectedCounts = fn ($page) => $page
        ->where('counts.needs_review', 25)
        ->where('counts.approved_scheduled', 6)
        ->where('counts.history', 5);

    $this->actingAs($this->user)
        ->get(route('company.marketing.instagram.index'))
        ->assertInertia(fn ($page) => $expectedCounts($page)->where('posts.total', 25));

    // Same organization-wide counts on page 2 of the same tab — never derived
    // from whichever rows happen to be on the currently loaded page.
    $this->actingAs($this->user)
        ->get(route('company.marketing.instagram.index', ['page' => 2]))
        ->assertInertia(fn ($page) => $expectedCounts($page)->where('posts.current_page', 2));

    $this->actingAs($this->user)
        ->get(route('company.marketing.instagram.index', ['tab' => 'history']))
        ->assertInertia(fn ($page) => $expectedCounts($page)->where('posts.total', 5));
});

test('each tab shows only the statuses that belong to it', function () {
    $statuses = ['generating', 'pending_approval', 'approved', 'scheduled', 'publishing', 'published', 'rejected', 'failed'];
    foreach ($statuses as $status) {
        MarketingInstagramPost::create([
            'organization_id' => $this->org->id,
            'topic' => "Status: {$status}",
            'status' => $status,
        ]);
    }

    $topicsFor = function (string $tab) {
        $response = $this->actingAs($this->user)->get(route('company.marketing.instagram.index', ['tab' => $tab]));
        $props = $response->viewData('page')['props'];

        return collect($props['posts']['data'])->pluck('topic')->all();
    };

    expect($topicsFor('needs_review'))->toEqualCanonicalizing(['Status: generating', 'Status: pending_approval']);
    expect($topicsFor('approved_scheduled'))->toEqualCanonicalizing(['Status: approved', 'Status: scheduled', 'Status: publishing']);
    expect($topicsFor('history'))->toEqualCanonicalizing(['Status: published', 'Status: rejected', 'Status: failed']);
});

test('pagination is independent per tab and a tab switch starts fresh at page 1', function () {
    foreach (range(1, 25) as $i) {
        MarketingInstagramPost::create([
            'organization_id' => $this->org->id,
            'topic' => "Needs review {$i}",
            'status' => 'pending_approval',
            'created_at' => now()->subMinutes($i),
        ]);
    }
    foreach (range(1, 3) as $i) {
        MarketingInstagramPost::create([
            'organization_id' => $this->org->id,
            'topic' => "Approved {$i}",
            'status' => 'approved',
        ]);
    }

    $this->actingAs($this->user)
        ->get(route('company.marketing.instagram.index', ['tab' => 'needs_review', 'page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->where('posts.current_page', 2)
            ->where('posts.data', fn ($data) => count($data) === 5)
        );

    // Switching tabs (no `page` param) starts that tab's own paginator at page 1,
    // unaffected by the page position left behind on the other tab.
    $this->actingAs($this->user)
        ->get(route('company.marketing.instagram.index', ['tab' => 'approved_scheduled']))
        ->assertInertia(fn ($page) => $page
            ->where('posts.current_page', 1)
            ->where('posts.data', fn ($data) => count($data) === 3)
        );

    $this->actingAs($this->user)
        ->get(route('company.marketing.instagram.index', ['tab' => 'needs_review']))
        ->assertInertia(fn ($page) => $page
            ->where('posts.current_page', 1)
            ->where('posts.data', fn ($data) => count($data) === 20)
        );
});
