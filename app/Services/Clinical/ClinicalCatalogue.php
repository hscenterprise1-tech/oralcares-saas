<?php

namespace App\Services\Clinical;

use App\Models\Clinic;
use App\Models\ClinicalCatalogueItem;
use App\Models\ClinicCatalogueSetting;
use App\Models\ClinicSettingAudit;
use App\Models\User;
use App\Support\Clinical\DefaultClinicalCatalogue;
use App\Support\Specialty\PracticeAreas;
use App\Support\Specialty\SpecialtyRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The clinical catalogue as one clinic sees it: the shared OralCares items
 * merged with the clinic's sparse settings, filtered by specialty.
 *
 * Both halves are cached and version-keyed: the shared catalogue once for
 * every clinic, each clinic's settings on their own. A write bumps the
 * version, so a read never sees stale configuration and no cache is ever
 * cleared by pattern. A clinic on the defaults costs one cached lookup.
 *
 * @phpstan-type EffectiveItem array{kind: string, key: string, name: string, category: ?string, severity: ?string, specialty_keys: ?list<string>, practice_area_keys: ?list<string>, position: int, required: bool, enabled: bool, custom: bool}
 */
class ClinicalCatalogue
{
    private const TTL = 86400;

    public const AUDIT_SECTION = 'clinical_catalogue';

    /** @var array<string, array<string, array<string, mixed>>> one view, keyed clinic:globalVersion:clinicVersion */
    private array $memo = [];

    /**
     * The clinic's usable items of one kind, in display order. Disabled and
     * archived items are left out unless asked for (configuration screens).
     *
     * @return list<EffectiveItem>
     */
    public function effective(int $clinicId, string $kind, string|array|null $specialtyKey = null, bool $includeDisabled = false, ?string $practiceArea = null): array
    {
        $this->assertKind($kind);

        $items = array_filter($this->merged($clinicId), function (array $item) use ($kind, $specialtyKey, $includeDisabled, $practiceArea) {
            if ($item['kind'] !== $kind || $item['archived']) {
                return false;
            }
            if (! $includeDisabled && ! $item['enabled']) {
                return false;
            }

            // Several specialties (a patient in more than one): the item applies to any of them.
            $wanted = $specialtyKey === null || $specialtyKey === [] ? null : (array) $specialtyKey;

            if ($wanted !== null && $item['specialty_keys'] !== null && array_intersect($wanted, $item['specialty_keys']) === []) {
                return false;
            }

            // An item naming practice areas applies only to those; with no area known, to its whole specialty.
            return $practiceArea === null || empty($item['practice_area_keys']) || in_array($practiceArea, $item['practice_area_keys'], true);
        });

        usort($items, fn (array $a, array $b) => [$a['position'], $a['name']] <=> [$b['position'], $b['name']]);

        return array_values(array_map(fn (array $item) => collect($item)->except('archived')->all(), $items));
    }

    /** Whether the key names an item at all for this clinic, even a disabled or archived one. */
    public function knows(int $clinicId, string $kind, string $key): bool
    {
        return isset($this->merged($clinicId)["{$kind}:{$key}"]);
    }

    /**
     * Every kind at once, for one configuration payload: kind => items.
     *
     * @param  list<string>  $kinds
     * @return array<string, list<EffectiveItem>>
     */
    public function effectiveKinds(int $clinicId, array $kinds, string|array|null $specialtyKey = null): array
    {
        return collect($kinds)->mapWithKeys(fn (string $kind) => [$kind => $this->effective($clinicId, $kind, $specialtyKey)])->all();
    }

    /**
     * The chosen keys as the clinic's enabled items, or a validation error
     * naming the field. This is what makes a selection server-authoritative.
     *
     * @param  list<string>  $keys
     * @return array<string, EffectiveItem> keyed by item key
     */
    public function resolveKeys(int $clinicId, string $kind, array $keys, string $field, string|array|null $specialtyKey = null): array
    {
        $available = collect($this->effective($clinicId, $kind, $specialtyKey))->keyBy('key');
        $resolved = [];
        foreach (array_values(array_unique($keys)) as $index => $key) {
            if (! $available->has($key)) {
                throw ValidationException::withMessages(["{$field}.{$index}" => 'That option is not available at this clinic.']);
            }
            $resolved[$key] = $available->get($key);
        }

        return $resolved;
    }

