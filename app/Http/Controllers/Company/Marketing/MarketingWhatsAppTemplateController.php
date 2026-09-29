<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Services\Marketing\WhatsAppTemplateService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketingWhatsAppTemplateController extends Controller
{
    public function __construct(
        protected WhatsAppTemplateService $templateService
    ) {}

    /**
     * Display WhatsApp Templates Directory.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $accounts = MarketingWhatsAppAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->get();

        $selectedAccountId = $request->input('account_id') ?? $accounts->first()?->id;

        $templates = MarketingWhatsAppTemplate::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->when($selectedAccountId, fn ($q) => $q->where('whatsapp_account_id', $selectedAccountId))
            ->with('whatsappAccount')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Company/Marketing/Templates/Index', [
            'templates' => $templates,
            'accounts' => $accounts,
            'selectedAccountId' => $selectedAccountId,
        ]);
    }

    /**
     * Synchronize templates from WhatsApp Provider API.
     */
    public function sync(Request $request)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $validated = $request->validate([
            'whatsapp_account_id' => 'required|exists:marketing_whatsapp_accounts,id',
        ]);

        $account = MarketingWhatsAppAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($validated['whatsapp_account_id']);

        $res = $this->templateService->syncTemplates($account);

        return redirect()->back()->with('success', "Synchronized {$res['total']} WhatsApp templates successfully.");
    }
}
