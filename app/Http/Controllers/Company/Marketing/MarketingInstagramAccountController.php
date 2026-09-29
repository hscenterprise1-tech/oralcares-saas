<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingAiSetting;
use App\Models\MarketingInstagramAccount;
use App\Services\Marketing\InstagramManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MarketingInstagramAccountController extends Controller
{
    public function __construct(
        protected InstagramManager $instagramManager
    ) {}

    /**
     * Display Instagram connection + AI content generation settings.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $account = MarketingInstagramAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->first();

        $aiSettings = MarketingAiSetting::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('provider', 'openai')
            ->first();

        return Inertia::render('Company/Marketing/Instagram/Settings', [
            'account' => $account ? [
                'id' => $account->id,
                'instagram_business_account_id' => $account->instagram_business_account_id,
                'facebook_page_id' => $account->facebook_page_id,
                'username' => $account->username,
                'masked_token' => $account->masked_token,
                'status' => $account->status,
                'last_connected_at' => $account->last_connected_at?->toIso8601String(),
                'logo_url' => $account->logo_path ? Storage::disk('public')->url($account->logo_path) : null,
                'buffer_target' => $account->buffer_target,
                'auto_fill_enabled' => $account->auto_fill_enabled,
            ] : null,
            'aiSettings' => $aiSettings ? [
                'id' => $aiSettings->id,
                'image_model' => $aiSettings->image_model,
                'text_model' => $aiSettings->text_model,
                'masked_key' => $aiSettings->masked_key,
                'is_configured' => $aiSettings->isConfigured(),
            ] : null,
        ]);
    }

    /**
     * Connect or update Instagram Business Account credentials.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $validated = $request->validate([
            'instagram_business_account_id' => 'required|string|max:255',
            'facebook_page_id' => 'required|string|max:255',
            'username' => 'nullable|string|max:255',
            'access_token' => 'nullable|string|max:2000',
        ]);

        $account = $this->findOrCreateAccount($orgId, $user?->id);

        $account->fill([
            'instagram_business_account_id' => trim($validated['instagram_business_account_id']),
            'facebook_page_id' => trim($validated['facebook_page_id']),
            'username' => $validated['username'] ?? null,
            'updated_by_user_id' => $user?->id,
            'status' => 'connected',
        ]);

        if (! empty($validated['access_token'])) {
            $account->access_token = trim($validated['access_token']);
        }

        $account->save();

        $isOk = $this->instagramManager->driver()->testConnection($account);
        if (! $isOk) {
            $account->update(['status' => 'error']);
        }

        return redirect()->route('company.marketing.instagram.settings')
            ->with($isOk ? 'success' : 'error', $isOk
                ? 'Instagram account connected successfully.'
                : 'Saved, but could not verify the connection — check credentials.');
    }

    /**
     * Disconnect Instagram account.
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $account = MarketingInstagramAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $account->update(['status' => 'disconnected']);

        return redirect()->route('company.marketing.instagram.settings')->with('success', 'Instagram account disconnected.');
    }

    /**
     * Test connection credentials against provider API.
     */
    public function testConnection(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $account = MarketingInstagramAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $isOk = $this->instagramManager->driver()->testConnection($account);

        return redirect()->back()->with(
            $isOk ? 'success' : 'error',
            $isOk ? 'Instagram connection verified successfully.' : 'Failed to verify Instagram connection.'
        );
    }

    /**
     * Save the OpenAI API key and model preferences used to generate posts.
     */
    public function saveAiSettings(Request $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $validated = $request->validate([
            'api_key' => 'nullable|string|max:2000',
            'image_model' => 'nullable|string|max:100',
            'text_model' => 'nullable|string|max:100',
        ]);

        $settings = MarketingAiSetting::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('provider', 'openai')
            ->first();

        if (! $settings) {
            $settings = new MarketingAiSetting([
                'organization_id' => $orgId,
                'provider' => 'openai',
            ]);
        }

        $settings->image_model = $validated['image_model'] ?? $settings->image_model ?? 'dall-e-3';
        $settings->text_model = $validated['text_model'] ?? $settings->text_model ?? 'gpt-4o-mini';

        if (! empty($validated['api_key'])) {
            $settings->api_key = trim($validated['api_key']);
        }

        $settings->save();

        return redirect()->route('company.marketing.instagram.settings')->with('success', 'AI generation settings saved.');
    }

    /**
     * Save the auto-fill buffer size and on/off toggle. Independent of the Instagram
     * connection form — the queue can auto-generate and sit ready for review even before
     * a real Instagram account is connected.
     */
    public function saveAutomationSettings(Request $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $validated = $request->validate([
            'buffer_target' => 'required|integer|min:1|max:30',
            'auto_fill_enabled' => 'required|boolean',
        ]);

        $account = $this->findOrCreateAccount($orgId, $user?->id);
        $account->buffer_target = $validated['buffer_target'];
        $account->auto_fill_enabled = $validated['auto_fill_enabled'];
        $account->save();

        return redirect()->route('company.marketing.instagram.settings')->with('success', 'Automation settings saved.');
    }

    /**
     * Upload the brand logo watermarked onto every future generated image.
     */
    public function uploadLogo(Request $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $validated = $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        $account = $this->findOrCreateAccount($orgId, $user?->id);

        if ($account->logo_path) {
            Storage::disk('public')->delete($account->logo_path);
        }

        $path = $request->file('logo')->store('instagram/logos', 'public');
        $account->logo_path = $path;
        $account->save();

        return redirect()->route('company.marketing.instagram.settings')->with('success', 'Logo uploaded — it will appear on future generated images.');
    }

    protected function findOrCreateAccount(?int $orgId, ?int $userId): MarketingInstagramAccount
    {
        $account = MarketingInstagramAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->first();

        if (! $account) {
            $account = new MarketingInstagramAccount([
                'organization_id' => $orgId,
                'created_by_user_id' => $userId,
            ]);
            $account->save();
        }

        return $account;
    }
}
