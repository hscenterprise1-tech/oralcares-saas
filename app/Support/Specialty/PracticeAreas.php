<?php

namespace App\Support\Specialty;

/**
 * Healthcare modules and their practice areas (`config/clinic_specialties.php`).
 *
 * Module → specialty → practice area are separate ideas: Dental is the
 * module, Orthodontics is a practice area within it, and the registry
 * specialty a visit is recorded under is what the clinic practises
 * (`orthodontics` if the clinic offers it, else Dental's root `dental`).
 * Qualifications are none of these. Core code asks this class for an area's
 * module, label, capabilities or workspace focus and never branches on an
 * area name.
 */
class PracticeAreas
{
    /** @var array<string, string>|null normalised name → area key */
    private ?array $index = null;

    public function __construct(protected SpecialtyRegistry $registry) {}

    /**
     * @return array<string, array{label: string, specialty: string, default_area: string, capabilities: array<string, string>}>
     */
    public function modules(): array
    {
        return config('clinic_specialties.modules', []);
    }

    /**
     * @return array{label: string, specialty: string, default_area: string, capabilities: array<string, string>}|null
     */
    public function module(?string $key): ?array
    {
        return $key !== null ? ($this->modules()[$key] ?? null) : null;
    }

    public function moduleLabel(?string $key): ?string
    {
        return $this->module($key)['label'] ?? null;
    }

    /** The module a registry specialty belongs to (its `plan_module`, else itself), or null. */
    public function moduleOfSpecialty(?string $specialty): ?string
    {
        $key = $specialty !== null ? ($this->registry->normalize([$specialty])[0] ?? null) : null;
        if ($key === null) {
            return null;
        }
        $module = $this->registry->get($key)['plan_module'] ?? $key;

        return isset($this->modules()[$module]) ? $module : null;
    }

    /**
     * Every practice area, keyed, with its defaults filled in.
     *
     * @return array<string, array{module: string, label: string, short_label: string, specialty: string, focus: string, capabilities: array<string, string>, aliases: list<string>}>
     */
    public function all(): array
    {
        $areas = [];
        foreach (config('clinic_specialties.practice_areas', []) as $key => $area) {
            $module = $this->module($area['module'] ?? null);
            if ($module === null) {
                continue;
            }
            $areas[$key] = [
                'module' => $area['module'],
                'label' => $area['label'],
                'short_label' => $area['short_label'] ?? $area['label'],
                'specialty' => $area['specialty'] ?? $module['specialty'],
                'focus' => $area['focus'] ?? 'general',
                'capabilities' => $area['capabilities'] ?? [],
                'aliases' => $area['aliases'] ?? [],
            ];
        }

        return $areas;
    }

    /**
     * @return array{module: string, label: string, short_label: string, specialty: string, focus: string, capabilities: array<string, string>, aliases: list<string>}|null
     */
    public function get(?string $key): ?array
    {
        return $key !== null ? ($this->all()[$key] ?? null) : null;
    }

    /**
     * The areas of one module, in registry order.
     *
     * @return array<string, array{module: string, label: string, short_label: string, specialty: string, focus: string, capabilities: array<string, string>, aliases: list<string>}>
     */
    public function ofModule(string $module): array
    {
        return array_filter($this->all(), fn (array $area) => $area['module'] === $module);
    }

    /**
     * The registry specialties an area can be recorded under, most specific
     * first: the area's own specialty, then its module's root.
     *
     * @return list<string>
     */
    public function specialtiesFor(string $area): array
    {
        $definition = $this->get($area);
        if ($definition === null) {
            return [];
        }

        return array_values(array_unique(array_filter([$definition['specialty'], $this->module($definition['module'])['specialty'] ?? null])));
    }

    /**
     * What a practitioner of these areas can do: each area's module
     * capabilities plus the area's own, keyed.
     *
     * @param  list<string>  $areas
     * @return array<string, string>
     */
    public function capabilities(array $areas): array
    {
        $capabilities = [];
        foreach ($areas as $key) {
            $area = $this->get($key);
            if ($area === null) {
                continue;
            }
            $capabilities += $this->module($area['module'])['capabilities'] ?? [];
            $capabilities += $area['capabilities'];
        }

        return $capabilities;
    }

    /**
     * Every capability a module's practitioners can have: the module's own
     * and each of its areas', with the area each belongs to (null: the
     * whole module). What a treatment may be limited to.
     *
     * @return array<string, array{label: string, area: ?string}>
     */
    public function capabilitiesOfModule(string $module): array
    {
        $all = collect($this->module($module)['capabilities'] ?? [])->map(fn (string $label) => ['label' => $label, 'area' => null])->all();
        foreach ($this->ofModule($module) as $key => $area) {
            foreach ($area['capabilities'] as $capability => $label) {
                $all[$capability] ??= ['label' => $label, 'area' => $key];
            }
        }

        return $all;
    }

    /**
     * The area a specialty name or title means ("Conservative Dentistry &
     * Endodontics", "Orthodontist"), by key, label or alias; null when it
     * names no area.
     */
    public function match(?string $name): ?string
    {
        $normalised = self::normalise($name);
        if ($normalised === '') {
            return null;
        }

        if ($this->index === null) {
            $this->index = [];
            foreach ($this->all() as $key => $area) {
                foreach ([$key, $area['label'], $area['short_label'], ...$area['aliases']] as $spelling) {
                    $this->index[self::normalise($spelling)] ??= $key;
                }
            }
        }

        return $this->index[$normalised] ?? null;
    }

    /**
     * The area a broad professional title means: an area by name, else the
     * default area of the module the title's specialty belongs to
     * ("Dentist" → General Dentistry).
     */
    public function forTitle(?string $title): ?string
    {
        if ($area = $this->match($title)) {
            return $area;
        }

        $specialty = $this->registry->normalize([self::normalise($title)])[0] ?? null;
        $module = $this->module($this->moduleOfSpecialty($specialty));

        return $module['default_area'] ?? null;
    }

    /** "Dental · Orthodontics"; just the module when the area is named after it ("Physiotherapy"). */
    public function contextLabel(?string $area): ?string
    {
        $definition = $this->get($area);
        if ($definition === null) {
            return null;
        }
        $module = $this->moduleLabel($definition['module']);

        return $module === $definition['label'] ? $module : "{$module} · {$definition['short_label']}";
    }

    /** "Conservative Dentistry & Endodontics" → "conservative_dentistry_endodontics". */
    public static function normalise(?string $name): string
    {
        $name = strtolower(trim((string) $name));
        $name = str_replace('&', ' and ', $name);
        $name = preg_replace('/\band\b/', ' ', $name);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $name), '_');
    }
}
