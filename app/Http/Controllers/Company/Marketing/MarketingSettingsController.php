<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Services\Marketing\MarketingSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketingSettingsController extends Controller
{
    public function __construct(
        protected MarketingSettingsService $settingsService
    ) {}

    /**
     * Marketing Settings & Governance Control Center.
     *
     * Also the entry point to three real pages that have no primary nav
     * destination of their own — WhatsApp numbers, templates, and the
     * suppression register are all setup/compliance work, not daily
     * operational surfaces, so they live here as linked cards rather than
     * crowding the rail. Before this they were reachable only by typing the
     * URL directly.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $settings = $this->settingsService->getSettings($orgId);
        $accounts = MarketingWhatsAppAccount::where('organization_id', $orgId)
            ->where('status', 'connected')
            ->select('id', 'verified_name', 'display_phone_number')
            ->get();

        return Inertia::render('Company/Marketing/Settings/Index', [
            'settings' => $settings,
            'accounts' => $accounts,
            // The clock quiet hours are read against — shown on the page so
            // the hours a user picks are unambiguous.
            'timezone' => $this->settingsService->businessTimezone($orgId),
            'tab' => $request->input('tab'),
            'connectionsSummary' => [
                'whatsapp_connected' => MarketingWhatsAppAccount::where('organization_id', $orgId)->where('status', 'connected')->count(),
                'whatsapp_total' => MarketingWhatsAppAccount::where('organization_id', $orgId)->count(),
                'templates_approved' => MarketingWhatsAppTemplate::where('organization_id', $orgId)->where('status', 'approved')->count(),
                'suppressed_total' => MarketingContact::where('organization_id', $orgId)->whereIn('marketing_status', ['opted_out', 'suppressed'])->count(),
            ],
        ]);
    }

    /**
     * Update Marketing Governance Settings payload.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $validated = $request->validate([
            'default_whatsapp_account_id' => 'nullable|integer|exists:marketing_whatsapp_accounts,id',
            'max_daily_messages' => 'required|integer|min:1|max:100000',
            'hourly_dispatch_rate' => 'required|integer|min:1|max:5000',
            'quiet_hours_enabled' => 'required|boolean',
            'quiet_hours_start' => 'required|string',
            'quiet_hours_end' => 'required|string',
            'opt_out_keywords' => 'nullable|string',
            'auto_suppress_on_failures' => 'required|boolean',
            'max_consecutive_failures' => 'required|integer|min:1|max:10',
        ]);

        $this->settingsService->updateSettings($orgId, $validated);

        return back()->with('success', 'Marketing governance settings updated successfully.');
    }

    /**
     * Toggle emergency operational kill-switch.
     */
    public function toggleEmergencyFreeze(Request $request): RedirectResponse
    {
        $user = $request->user();
        $orgId = $user->resolveOrganizationId();

        $freeze = $request->has('freeze') ? (bool) $request->input('freeze') : null;

        $newStatus = $this->settingsService->toggleEmergencyFreeze($orgId, $freeze);

        $msg = $newStatus
            ? 'EMERGENCY FREEZE ACTIVATED. Outbound marketing WhatsApp sends are now frozen across your organization.'
            : 'Emergency freeze deactivated. Outbound marketing WhatsApp dispatches restored.';

        return back()->with('success', $msg);
    }
}
