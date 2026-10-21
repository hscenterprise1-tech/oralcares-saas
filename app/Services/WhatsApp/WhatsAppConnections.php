<?php

namespace App\Services\WhatsApp;

use App\Models\Clinic;
use App\Models\ClinicWhatsAppSettings;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Services\Marketing\WhatsAppManager;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * OralCares' side of clinic WhatsApp: the technical connections. Only
 * company staff reach this (`clinic-whatsapp.manage`); a clinic never sees a
 * credential. One active common number serves every clinic in Common mode;
 * a clinic-owned number serves only its clinic. Every change is audited.
 */
class WhatsAppConnections
{
    public function __construct(
        protected WhatsAppManager $whatsApp,
        protected SecurityAudit $audit,
    ) {}

    /**
     * Create or update a connection. A clinic-owned one names its clinic; the
     * common one names none. Credentials left blank are kept.
     *
     * @param  array{mode: string, clinic_id?: ?int, label: string, display_phone_number?: ?string, phone_number_id?: ?string, business_account_id?: ?string, access_token?: ?string, webhook_verify_token?: ?string}  $data
     */
    public function save(array $data, User $by, ?WhatsAppConnection $connection = null): WhatsAppConnection
    {
        $owned = $data['mode'] === WhatsAppConnection::OWNED;
        if ($owned && empty($data['clinic_id'])) {
            throw new DomainException('A clinic’s own number must belong to a clinic.');
        }
        if ($owned && WhatsAppConnection::query()->where('mode', WhatsAppConnection::OWNED)->where('clinic_id', $data['clinic_id'])
            ->when($connection, fn ($query) => $query->whereKeyNot($connection->id))->exists()) {
            throw new DomainException('This clinic already has its own number. Edit that one instead.');
        }

        $attributes = [
            'mode' => $data['mode'],
            'clinic_id' => $owned ? (int) $data['clinic_id'] : null,
            'label' => $data['label'],
            'display_phone_number' => $data['display_phone_number'] ?? null,
            'phone_number_id' => $data['phone_number_id'] ?? null,
            'business_account_id' => $data['business_account_id'] ?? null,
        ];
        foreach (['access_token', 'webhook_verify_token'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $attributes[$secret] = $data[$secret];
            }
        }

        $connection = $connection ? tap($connection)->update($attributes) : WhatsAppConnection::query()->create($attributes + ['status' => 'pending']);
        $this->audit->record('whatsapp.connection_saved', $connection, ['mode' => $connection->mode, 'clinic_id' => $connection->clinic_id, 'credentials_changed' => filled($data['access_token'] ?? null)]);

        return $connection;
    }

    /**
     * Check the credentials with Meta, then switch the number on. Only one
     * common number is active at a time.
     */
    public function activate(WhatsAppConnection $connection, User $by): WhatsAppConnection
    {
        $healthy = $this->whatsApp->driver('meta')->testConnection($connection);
        $connection->update(['last_checked_at' => now(), 'health_message' => $healthy ? 'Meta accepted the credentials.' : 'Meta did not accept the credentials.']);
        if (! $healthy) {
            throw new DomainException('Meta did not accept these credentials. Check the phone number ID and access token.');
        }

        DB::transaction(function () use ($connection, $by) {
            if ($connection->isShared()) {
                WhatsAppConnection::query()->where('mode', WhatsAppConnection::SHARED)->whereKeyNot($connection->id)->active()->update(['status' => 'disabled']);
            }
            $connection->update(['status' => WhatsAppConnection::ACTIVE, 'activated_at' => now(), 'activated_by' => $by->id]);
        });
        $this->audit->record('whatsapp.connection_activated', $connection, ['mode' => $connection->mode, 'clinic_id' => $connection->clinic_id]);

        return $connection;
    }

    public function disable(WhatsAppConnection $connection, User $by): WhatsAppConnection
    {
        $connection->update(['status' => 'disabled']);
        $this->audit->record('whatsapp.connection_disabled', $connection, ['mode' => $connection->mode, 'clinic_id' => $connection->clinic_id]);

        return $connection;
    }

    /** Ask Meta whether the credentials still work. */
    public function check(WhatsAppConnection $connection): bool
    {
        $healthy = $this->whatsApp->driver('meta')->testConnection($connection);
        $connection->update(['last_checked_at' => now(), 'health_message' => $healthy ? 'Meta accepted the credentials.' : 'Meta did not accept the credentials.']);

        return $healthy;
    }

    /**
     * Register the approved template an automation uses on this number. A
     * clinic copy (clinic_id) overrides the shared one for that clinic only.
     *
     * @param  array{automation_key: string, name: string, language?: ?string, body: string, clinic_id?: ?int}  $data
     */
    public function saveTemplate(WhatsAppConnection $connection, array $data, User $by): WhatsAppTemplate
    {
        $definition = ClinicWhatsApp::catalog()[$data['automation_key']] ?? throw new DomainException('Unknown automation.');
        $expected = count((array) $definition['variables']);
        preg_match_all('/\{\{(\d+)\}\}/', $data['body'], $found);
        if ($found[1] !== [] && max(array_map('intval', $found[1])) > $expected) {
            throw new DomainException("This message carries {$expected} values ({{1}} to {{{$expected}}}).");
        }
        $clinicId = $connection->isShared() ? ($data['clinic_id'] ?? null) : $connection->clinic_id;

        $template = WhatsAppTemplate::query()->updateOrCreate(
            ['whatsapp_connection_id' => $connection->id, 'automation_key' => $data['automation_key'], 'clinic_id' => $clinicId, 'language' => $data['language'] ?? 'en'],
            ['name' => $data['name'], 'body' => $data['body'], 'variables' => $definition['variables'], 'status' => 'approved', 'category' => 'UTILITY'],
        );
        $this->audit->record('whatsapp.template_saved', $template, ['automation' => $template->automation_key, 'connection_id' => $connection->id]);

        return $template;
    }

    /** Register OralCares' default text for every automation the number has no template for yet. */
    public function seedDefaultTemplates(WhatsAppConnection $connection, User $by): int
    {
        $created = 0;
        foreach (ClinicWhatsApp::catalog() as $key => $definition) {
            $exists = WhatsAppTemplate::query()->where('whatsapp_connection_id', $connection->id)->where('automation_key', $key)->whereNull('clinic_id')->exists();
            if (! $exists) {
                $this->saveTemplate($connection, ['automation_key' => $key, 'name' => 'oc_'.$key, 'body' => (string) $definition['body']], $by);
                $created++;
            }
        }

        return $created;
    }

    /** The clinics waiting for their own number. */
    public function pendingRequests(): Collection
    {
        return ClinicWhatsAppSettings::query()->withoutGlobalScopes()->where('mode', ClinicWhatsAppSettings::OWN)->whereNotNull('own_requested_at')
            ->whereNotIn('clinic_id', WhatsAppConnection::query()->where('mode', WhatsAppConnection::OWNED)->active()->select('clinic_id'))
            ->orderBy('own_requested_at')->limit(100)->get()
            ->map(fn (ClinicWhatsAppSettings $settings) => [
                'clinic_id' => $settings->clinic_id,
                'clinic' => Clinic::query()->whereKey($settings->clinic_id)->value('name'),
                'requested_at' => $settings->own_requested_at?->toIso8601String(),
                'note' => $settings->own_request_note,
            ]);
    }
}
