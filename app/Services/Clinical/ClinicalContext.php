<?php

namespace App\Services\Clinical;

use App\Models\ClinicEmployee;
use App\Support\Specialty\PracticeAreas;
use App\Support\Specialty\SpecialtyRegistry;

/**
 * Who a visit is with and what it is for, as `ClinicalContextResolver`
 * worked it out on the server: the practitioner, their healthcare module
 * (Dental), the practice area (Orthodontics) and the registry specialty the
 * clinic records it under. `source` says which input decided it.
 */
final class ClinicalContext
{
    /** A practitioner chosen in this request, or preselected by where the flow started (their schedule, queue or dashboard). */
    public const SELECTED = 'selected';

    public const APPOINTMENT = 'appointment';

    public const QUEUE = 'queue';

    /** The patient's own assigned practitioner. */
    public const ASSIGNED = 'assigned';

    /** The signed-in user, when they are a practitioner here. */
    public const CURRENT_PRACTITIONER = 'current_practitioner';

    /** The branch practises exactly one specialty. */
    public const BRANCH_DEFAULT = 'branch_default';

    /** No practitioner; the user chose what the visit is for. */
    public const SPECIALTY_SELECTION = 'specialty_selection';

    public const NONE = 'none';

    public function __construct(
        public readonly string $source,
        public readonly ?ClinicEmployee $practitioner = null,
        public readonly ?string $module = null,
        public readonly ?string $specialtyKey = null,
        public readonly ?int $clinicSpecialtyId = null,
        public readonly ?string $practiceArea = null,
    ) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /** Whether anything clinical is known: a module or a specialty. */
    public function isResolved(): bool
    {
        return $this->module !== null || $this->specialtyKey !== null;
    }

    /** The same context with the practitioner the queue assigned. */
    public function withPractitioner(?ClinicEmployee $practitioner): self
    {
        return new self($this->source, $practitioner, $this->module, $this->specialtyKey, $this->clinicSpecialtyId, $this->practiceArea);
    }

    /**
     * What the practitioner can do in this context.
     *
     * @return array<string, string>
     */
    public function capabilities(): array
    {
        $areas = app(PracticeAreas::class);
        $area = $this->practiceArea ?? ($areas->module($this->module)['default_area'] ?? null);

        return $area !== null ? $areas->capabilities([$area]) : [];
    }

    /**
     * @return array{source: string, practitioner: array{id: int, name: string}|null, module: array{key: string, label: string}|null, specialty: array{key: string, label: string}|null, area: array{key: string, label: string}|null}
     */
    public function present(): array
    {
        $areas = app(PracticeAreas::class);
        $area = $areas->get($this->practiceArea);

        return [
            'source' => $this->source,
            'practitioner' => $this->practitioner ? ['id' => $this->practitioner->id, 'name' => $this->practitioner->name] : null,
            'module' => $this->module ? ['key' => $this->module, 'label' => $areas->moduleLabel($this->module) ?? $this->module] : null,
            'specialty' => $this->specialtyKey ? ['key' => $this->specialtyKey, 'label' => app(SpecialtyRegistry::class)->get($this->specialtyKey)['label'] ?? $this->specialtyKey] : null,
            'area' => $area ? ['key' => $this->practiceArea, 'label' => $area['label']] : null,
        ];
    }
}