    /**
     * Change how the clinic uses an item: rename, reorder, switch off, make
     * required, limit to specialties, archive. Audited; writes only the
     * columns that differ from the catalogue.
     *
     * @param  array{name?: ?string, category?: ?string, severity?: ?string, specialty_keys?: ?list<string>, practice_area_keys?: ?list<string>, position?: ?int, enabled?: ?bool, required?: ?bool, archived?: bool}  $changes
     */
    public function configure(Clinic $clinic, ?User $actor, string $kind, string $key, array $changes): void
    {
        $this->assertKind($kind);
        $this->assertChanges($changes);

        $global = collect($this->globalItems())->first(fn (array $item) => $item['kind'] === $kind && $item['key'] === $key);
        $setting = ClinicCatalogueSetting::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('kind', $kind)->where('key', $key)->first();
        if (! $global && ! $setting) {
            throw new InvalidArgumentException("No {$kind} “{$key}” in the catalogue or this clinic.");
        }

        $before = $this->auditView($clinic->id, $kind, $key);
        $current = $this->merged($clinic->id)["{$kind}:{$key}"] ?? null;
        $this->assertDomain(
            array_key_exists('specialty_keys', $changes) ? $changes['specialty_keys'] : ($current['specialty_keys'] ?? null),
            array_key_exists('practice_area_keys', $changes) ? $changes['practice_area_keys'] : ($current['practice_area_keys'] ?? null),
            $global ? $global['specialty_keys'] : null,
        );
        $this->assertCategory($clinic->id, $kind, $changes);

        DB::transaction(function () use ($clinic, $actor, $kind, $key, $changes, $global, $setting) {
            $setting ??= new ClinicCatalogueSetting(['clinic_id' => $clinic->id, 'kind' => $kind, 'key' => $key, 'catalogue_item_id' => $global['id'] ?? null]);
            $map = ['name' => 'name', 'category' => 'category', 'severity' => 'severity', 'specialty_keys' => 'specialty_keys', 'practice_area_keys' => 'practice_area_keys', 'position' => 'position', 'enabled' => 'is_enabled', 'required' => 'is_required'];
            foreach ($map as $input => $column) {
                if (array_key_exists($input, $changes)) {
                    $setting->{$column} = $changes[$input];
                }
            }
            if (array_key_exists('archived', $changes)) {
                $setting->archived_at = $changes['archived'] ? now() : null;
            }
            $setting->updated_by = $actor?->id;
            $setting->save();
        });

        $this->bump($clinic->id);
        $this->audit($clinic, $actor, $kind, $key, $before, $this->auditView($clinic->id, $kind, $key));
    }

    /**
     * An item of the clinic's own, beside the shared ones. Its key is
     * prefixed so a later shared item can never collide with it.
     *
     * @param  array{category?: ?string, severity?: ?string, specialty_keys?: ?list<string>, practice_area_keys?: ?list<string>, position?: ?int, required?: ?bool}  $attributes
     */
    public function addCustom(Clinic $clinic, ?User $actor, string $kind, string $name, array $attributes = []): string
    {
        $this->assertKind($kind);
        $this->assertChanges($attributes);
        $this->assertDomain($attributes['specialty_keys'] ?? null, $attributes['practice_area_keys'] ?? null, null);
        $this->assertCategory($clinic->id, $kind, $attributes);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Give the item a name of up to 120 characters.']);
        }

        $base = 'c_'.Str::limit(Str::slug($name, '_'), 56, '');
        $key = $base;
        for ($suffix = 2; ClinicCatalogueSetting::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('kind', $kind)->where('key', $key)->exists(); $suffix++) {
            $key = "{$base}_{$suffix}";
        }

        ClinicCatalogueSetting::withoutGlobalScopes()->create([
            'clinic_id' => $clinic->id,
            'kind' => $kind,
            'key' => $key,
            'name' => $name,
            'category' => $attributes['category'] ?? null,
            'severity' => $attributes['severity'] ?? null,
            'specialty_keys' => $attributes['specialty_keys'] ?? null,
            'practice_area_keys' => $attributes['practice_area_keys'] ?? null,
            'position' => $attributes['position'] ?? null,
            'is_enabled' => true,
            'is_required' => $attributes['required'] ?? null,
            'updated_by' => $actor?->id,
        ]);

        $this->bump($clinic->id);
        $this->audit($clinic, $actor, $kind, $key, null, $this->auditView($clinic->id, $kind, $key));

