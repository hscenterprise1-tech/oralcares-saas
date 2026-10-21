<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\ClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppSettings;
use App\Services\ClinicContext;
use App\Services\WhatsApp\ClinicWhatsApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The clinic's WhatsApp, as the owner or administrator sees it: on or off,
 * Common or Own number, which automations run, the opt-in policy, and every
 * message with its delivery state. The connection itself is OralCares'
 * (company side); no credential is ever shown here.
 */
class WhatsAppController extends Controller
{
    private const PAGE = 25;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicWhatsApp $whatsApp,
    ) {}

    public function index(Request $request): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $settings = $this->whatsApp->settings($clinic);
        $status = in_array($request->query('status'), ['queued', 'sent', 'delivered', 'read', 'failed', 'skipped', 'received'], true) ? $request->query('status') : null;
        $connection = $this->whatsApp->connectionFor($clinic, $settings);
        $groups = (array) config('clinic_whatsapp.groups', []);

        return Inertia::render('ClinicPortal/WhatsApp/Index', [
            'status' => $this->whatsApp->status($clinic, $settings),
            'settings' => [
                'enabled' => (bool) $settings->enabled,
                'mode' => $settings->mode ?? ClinicWhatsAppSettings::COMMON,
                'require_opt_in' => (bool) ($settings->require_opt_in ?? true),
                'own_requested_at' => $settings->own_requested_at?->toIso8601String(),
            ],
            'automations' => collect($this->whatsApp->availableAutomations($clinic))->map(fn (array $automation, string $key) => [
                'key' => $key,
                'label' => $automation['label'],
                'group' => $automation['group'],
                'group_label' => $groups[$automation['group']] ?? $automation['group'],
                'enabled' => $settings->automationEnabled($key),
                'template_ready' => $connection !== null && $this->whatsApp->templateFor($connection, $clinic, $key) !== null,
                'sample' => ClinicWhatsApp::render((string) $automation['body'], array_map(fn (string $name) => match ($name) {
                    'clinic' => $clinic->name, 'patient' => 'Asha', 'when' => '12 Oct 2026, 05:30 PM', 'date' => '12 Oct 2026', 'amount' => '₹500',
                    'balance' => '₹0', 'number' => 'INV-00012', 'token' => '14', 'remaining' => '2', 'next' => '14 Oct 2026, 10:00 AM', default => '…',
                }, (array) $automation['variables'])),
            ])->values(),
            'summary' => ClinicWhatsAppMessage::query()->where('clinic_id', $clinic->id)->where('created_at', '>=', now()->subDays(30))
                ->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n),
            'messages' => Inertia::scroll(fn () => ClinicWhatsAppMessage::query()->where('clinic_id', $clinic->id)
                ->when($status, fn ($query) => $query->where('status', $status))
                ->with(['patient' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'first_name', 'last_name', 'uhid')])
                ->orderByDesc('id')->cursorPaginate(self::PAGE, ['id', 'patient_id', 'direction', 'automation_key', 'preview', 'status', 'failure_reason', 'created_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at'])
                ->through(fn (ClinicWhatsAppMessage $message) => [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'automation' => ClinicWhatsApp::catalog()[$message->automation_key]['label'] ?? ($message->direction === 'inbound' ? 'Reply' : $message->automation_key),
                    'patient' => $message->patient ? ['name' => trim($message->patient->first_name.' '.$message->patient->last_name), 'uhid' => $message->patient->uhid, 'url' => route('clinic.patients.show', $message->patient_id, false)] : null,
                    'preview' => $message->preview,
                    'status' => $message->status,
                    'reason' => $message->failure_reason,
                    'at' => ($message->read_at ?? $message->delivered_at ?? $message->sent_at ?? $message->failed_at ?? $message->created_at)?->toIso8601String(),
                ])),
            'filter' => ['status' => $status],
            'links' => [
                'update' => route('clinic.whatsapp.update', [], false),
                'request_own' => route('clinic.whatsapp.request-own', [], false),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'mode' => ['required', Rule::in([ClinicWhatsAppSettings::COMMON, ClinicWhatsAppSettings::OWN])],
            'require_opt_in' => ['required', 'boolean'],
            'automations' => ['present', 'array', 'max:60'],
            'automations.*' => ['string', Rule::in(array_keys(ClinicWhatsApp::catalog()))],
        ]);

        $this->whatsApp->configure($this->clinicContext->clinic(), $data, $request->user());

        return back()->with('success', 'WhatsApp settings saved.');
    }

    public function requestOwn(Request $request): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->whatsApp->requestOwnNumber($this->clinicContext->clinic(), $data['note'] ?? null, $request->user());

        return back()->with('success', 'Request sent. OralCares will set up and activate your clinic\'s WhatsApp number.');
    }
}
