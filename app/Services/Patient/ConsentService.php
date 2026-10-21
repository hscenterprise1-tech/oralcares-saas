<?php

namespace App\Services\Patient;

use App\ClinicPermission;
use App\Models\Clinic;
use App\Models\ClinicConsentType;
use App\Models\ConsentRecord;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of patient consents. A consent is evidence: the type, the
 * text agreed to (its version and a SHA-256 of the exact wording), how and by
 * whom it was captured, and when. Revoking keeps the record and marks it;
 * agreeing again is a new record. The security trail is written by the
 * clinical audit observer (`consent.created`, `consent.revoked`).
 */
class ConsentService
{
    public function __construct(protected ConsentTypes $consentTypes) {}

    /**
     * The consent types this clinic asks for now (`ConsentTypes`): its own
     * list, with a specialty's types only while it has that specialty.
     *
     * @return list<string>
     */
    public function types(Clinic $clinic): array
    {
        return $this->consentTypes->available($clinic)->pluck('key')->all();
    }

    /**
     * The same, with the wording a patient is shown.
     *
     * @return list<array{key: string, label: string, specialty: ?string, version: int, text: ?string}>
     */
    public function options(Clinic $clinic): array
    {
        return $this->consentTypes->available($clinic)->map(fn (ClinicConsentType $type) => [
            'key' => $type->key,
            'label' => $type->label,
            'specialty' => $type->specialty_key,
            'version' => $type->current_version,
            'text' => $this->consentTypes->current($type)?->text,
        ])->values()->all();
    }

    /**
     * The version and hash of the wording a consent of this type is given
     * against now.
     *
     * @return array{version: ?string, text_hash: ?string}
     */
    public function evidence(Clinic $clinic, string $type): array
    {
        $record = $this->consentTypes->available($clinic)->firstWhere('key', $type);
        $current = $record ? $this->consentTypes->current($record) : null;

        return ['version' => $current ? (string) $current->version : null, 'text_hash' => $current?->text_hash];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Patient $patient, User $by): array
    {
        $this->assertCanView($by, $patient);

        return ConsentRecord::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->with(['capturedBy:id,name'])->latest('id')->limit(200)->get()
            ->map(fn (ConsentRecord $c) => [
                'id' => $c->id,
                'type' => $c->consent_type,
                'version' => $c->consent_version,
                'text_hash' => $c->text_hash,
                'status' => $c->revoked_at ? ConsentRecord::REVOKED : $c->status,
                'method' => $c->capture_method,
                'captured_by' => $c->capturedBy?->name,
                'captured_at' => ($c->captured_at ?? $c->signed_at)?->toIso8601String(),
                'revoked_at' => $c->revoked_at?->toIso8601String(),
                'revocation_reason' => $c->revocation_reason,
            ])->values()->all();
    }

    /**
     * @param  array{type: string, method: string, version?: ?string, text?: ?string}  $data
     */
    public function capture(Patient $patient, array $data, User $by): ConsentRecord
    {
        $this->assertCanManage($by, $patient);
        $clinic = Clinic::query()->findOrFail($patient->clinic_id);
        if (! in_array($data['type'], $this->types($clinic), true)) {
            throw new InvalidArgumentException("“{$data['type']}” is not a consent type this clinic records.");
        }
        if (! in_array($data['method'], ConsentRecord::METHODS, true)) {
            throw new InvalidArgumentException('Choose how the consent was given.');
        }
        // The wording agreed to: the form's own when one was signed (a paper
        // form, an imported record), otherwise the clinic's current version.
        $text = trim((string) ($data['text'] ?? ''));
        $evidence = $text !== ''
            ? ['version' => $data['version'] ?? null, 'text_hash' => hash('sha256', $text)]
            : $this->evidence($clinic, $data['type']);

        return DB::transaction(function () use ($patient, $data, $evidence, $by) {
            $now = now();
            $consent = ConsentRecord::query()->withoutGlobalScopes()->create([
                'clinic_id' => $patient->clinic_id,
                'patient_id' => $patient->id,
                'consent_type' => $data['type'],
                'consent_version' => $evidence['version'],
                'text_hash' => $evidence['text_hash'],
                'status' => ConsentRecord::GRANTED,
                'capture_method' => $data['method'],
                'captured_by' => $by->id,
                'captured_at' => $now,
                'signed_at' => $now,
            ]);
            $this->timeline($patient, $by, 'consent_captured', ['consent_id' => $consent->id, 'type' => $consent->consent_type, 'method' => $consent->capture_method]);

            return $consent;
        });
    }

    public function revoke(ConsentRecord $consent, string $reason, User $by): ConsentRecord
    {
        $patient = Patient::query()->withoutGlobalScopes()->findOrFail($consent->patient_id);
        $this->assertCanManage($by, $patient);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('Give a reason.');
        }

        return DB::transaction(function () use ($consent, $patient, $reason, $by) {
            $locked = ConsentRecord::query()->withoutGlobalScopes()->whereKey($consent->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null) {
                throw new DomainException('This consent was already revoked.');
            }
            $locked->update(['status' => ConsentRecord::REVOKED, 'revoked_at' => now(), 'revoked_by' => $by->id, 'revocation_reason' => mb_substr($reason, 0, 500)]);
            $this->timeline($patient, $by, 'consent_revoked', ['consent_id' => $locked->id, 'type' => $locked->consent_type, 'reason' => $reason]);

            return $locked;
        });
    }

    private function assertCanView(User $user, Patient $patient): void
    {
        if (! $user->can('view', $patient)) {
            throw new AuthorizationException('You cannot open this patient.');
        }
    }

    private function assertCanManage(User $user, Patient $patient): void
    {
        $this->assertCanView($user, $patient);
        if (! $user->hasClinicPermission(ClinicPermission::ConsentManage)) {
            throw new AuthorizationException('You cannot record consents.');
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function timeline(Patient $patient, User $by, string $event, array $context): void
    {
        PatientTimelineEvent::query()->withoutGlobalScopes()->create([
            'organization_id' => $patient->organization_id, 'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id,
            'actor_id' => $by->id, 'event_type' => $event, 'context' => $context,
        ]);
    }
}