        return $key;
    }

    /** Call after changing the shared catalogue itself (a seeder, an import). */
    public function forgetGlobal(): void
    {
        Cache::forever('clinical_catalogue:version', $this->globalVersion() + 1);
        $this->memo = [];
    }

    /**
     * Shared items merged with this clinic's settings, keyed "kind:key".
     *
     * @return array<string, array<string, mixed>>
     */
    private function merged(int $clinicId): array
    {
        // Keyed by both versions: an instance made before a change never serves the old view.
        $memoKey = $clinicId.':'.$this->globalVersion().':'.$this->clinicVersion($clinicId);
        if (! isset($this->memo[$memoKey])) {
            $this->memo = [];
        }

        return $this->memo[$memoKey] ??= (function () use ($clinicId) {
            $merged = [];
            foreach ($this->globalItems() as $item) {
                $merged["{$item['kind']}:{$item['key']}"] = [
                    'kind' => $item['kind'],
                    'key' => $item['key'],
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'severity' => $item['default_severity'],
                    'specialty_keys' => $item['specialty_keys'],
                    'practice_area_keys' => $item['practice_area_keys'],
                    'position' => $item['position'],
                    'required' => false,
                    'enabled' => $item['is_active'],
                    'custom' => false,
                    'archived' => $item['archived'],
                ];
            }

            foreach ($this->clinicSettings($clinicId) as $setting) {
                $id = "{$setting['kind']}:{$setting['key']}";
                $base = $merged[$id] ?? null;
                if (! $base && $setting['catalogue_item_id'] !== null) {
                    continue; // A setting for a shared item that no longer exists.
                }
                $base ??= ['kind' => $setting['kind'], 'key' => $setting['key'], 'name' => $setting['key'], 'category' => null, 'severity' => null, 'specialty_keys' => null, 'practice_area_keys' => null, 'position' => 999, 'required' => false, 'enabled' => true, 'custom' => true, 'archived' => false];

                $merged[$id] = [
                    ...$base,
                    'name' => $setting['name'] ?? $base['name'],
                    'category' => $setting['category'] ?? $base['category'],
                    'severity' => $setting['severity'] ?? $base['severity'],
                    'specialty_keys' => $setting['specialty_keys'] ?? $base['specialty_keys'],
                    'practice_area_keys' => $setting['practice_area_keys'] ?? $base['practice_area_keys'],
                    'position' => $setting['position'] ?? $base['position'],
                    'required' => $setting['is_required'] ?? $base['required'],
                    // A shared item the catalogue retired stays off whatever the clinic says.
                    'enabled' => $base['custom'] ? ($setting['is_enabled'] ?? true) : ($base['enabled'] && ($setting['is_enabled'] ?? true)),
                    'archived' => $base['archived'] || $setting['archived_at'] !== null,
                ];
            }

            return $merged;
        })();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function globalItems(): array
    {
        return Cache::remember('clinical_catalogue:items:v'.$this->globalVersion(), self::TTL, fn () => ClinicalCatalogueItem::query()
            ->orderBy('kind')->orderBy('position')
            ->get(['id', 'kind', 'key', 'name', 'category', 'default_severity', 'specialty_keys', 'practice_area_keys', 'position', 'is_active', 'archived_at'])
            ->map(fn (ClinicalCatalogueItem $item) => [
                'id' => $item->id,
                'kind' => $item->kind,
                'key' => $item->key,
                'name' => $item->name,
                'category' => $item->category,
                'default_severity' => $item->default_severity,
                'specialty_keys' => $item->specialty_keys,
                'practice_area_keys' => $item->practice_area_keys,
                'position' => $item->position,
                'is_active' => $item->is_active,
                'archived' => $item->archived_at !== null,
            ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function clinicSettings(int $clinicId): array
    {
        return Cache::remember("clinical_catalogue:clinic:{$clinicId}:v".$this->clinicVersion($clinicId), self::TTL, fn () => ClinicCatalogueSetting::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->get(['kind', 'key', 'catalogue_item_id', 'name', 'category', 'severity', 'specialty_keys', 'practice_area_keys', 'position', 'is_enabled', 'is_required', 'archived_at'])
            ->map(fn (ClinicCatalogueSetting $setting) => [
                'kind' => $setting->kind,
                'key' => $setting->key,
                'catalogue_item_id' => $setting->catalogue_item_id,
                'name' => $setting->name,
                'category' => $setting->category,
                'severity' => $setting->severity,
                'specialty_keys' => $setting->specialty_keys,
                'practice_area_keys' => $setting->practice_area_keys,
                'position' => $setting->position,
                'is_enabled' => $setting->is_enabled,
                'is_required' => $setting->is_required,
                'archived_at' => $setting->archived_at?->toIso8601String(),
            ])->all());
    }

    private function globalVersion(): int
    {
        return (int) Cache::get('clinical_catalogue:version', 1);
    }

    private function clinicVersion(int $clinicId): int
    {
        return (int) Cache::get("clinical_catalogue:clinic_version:{$clinicId}", 1);
    }

    private function bump(int $clinicId): void
    {
        Cache::forever("clinical_catalogue:clinic_version:{$clinicId}", $this->clinicVersion($clinicId) + 1);
        $this->memo = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function auditView(int $clinicId, string $kind, string $key): ?array
    {
        $this->memo = [];
        $item = $this->merged($clinicId)["{$kind}:{$key}"] ?? null;

        return $item ? collect($item)->only(['name', 'category', 'severity', 'specialty_keys', 'practice_area_keys', 'position', 'required', 'enabled', 'archived'])->all() : null;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(Clinic $clinic, ?User $actor, string $kind, string $key, ?array $before, ?array $after): void
    {
        if ($before === $after) {
            return;
        }

        ClinicSettingAudit::create([
            'clinic_id' => $clinic->id,
            'user_id' => $actor?->id,
            'section' => self::AUDIT_SECTION,
            'old_values' => $before === null ? null : ['kind' => $kind, 'key' => $key, ...$before],
            'new_values' => $after === null ? null : ['kind' => $kind, 'key' => $key, ...$after],
        ]);
    }

    /**
     * Keep an item inside the clinical domain it belongs to: its practice
     * areas must belong to the module of its specialties (an Orthodontics
     * reason stays Dental), and a shared item can be narrowed but never moved
     * to another module. Clinics choose what is offered; they cannot turn an
     * orthodontic reason into a physiotherapy one.
     *
     * @param  list<string>|null  $specialtyKeys
     * @param  list<string>|null  $areaKeys
     * @param  list<string>|null  $sharedSpecialtyKeys  the shared item's own specialties, when it is one
     */
    private function assertDomain(?array $specialtyKeys, ?array $areaKeys, ?array $sharedSpecialtyKeys): void
    {
        $areas = app(PracticeAreas::class);
        $registry = app(SpecialtyRegistry::class);
        if ($specialtyKeys !== null && $registry->normalize($specialtyKeys) !== array_values($specialtyKeys)) {
            throw ValidationException::withMessages(['specialty_keys' => 'Choose specialties from the list.']);
        }
        $modules = fn (?array $keys) => $keys === null ? null : collect($keys)->map(fn (string $key) => $areas->moduleOfSpecialty($key))->filter()->unique()->values()->all();
        $own = $modules($specialtyKeys);

        if ($areaKeys !== null && $areaKeys !== []) {
            if ($own === null || $own === []) {
                throw ValidationException::withMessages(['practice_area_keys' => 'Choose the specialty before limiting it to practice areas.']);
            }
            foreach ($areaKeys as $area) {
                if (! in_array($areas->get($area)['module'] ?? null, $own, true)) {
                    throw ValidationException::withMessages(['practice_area_keys' => 'A practice area must belong to the item\'s own specialty.']);
                }
            }
        }

        $shared = $modules($sharedSpecialtyKeys);
        if ($shared !== null && $own !== null && array_diff($own, $shared) !== []) {
            throw ValidationException::withMessages(['specialty_keys' => 'A built-in item stays in its own specialty; add your own item instead.']);
        }
    }

    /**
     * A visit reason's category is the visit type it implies: one the clinic has.
     *
     * @param  array<string, mixed>  $changes
     */
    private function assertCategory(int $clinicId, string $kind, array $changes): void
    {
        if ($kind !== DefaultClinicalCatalogue::KIND_VISIT_REASON || ! isset($changes['category'])) {
            return;
        }
        if (! collect($this->effective($clinicId, DefaultClinicalCatalogue::KIND_VISIT_TYPE, null, true))->contains('key', $changes['category'])) {
            throw ValidationException::withMessages(['category' => 'Choose one of the clinic\'s visit types.']);
        }
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, DefaultClinicalCatalogue::KINDS, true)) {
            throw new InvalidArgumentException("Unknown catalogue kind “{$kind}”.");
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function assertChanges(array $changes): void
    {
        if (isset($changes['severity']) && ! in_array($changes['severity'], DefaultClinicalCatalogue::SEVERITIES, true)) {
            throw ValidationException::withMessages(['severity' => 'Choose low, moderate, high or critical.']);
        }
        if (isset($changes['specialty_keys']) && ! array_is_list($changes['specialty_keys'])) {
            throw ValidationException::withMessages(['specialty_keys' => 'Specialties must be a list.']);
        }
        if (isset($changes['practice_area_keys']) && (! array_is_list($changes['practice_area_keys']) || array_diff($changes['practice_area_keys'], array_keys(app(PracticeAreas::class)->all())) !== [])) {
            throw ValidationException::withMessages(['practice_area_keys' => 'Choose practice areas from the list.']);
        }
    }
}
