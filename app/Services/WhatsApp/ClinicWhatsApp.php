<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendClinicWhatsAppMessage;
use App\Models\Clinic;
use App\Models\ClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppSettings;
use App\Models\ConsentRecord;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Services\Communication\PatientMessageEligibility;
use App\Services\NotificationService;
use App\Services\Provisioning\EntitlementResolver;
use App\Services\Security\SecurityAudit;
use App\Support\Specialty\SpecialtyRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Patient WhatsApp for clinics: the WhatsApp channel of `PatientMessaging`.
 *
 * `PatientMessaging` decides what to tell a patient and when (one
 * `notifications` row per event). This class decides whether that message
 * goes on WhatsApp (`carries()`) and delivers it (`deliver()`), resolving in
 * order: the clinic → its settings (on, automation on, plan, specialty) →
 * the number its mode selects (OralCares' common number, or the clinic's own
 * once OralCares has activated it; never another clinic's, never the HQ
 * marketing number) → the template → the patient's mobile and agreement.
 *
 * A delivery that cannot go is kept as `skipped` with the reason, so the
 * owner sees why. Sending is `SendClinicWhatsAppMessage`, after commit.
 */
class ClinicWhatsApp
{
    public function __construct(
        protected SpecialtyRegistry $specialties,
        protected EntitlementResolver $entitlements,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public static function catalog(): array
    {
        return (array) config('clinic_whatsapp.automations', []);
    }

    public function settings(Clinic $clinic): ClinicWhatsAppSettings
    {
        return ClinicWhatsAppSettings::query()->withoutGlobalScopes()->firstOrNew(['clinic_id' => $clinic->id], [
            'enabled' => false, 'mode' => ClinicWhatsAppSettings::COMMON, 'automations' => [], 'reminder_lead_hours' => 24, 'require_opt_in' => true,
        ]);
    }

    /** Whether the clinic's plan includes WhatsApp (a clinic with no subscription keeps it). */
    public function inPlan(Clinic $clinic): bool
    {
        $entitlement = $this->entitlements->forClinic($clinic);

        return ! $entitlement['has_subscription'] || in_array('whatsapp', (array) $entitlement['modules'], true);
    }

    /**
     * The automations this clinic may switch on: the shared ones and those
     * of the specialties it bought.
     *
     * @return array<string, array<string, mixed>>
     */
    public function availableAutomations(Clinic $clinic): array
    {
        $extensions = $this->specialties->extensionsFor($this->specialties->forClinic($clinic));

        return array_filter(self::catalog(), fn (array $automation) => ! isset($automation['requires_extension']) || in_array($automation['requires_extension'], $extensions, true));
    }

    /** The number this clinic's messages go out on now, or null. */
    public function connectionFor(Clinic $clinic, ?ClinicWhatsAppSettings $settings = null): ?WhatsAppConnection
    {
        $settings ??= $this->settings($clinic);

        return $settings->mode === ClinicWhatsAppSettings::OWN
            ? WhatsAppConnection::query()->where('mode', WhatsAppConnection::OWNED)->where('clinic_id', $clinic->id)->active()->first()
            : WhatsAppConnection::query()->where('mode', WhatsAppConnection::SHARED)->whereNull('clinic_id')->active()->orderBy('id')->first();
    }

    /**
     * The owner's choices: on/off, Common or Own, the opt-in policy and which
     * automations run. Only automations this clinic may use are kept.
     *
     * @param  array{enabled: bool, mode: string, require_opt_in: bool, automations: list<string>}  $data
     */
    public function configure(Clinic $clinic, array $data, User $by): ClinicWhatsAppSettings
    {
        $available = array_keys($this->availableAutomations($clinic));
        $settings = $this->settings($clinic);
        $before = $settings->only(['enabled', 'mode', 'require_opt_in', 'automations']);
        $settings->fill([
            'enabled' => $data['enabled'],
            'mode' => $data['mode'],
            'require_opt_in' => $data['require_opt_in'],
            'automations' => array_fill_keys(array_values(array_intersect($data['automations'], $available)), true),
            'updated_by' => $by->id,
        ])->save();
        app(SecurityAudit::class)->record('whatsapp.settings_changed', $settings, ['before' => $before, 'after' => $settings->only(['enabled', 'mode', 'require_opt_in', 'automations'])], ['clinic_id' => $clinic->id]);

        return $settings;
    }

    /** Ask OralCares to connect the clinic's own number (Own mode). */
    public function requestOwnNumber(Clinic $clinic, ?string $note, User $by): ClinicWhatsAppSettings
    {
        $settings = $this->settings($clinic);
        $settings->fill(['mode' => ClinicWhatsAppSettings::OWN, 'own_requested_at' => now(), 'own_request_note' => $note ? mb_substr($note, 0, 500) : null, 'updated_by' => $by->id])->save();
        app(SecurityAudit::class)->record('whatsapp.own_number_requested', $settings, [], ['clinic_id' => $clinic->id]);

        return $settings;
    }

    /**
     * Where the clinic's WhatsApp stands, for its settings page. Never a
     * credential: the number masked, and who manages it.
     *
     * @return array{state: string, label: string, number: ?string, managed_by: string, mode: string}
     */
    public function status(Clinic $clinic, ?ClinicWhatsAppSettings $settings = null): array
    {
        $settings ??= $this->settings($clinic);
        $connection = $this->connectionFor($clinic, $settings);
        $own = $settings->mode === ClinicWhatsAppSettings::OWN;

        $state = match (true) {
            ! $this->inPlan($clinic) => 'not_in_plan',
            $connection !== null => 'connected',
            $own && $settings->own_requested_at !== null => 'pending_activation',
            $own => 'not_requested',
            default => 'unavailable',
        };

        return [
            'state' => $state,
            'mode' => $settings->mode,
            'label' => $own ? 'Clinic WhatsApp' : 'OralCares Common WhatsApp',
            'number' => $own ? $connection?->maskedNumber() : null,
            'managed_by' => 'OralCares',
        ];
    }

    /** Whether a patient message of this code goes on WhatsApp for this clinic. */
    public function carries(Clinic $clinic, string $code): bool
    {
        $settings = $this->settings($clinic);

        return $settings->exists && $settings->enabled && $settings->automationEnabled($code)
            && $this->inPlan($clinic) && array_key_exists($code, $this->availableAutomations($clinic))
            && $this->connectionFor($clinic, $settings) !== null;
    }

    /**
     * Turn a due patient message into a WhatsApp delivery, once per message.
     */
    public function deliver(Notification $notification): ClinicWhatsAppMessage
    {
        $key = "notification:{$notification->id}";
        $existing = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        $clinic = Clinic::query()->findOrFail($notification->clinic_id);
        $code = (string) $notification->template()->withoutGlobalScopes()->value('code');
        $definition = self::catalog()[$code] ?? null;
        $settings = $this->settings($clinic);
        $patient = $notification->recipient_type === 'patient' ? Patient::query()->withoutGlobalScopes()->find($notification->recipient_id) : null;
        $connection = $this->connectionFor($clinic, $settings);
        $template = $connection && $definition ? $this->templateFor($connection, $clinic, $code) : null;
        $variables = $definition ? $this->variables($definition, (array) ($notification->metadata['placeholders'] ?? []), $clinic) : [];
        $phone = $patient ? self::normalizePhone($patient->phone) : null;

        $skip = match (true) {
            $definition === null || ! $this->carries($clinic, $code) => 'WhatsApp is off for this message.',
            $connection === null => 'No active WhatsApp number for this clinic.',
            $template === null => 'No approved template for this message.',
            $patient === null || $phone === null => 'The patient has no mobile number.',
            ! $this->agreed($patient, $settings) => 'The patient has not agreed to WhatsApp messages.',
            default => null,
        };

        try {
            $message = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->create([
                'clinic_id' => $clinic->id,
                'branch_id' => $notification->branch_id,
                'whatsapp_connection_id' => $connection?->id,
                'whatsapp_template_id' => $template?->id,
                'patient_id' => $patient?->id,
                'notification_id' => $notification->id,
                'direction' => 'outbound',
                'automation_key' => $code,
                'event_key' => $definition['event'] ?? null,
                'idempotency_key' => $key,
                'recipient_phone' => $phone,
                'variables' => $variables,
                'preview' => self::render($template?->body ?? (string) ($definition['body'] ?? ''), $variables),
                'status' => $skip === null ? ClinicWhatsAppMessage::QUEUED : ClinicWhatsAppMessage::SKIPPED,
                'failure_reason' => $skip,
            ]);
        } catch (UniqueConstraintViolationException) {
            return ClinicWhatsAppMessage::query()->withoutGlobalScopes()->where('idempotency_key', $key)->firstOrFail();
        }

        if ($skip === null) {
            SendClinicWhatsAppMessage::dispatch($message->id)->afterCommit();
        }

        return $message;
    }

    /**
     * Send a failed delivery again, on the same message row (so it can never
     * become two messages). Only a `failed` message is retried — a skipped
     * one (WhatsApp off, no template, no agreement) would only skip again —
     * and the checks a send makes are made again: a number the clinic may no
     * longer use, a withdrawn agreement or a template no longer approved make
     * it skipped, with the reason, instead of sent. Audited.
     *
     * @return bool whether the message was queued again
     */
    public function retry(ClinicWhatsAppMessage $message, User $by): bool
    {
        $queued = DB::transaction(function () use ($message) {
            $message = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->lockForUpdate()->find($message->id);
            if (! $message || $message->status !== ClinicWhatsAppMessage::FAILED) {
                return null;
            }

            $clinic = Clinic::query()->findOrFail($message->clinic_id);
            $settings = $this->settings($clinic);
            $connection = $this->connectionFor($clinic, $settings);
            $template = $connection && $message->automation_key ? $this->templateFor($connection, $clinic, $message->automation_key) : null;
            $patient = $message->patient_id ? Patient::query()->withoutGlobalScopes()->find($message->patient_id) : null;

            // A patient who may no longer be contacted is not retried: the
            // message and its patient message become `skipped` with the reason
            // (a lifecycle stop, not a delivery failure).
            if ($message->patient_id && ($lifecycle = app(PatientMessageEligibility::class)->blockReason($patient)) !== null) {
                $message->update(['status' => ClinicWhatsAppMessage::SKIPPED, 'failure_reason' => $lifecycle]);
                if ($message->notification_id) {
                    Notification::query()->whereKey($message->notification_id)->update(['status' => NotificationService::SKIPPED, 'failed_reason' => $lifecycle]);
                }

                return [$message, false];
            }

            $skip = match (true) {
                ! $message->automation_key || ! $this->carries($clinic, $message->automation_key) => 'WhatsApp is off for this message.',
                $connection === null => 'No active WhatsApp number for this clinic.',
                $template === null => 'No approved template for this message.',
                $patient === null || self::normalizePhone($patient->phone) === null => 'The patient has no mobile number.',
                ! $this->agreed($patient, $settings) => 'The patient has not agreed to WhatsApp messages.',
                default => null,
            };

            $message->update($skip === null
                ? ['status' => ClinicWhatsAppMessage::QUEUED, 'attempts' => 0, 'failure_reason' => null, 'failed_at' => null, 'whatsapp_connection_id' => $connection->id, 'whatsapp_template_id' => $template->id]
                : ['status' => ClinicWhatsAppMessage::SKIPPED, 'failure_reason' => $skip]);
            if ($message->notification_id) {
                Notification::query()->whereKey($message->notification_id)->update($skip === null ? ['status' => 'processing', 'failed_reason' => null] : ['status' => 'failed', 'failed_reason' => $skip]);
            }

            return [$message, $skip === null];
        });
        if ($queued === null) {
            return false;
        }

        [$message, $sending] = $queued;
        app(SecurityAudit::class)->record('whatsapp.message_retried', $message, ['automation_key' => $message->automation_key, 'outcome' => $sending ? 'queued' : 'skipped'], ['clinic_id' => $message->clinic_id, 'actor_user_id' => $by->id]);
        if ($sending) {
            SendClinicWhatsAppMessage::dispatch($message->id);
        }

        return $sending;
    }

    public function templateFor(WhatsAppConnection $connection, Clinic $clinic, string $code): ?WhatsAppTemplate
    {
        return WhatsAppTemplate::query()
            ->where('whatsapp_connection_id', $connection->id)->where('automation_key', $code)->where('status', 'approved')
            ->where(fn ($query) => $query->whereNull('clinic_id')->orWhere('clinic_id', $clinic->id))
            ->orderByRaw('clinic_id is null')->orderBy('id')->first();
    }

    /**
     * The patient's agreement: an agreement in force when the clinic requires
     * one, otherwise only that they have not withdrawn it.
     */
    public function agreed(Patient $patient, ClinicWhatsAppSettings $settings): bool
    {
        $consents = fn () => ConsentRecord::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereIn('consent_type', ['whatsapp', 'communication']);

        if ($consents()->inForce()->exists()) {
            return true;
        }

        return ! $settings->require_opt_in && ! $consents()->where('status', ConsentRecord::REVOKED)->exists();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, scalar|null>  $placeholders
     * @return list<string>
     */
    private function variables(array $definition, array $placeholders, Clinic $clinic): array
    {
        $values = [...$placeholders, 'clinic' => $clinic->name];

        return array_map(fn (string $name) => (string) ($values[$name] ?? ''), (array) $definition['variables']);
    }

    /**
     * Why WhatsApp cannot send this message to this patient now, in the
     * words `deliver()` records when it skips; null when it can. Used before
     * a member sends one by hand, so nothing is promised that will not go.
     */
    public function blockedReason(Clinic $clinic, Patient $patient, string $code): ?string
    {
        $settings = $this->settings($clinic);
        $connection = $this->connectionFor($clinic, $settings);

        return match (true) {
            ! array_key_exists($code, self::catalog()) || ! $this->carries($clinic, $code) => 'WhatsApp is off for this message.',
            $connection === null => 'No active WhatsApp number for this clinic.',
            $this->templateFor($connection, $clinic, $code) === null => 'No approved template for this message.',
            self::normalizePhone($patient->phone) === null => 'The patient has no mobile number.',
            ! $this->agreed($patient, $settings) => 'The patient has not agreed to WhatsApp messages.',
            default => null,
        };
    }

    /**
     * The text the patient would receive: the approved template's body (or
     * the catalogue's while none is approved) with the values filled in.
     *
     * @param  array<string, string>  $placeholders
     */
    public function preview(Clinic $clinic, string $code, array $placeholders): string
    {
        $definition = self::catalog()[$code] ?? null;
        if (! $definition) {
            return '';
        }
        $connection = $this->connectionFor($clinic);
        $template = $connection ? $this->templateFor($connection, $clinic, $code) : null;

        return self::render($template?->body ?? (string) ($definition['body'] ?? ''), $this->variables($definition, $placeholders, $clinic));
    }

    /** @param list<string> $variables */
    public static function render(string $body, array $variables): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', fn (array $m) => $variables[(int) $m[1] - 1] ?? $m[0], $body) ?? $body;
    }

    /** A mobile number as WhatsApp wants it (country code, digits only), or null. India is the default country. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }

        return strlen($digits) >= 11 && strlen($digits) <= 15 ? $digits : null;
    }
}
