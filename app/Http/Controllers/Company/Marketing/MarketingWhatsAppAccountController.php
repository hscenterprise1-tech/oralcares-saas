<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingSettings;
use App\Models\MarketingWhatsAppAccount;
use App\Services\Marketing\WhatsAppAccountService;
use App\WhatsAppNumberPurpose;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MarketingWhatsAppAccountController extends Controller
{
    public function __construct(
        protected WhatsAppAccountService $accountService
    ) {}

    /**
     * Display WhatsApp Account Connection Dashboard.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $accounts = MarketingWhatsAppAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('provider', 'meta')
            ->orderBy('id')
            ->get();

        // Never exposes the access token — only the masked form.
        $toSafeArray = fn (MarketingWhatsAppAccount $account) => [
            'id' => $account->id,
            'provider' => $account->provider,
            'business_account_id' => $account->business_account_id,
            'phone_number_id' => $account->phone_number_id,
            'display_phone_number' => $account->display_phone_number,
            'verified_name' => $account->verified_name,
            'purpose' => $account->purpose?->value,
            'label' => $account->label,
            'display_name' => $account->display_name,
            'webhook_verify_token' => $account->webhook_verify_token,
            'masked_token' => $account->masked_token,
            'status' => $account->status,
            'connection_status' => $account->connection_status,
            'last_connected_at' => $account->last_connected_at?->toIso8601String(),
            'last_webhook_at' => $account->last_webhook_at?->toIso8601String(),
        ];

        return Inertia::render('Company/Marketing/WhatsApp/Index', [
            'accounts' => $accounts->map($toSafeArray)->values(),
            /*
             * The five purposes and which line currently answers for each.
             *
             * With four or five numbers running, the thing nobody can see is the
             * gap — that onboarding has no line of its own and is quietly
             * falling back to marketing. Meta binds a template to a number, so
             * outbound routing follows the template rather than this; what this
             * answers is "have we set the lines up properly", which is the
             * question a person actually has on a settings screen.
             */
            'purposes' => collect(WhatsAppNumberPurpose::options())
                ->map(function (array $option) use ($orgId) {
                    $line = $orgId
                        ? MarketingWhatsAppAccount::forPurpose($orgId, WhatsAppNumberPurpose::from($option['value']))
                        : null;

                    $exact = $line && $line->purpose?->value === $option['value'];

                    return [
                        ...$option,
                        'line' => $exact ? $line->display_name : null,
                        'falls_back_to' => $exact ? null : $line?->display_name,
                    ];
                })
                ->values(),
            // Retained so the existing single-account view keeps working
            // until the Settings rebuild replaces it.
            'account' => $accounts->first() ? $toSafeArray($accounts->first()) : null,
            // Read, never created — this is a listing screen, and it should
            // not bring a settings row into existence as a side effect of
            // being viewed.
            'defaultAccountId' => $orgId
                ? MarketingSettings::where('organization_id', $orgId)->value('default_whatsapp_account_id')
                : null,
        ]);
    }

    /**
     * Connect or update WhatsApp Business Account credentials.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $validated = $request->validate([
            // Present when editing a connection that already exists; absent
            // when adding another number. Scoped to the caller's own
            // organisation so an id from another tenant cannot be targeted.
            'id' => [
                'nullable',
                'integer',
                Rule::exists('marketing_whatsapp_accounts', 'id')->where(
                    fn ($q) => $orgId ? $q->where('organization_id', $orgId) : $q
                ),
            ],
            'business_account_id' => 'required|string|max:255',
            'phone_number_id' => 'required|string|max:255',
            'display_phone_number' => 'required|string|max:255',
            'verified_name' => 'nullable|string|max:255',
            /*
             * What this line is for, and what to call it. Both optional so an
             * existing connection keeps working untouched; purpose then falls
             * back to the column default, which is marketing.
             */
            'purpose' => ['nullable', Rule::in(array_column(WhatsAppNumberPurpose::cases(), 'value'))],
            'label' => 'nullable|string|max:120',
            'access_token' => 'nullable|string|max:2000',
            'webhook_verify_token' => 'nullable|string|max:255',
        ]);

        $account = $this->accountService->saveAccount($orgId, $validated, $user);
        $this->accountService->testConnection($account);

        return redirect()->route('company.marketing.whatsapp.index')
            ->with('success', 'WhatsApp account connected successfully.');
    }

    /**
     * Disconnect WhatsApp account connection.
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $account = MarketingWhatsAppAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $this->accountService->disconnectAccount($account);

        return redirect()->route('company.marketing.whatsapp.index')
            ->with('success', 'WhatsApp account disconnected.');
    }

    /**
     * Test connection credentials against provider API.
     */
    public function testConnection(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $account = MarketingWhatsAppAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($id);

        $isOk = $this->accountService->testConnection($account);

        if ($isOk) {
            return redirect()->back()->with('success', 'WhatsApp connection verified successfully.');
        }

        return redirect()->back()->with('error', 'Failed to verify WhatsApp connection. Please check credentials.');
    }
}
