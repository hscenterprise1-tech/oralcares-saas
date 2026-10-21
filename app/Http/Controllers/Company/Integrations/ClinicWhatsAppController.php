<?php

namespace App\Http\Controllers\Company\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\ClinicWhatsAppMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\ClinicWhatsApp;
use App\Services\WhatsApp\WhatsAppConnections;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company → Clinic WhatsApp: OralCares' technical control of the numbers
 * clinics send on — the common number every Common-mode clinic uses, and
 * each clinic's own number, configured and activated here. Credentials are
 * write-only: entered here, never shown back. Separate from Marketing's
 * WhatsApp (OralCares' own sales line).
 */
class ClinicWhatsAppController extends Controller
{
    public function __construct(protected WhatsAppConnections $connections) {}

    public function index(): Response
    {
        Gate::authorize('clinic-whatsapp.view');
        $week = now()->subDays(7);
        $delivery = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->where('created_at', '>=', $week)->whereNotNull('whatsapp_connection_id')
            ->toBase()->selectRaw('whatsapp_connection_id, status, count(*) as total')->groupBy('whatsapp_connection_id', 'status')->get()
            ->groupBy('whatsapp_connection_id');
        $templates = WhatsAppTemplate::query()->toBase()->selectRaw('whatsapp_connection_id, count(*) as total')->groupBy('whatsapp_connection_id')->pluck('total', 'whatsapp_connection_id');

        return Inertia::render('Company/Integrations/ClinicWhatsApp', [
            'connections' => WhatsAppConnection::query()->with('clinic:id,name')->orderByRaw("mode = 'company_shared' desc")->orderByDesc('id')->limit(200)->get()
                ->map(fn (WhatsAppConnection $connection) => [
                    'id' => $connection->id,
                    'mode' => $connection->mode,
                    'clinic' => $connection->clinic ? ['id' => $connection->clinic->id, 'name' => $connection->clinic->name] : null,
                    'label' => $connection->label,
                    'number' => $connection->display_phone_number,
                    'phone_number_id' => $connection->phone_number_id,
                    'business_account_id' => $connection->business_account_id,
                    'has_access_token' => filled($connection->getRawOriginal('access_token')),
                    'has_verify_token' => filled($connection->getRawOriginal('webhook_verify_token')),
                    'status' => $connection->status,
                    'health' => $connection->health_message,
                    'checked_at' => $connection->last_checked_at?->toIso8601String(),
                    'activated_at' => $connection->activated_at?->toIso8601String(),
                    'templates' => (int) ($templates[$connection->id] ?? 0),
                    'week' => collect($delivery->get($connection->id, []))->pluck('total', 'status')->map(fn ($n) => (int) $n),
                ]),
            'requests' => $this->connections->pendingRequests(),
            'automations' => collect(ClinicWhatsApp::catalog())->map(fn (array $a, string $key) => ['key' => $key, 'label' => $a['label'], 'body' => $a['body'], 'variables' => $a['variables']])->values(),
            'webhook_url' => url('/webhooks/clinic-whatsapp'),
            'can_manage' => Gate::allows('clinic-whatsapp.manage'),
        ]);
    }

    /** Clinics by name, for choosing whose own number this is. */
    public function clinics(Request $request): JsonResponse
    {
        Gate::authorize('clinic-whatsapp.manage');
        $q = trim((string) $request->query('q', ''));

        return response()->json(['data' => Clinic::query()->when($q !== '', fn ($query) => $query->where('name', 'like', $q.'%'))
            ->orderBy('name')->limit(20)->get(['id', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('clinic-whatsapp.manage');

        return $this->attempt(fn () => $this->connections->save($this->validated($request), $request->user()), 'Number saved. Activate it once the details are complete.');
    }

    public function update(Request $request, WhatsAppConnection $connection): RedirectResponse
    {
        Gate::authorize('clinic-whatsapp.manage');

        return $this->attempt(fn () => $this->connections->save($this->validated($request, $connection), $request->user(), $connection), 'Number updated.');
    }

    public function activate(Request $request, WhatsAppConnection $connection): RedirectResponse
    {
        Gate::authorize('clinic-whatsapp.manage');

        return $this->attempt(fn () => $this->connections->activate($connection, $request->user()), 'Number activated. Messages now go out on it.');
    }

    public function disable(Request $request, WhatsAppConnection $connection): RedirectResponse
    {
        Gate::authorize('clinic-whatsapp.manage');

        return $this->attempt(fn () => $this->connections->disable($connection, $request->user()), 'Number disabled. Nothing goes out on it until it is activated again.');
    }

    public function check(WhatsAppConnection $connection): RedirectResponse
    {
        Gate::authorize('clinic-whatsapp.manage');
        $healthy = $this->connections->check($connection);

        return back()->with($healthy ? 'success' : 'error', $healthy ? 'Meta accepted the credentials.' : 'Meta did not accept the credentials.');
    }

    public function template(Request $request, WhatsAppConnection $connection): RedirectResponse
    {
        Gate::authorize('clinic-whatsapp.manage');
        $data = $request->validate([
            'automation_key' => ['required', Rule::in(array_keys(ClinicWhatsApp::catalog()))],
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['nullable', 'string', 'max:10'],
            'body' => ['required', 'string', 'max:1024'],
        ]);

        return $this->attempt(fn () => $this->connections->saveTemplate($connection, $data, $request->user()), 'Template saved.');
    }

    public function seedTemplates(Request $request, WhatsAppConnection $connection): RedirectResponse
    {
        Gate::authorize('clinic-whatsapp.manage');
        $created = $this->connections->seedDefaultTemplates($connection, $request->user());

        return back()->with('success', $created > 0 ? "{$created} default templates registered. Submit the same names and texts to Meta for approval." : 'Every automation already has a template.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?WhatsAppConnection $connection = null): array
    {
        return $request->validate([
            'mode' => ['required', Rule::in([WhatsAppConnection::SHARED, WhatsAppConnection::OWNED])],
            'clinic_id' => ['nullable', 'required_if:mode,'.WhatsAppConnection::OWNED, 'integer', 'exists:clinics,id'],
            'label' => ['required', 'string', 'max:120'],
            'display_phone_number' => ['nullable', 'string', 'max:30'],
            'phone_number_id' => ['nullable', 'string', 'max:64', Rule::unique('whatsapp_connections', 'phone_number_id')->ignore($connection?->id)],
            'business_account_id' => ['nullable', 'string', 'max:64'],
            'access_token' => ['nullable', 'string', 'max:2000'],
            'webhook_verify_token' => ['nullable', 'string', 'max:200'],
        ]);
    }

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->withErrors(['whatsapp' => $e->getMessage()]);
        }

        return back()->with('success', $success);
    }
}
