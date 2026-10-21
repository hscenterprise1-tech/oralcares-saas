<?php

namespace App\Services\Patient;

use App\Models\Clinic;
use App\Models\ClinicConsentType;
use App\Models\ClinicConsentTypeVersion;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use App\Support\Specialty\SpecialtyRegistry;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one writer of a clinic's consent types and their wording.
 *
 * A clinic starts with the defaults (`config/clinic_consents.php`), created
 * the first time they are asked for. Changing a wording writes the next
 * version and never touches an earlier one, so every signed consent keeps
 * the exact text it was given against. A specialty's types are offered only
 * while the clinic has that specialty (its plan sells it and it practises it).
 */
class ConsentTypes
{
    public function __construct(
        protected SpecialtyRegistry $specialties,
        protected SecurityAudit $audit,
    ) {}

    /**
     * Every type of the clinic, its defaults created first.
     *
     * @return Collection<int, ClinicConsentType>
     */
    public function all(Clinic $clinic): Collection
    {
        $this->ensureDefaults($clinic);

        return ClinicConsentType::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->orderBy('id')->get();
    }

    /**
     * The types a patient can be asked for now: active, and shared or of a
     * specialty the clinic has.
     *
     * @return Collection<int, ClinicConsentType>
     */
    public function available(Clinic $clinic): Collection
    {
        $specialties = $this->specialties->forClinic($clinic);

        return $this->all($clinic)->filter(fn (ClinicConsentType $type) => $type->is_active && ($type->specialty_key === null || in_array($type->specialty_key, $specialties, true)))->values();
    }

    /** The wording a new consent of this type is given against. */
    public function current(ClinicConsentType $type): ?ClinicConsentTypeVersion
    {
        return ClinicConsentTypeVersion::query()->where('clinic_consent_type_id', $type->id)->where('version', $type->current_version)->first();
    }

    /** Change the label and, when the wording changes, write the next version. */
    public function update(ClinicConsentType $type, string $label, string $text, User $by): ClinicConsentType
    {
        $text = trim($text);
        if ($text === '') {
            throw new DomainException('A consent needs its wording.');
        }

        return DB::transaction(function () use ($type, $label, $text, $by) {
            $type = ClinicConsentType::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($type->id);
            $current = $this->current($type);
            $changed = $current === null || ! hash_equals($current->text_hash, hash('sha256', $text));
            $type->update(['label' => mb_substr(trim($label), 0, 120), 'current_version' => $changed && $current ? $type->current_version + 1 : $type->current_version]);
            if ($changed) {
                $this->writeVersion($type, $text, $by);
            }
            $this->audit->record('consent_type.updated', $type, ['key' => $type->key, 'version' => $type->current_version, 'wording_changed' => $changed], ['clinic_id' => $type->clinic_id]);

            return $type;
        });
    }

    /** A consent of the clinic's own. */
    public function add(Clinic $clinic, string $label, string $text, ?string $specialty, User $by): ClinicConsentType
    {
        if ($specialty !== null && ! in_array($specialty, $this->specialties->forClinic($clinic), true)) {
            throw new DomainException('That specialty is not one this clinic has.');
        }
        $key = Str::slug($label, '_') ?: 'consent';
        $exists = fn (string $candidate) => ClinicConsentType::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('key', $candidate)->exists();
        $candidate = $key;
        for ($i = 2; $exists($candidate); $i++) {
            $candidate = "{$key}_{$i}";
        }

        return DB::transaction(function () use ($clinic, $candidate, $label, $text, $specialty, $by) {
            $type = ClinicConsentType::query()->withoutGlobalScopes()->create([
                'clinic_id' => $clinic->id, 'key' => mb_substr($candidate, 0, 60), 'label' => mb_substr(trim($label), 0, 120), 'specialty_key' => $specialty, 'current_version' => 1, 'is_active' => true,
            ]);
            $this->writeVersion($type, trim($text), $by);
            $this->audit->record('consent_type.created', $type, ['key' => $type->key], ['clinic_id' => $clinic->id]);

            return $type;
        });
    }

    public function setActive(ClinicConsentType $type, bool $active, User $by): ClinicConsentType
    {
        $type->update(['is_active' => $active]);
        $this->audit->record($active ? 'consent_type.activated' : 'consent_type.deactivated', $type, ['key' => $type->key], ['clinic_id' => $type->clinic_id]);

        return $type;
    }

    private function ensureDefaults(Clinic $clinic): void
    {
        $have = ClinicConsentType::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->pluck('key')->all();
        $missing = array_diff_key((array) config('clinic_consents.defaults', []), array_flip($have));
        foreach ($missing as $key => $default) {
            DB::transaction(function () use ($clinic, $key, $default) {
                $type = ClinicConsentType::query()->withoutGlobalScopes()->firstOrCreate(
                    ['clinic_id' => $clinic->id, 'key' => $key],
                    ['label' => $default['label'], 'specialty_key' => $default['specialty'], 'current_version' => 1, 'is_active' => true],
                );
                if ($type->wasRecentlyCreated) {
                    $this->writeVersion($type, (string) $default['text'], null);
                }
            });
        }
    }

    private function writeVersion(ClinicConsentType $type, string $text, ?User $by): ClinicConsentTypeVersion
    {
        return ClinicConsentTypeVersion::query()->create([
            'clinic_consent_type_id' => $type->id,
            'clinic_id' => $type->clinic_id,
            'version' => $type->current_version,
            'text' => $text,
            'text_hash' => hash('sha256', $text),
            'created_by' => $by?->id,
        ]);
    }
}
