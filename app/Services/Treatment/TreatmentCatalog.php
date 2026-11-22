<?php

namespace App\Services\Treatment;

use App\Models\CatalogStage;
use App\Models\CatalogStageProcedure;
use App\Models\CatalogTreatment;
use App\Models\CatalogTreatmentVersion;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\User;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Security\SecurityAudit;
use App\Services\Treatment\Targets\TreatmentTargets;
use App\Support\Specialty\PracticeAreas;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of the treatment catalog (treatments, versions, stages,
 * stage procedures). Owner/admin only (`catalog.manage`).
 *
 * A version is drafted, then published; publishing retires the previous
 * published version and makes it the one new patients start on. A published
 * version never changes — `newVersionFrom()` copies it into a new draft.
 * Deactivating a treatment or procedure only stops new selection; every
 * patient treatment keeps the version it started on.
 */
class TreatmentCatalog
{
    public function __construct(
        protected TreatmentAccess $access,
        protected ClinicSpecialties $clinicSpecialties,
        protected SecurityAudit $audit,
        protected TreatmentTargets $targets,
    ) {}

    /**
     * @param  array{specialty_key: string, code: string, name: string, category?: ?string, description?: ?string, required_capability?: ?string}  $data
     */
    public function createTreatment(Clinic $clinic, array $data, User $by): CatalogTreatment
    {
        $this->access->assertCanManageCatalog($by, $clinic);
        $specialty = $this->clinicSpecialties->active($clinic, $data['specialty_key'])
            ?? throw new DomainException('Treatments belong to one of the clinic\'s active specialties.');

        $treatment = CatalogTreatment::query()->withoutGlobalScopes()->create([
            'clinic_id' => $clinic->id,
            'specialty_key' => $specialty->specialty_key,
            'required_capability' => $this->checkedCapability($specialty->specialty_key, $data['required_capability'] ?? null),
            'code' => trim($data['code']),
            'name' => trim($data['name']),
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => true,
            'created_by' => $by->id,
        ]);
        $this->audit->record('catalog.treatment.created', $treatment, ['code' => $treatment->code, 'specialty' => $treatment->specialty_key], ['clinic_id' => $clinic->id]);

        return $treatment;
    }

    /**
     * Limit a treatment to practitioners with one practice-area capability
     * (or lift the limit with null). Only a capability of the treatment's own
     * module is accepted: an Orthodontics capability can never be put on a
     * Physiotherapy treatment. Audited.
     */
    public function setRequiredCapability(CatalogTreatment $treatment, ?string $capability, User $by): CatalogTreatment
    {
        $clinic = Clinic::query()->findOrFail($treatment->clinic_id);
        $this->access->assertCanManageCatalog($by, $clinic);
        $before = $treatment->required_capability;
        $treatment->update(['required_capability' => $this->checkedCapability($treatment->specialty_key, $capability)]);
        $this->audit->record('catalog.treatment.capability_changed', $treatment, ['before' => $before, 'after' => $treatment->required_capability], ['clinic_id' => $clinic->id]);

        return $treatment;
    }

    private function checkedCapability(?string $specialty, ?string $capability): ?string
    {
        if ($capability === null || $capability === '') {
            return null;
        }
        $areas = app(PracticeAreas::class);
        $module = $areas->moduleOfSpecialty($specialty);
        if ($module === null || ! array_key_exists($capability, $areas->capabilitiesOfModule($module))) {
            throw new DomainException('Choose a capability of this treatment\'s own specialty.');
        }

        return $capability;
    }

    /**
     * A new draft configuration.
     *
     * @param  array{treatment_type: string, charging_model: string, targets?: list<string>, planned_sessions?: ?int, validity_days?: ?int, default_duration_minutes?: ?int, settings?: ?array<string, mixed>, stages: list<array{name: string, description?: ?string, planned_sessions?: ?int, duration_minutes?: ?int, is_optional?: bool, procedures?: list<array{clinical_procedure_id: int, quantity?: int, is_required?: bool}>}>}  $config
     */
    public function draftVersion(CatalogTreatment $treatment, array $config, User $by): CatalogTreatmentVersion
    {
        $clinic = Clinic::query()->findOrFail($treatment->clinic_id);
        $this->access->assertCanManageCatalog($by, $clinic);
        $this->validateConfig($treatment, $config);
        $settings = $this->settings($treatment, $config);

        return DB::transaction(function () use ($treatment, $config, $settings, $by) {
            $locked = CatalogTreatment::query()->withoutGlobalScopes()->whereKey($treatment->id)->lockForUpdate()->firstOrFail();
            $next = (int) CatalogTreatmentVersion::query()->withoutGlobalScopes()->where('catalog_treatment_id', $locked->id)->max('version') + 1;

            $version = CatalogTreatmentVersion::query()->withoutGlobalScopes()->create([
                'clinic_id' => $locked->clinic_id,
                'catalog_treatment_id' => $locked->id,
                'version' => $next,
                'status' => CatalogTreatmentVersion::DRAFT,
                'treatment_type' => $config['treatment_type'],
                'charging_model' => $config['charging_model'],
                'planned_sessions' => $config['planned_sessions'] ?? null,
                'validity_days' => $config['validity_days'] ?? null,
                'default_duration_minutes' => $config['default_duration_minutes'] ?? null,
                'settings' => $settings,
                'created_by' => $by->id,
            ]);
            $this->writeStages($version, $config['stages']);

            return $version->load('stages.procedures');
        });
    }

    /**
     * Replace a draft's configuration (its stages and procedures too).
     *
     * @param  array<string, mixed>  $config  as for draftVersion()
     */
    public function updateDraft(CatalogTreatmentVersion $version, array $config, User $by): CatalogTreatmentVersion
    {
        $treatment = CatalogTreatment::query()->withoutGlobalScopes()->findOrFail($version->catalog_treatment_id);
        $this->access->assertCanManageCatalog($by, Clinic::query()->findOrFail($version->clinic_id));
        $this->validateConfig($treatment, $config);
        $settings = $this->settings($treatment, $config);

        return DB::transaction(function () use ($version, $config, $settings) {
            $locked = CatalogTreatmentVersion::query()->withoutGlobalScopes()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw new DomainException('A published version cannot be changed. Create a new version.');
            }
            $locked->update([
                'treatment_type' => $config['treatment_type'],
                'charging_model' => $config['charging_model'],
                'planned_sessions' => $config['planned_sessions'] ?? null,
                'validity_days' => $config['validity_days'] ?? null,
                'default_duration_minutes' => $config['default_duration_minutes'] ?? null,
                'settings' => $settings,
            ]);
            CatalogStage::query()->withoutGlobalScopes()->where('catalog_treatment_version_id', $locked->id)->get()->each->delete();
            $this->writeStages($locked, $config['stages']);

            return $locked->load('stages.procedures');
        });
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     */
    private function writeStages(CatalogTreatmentVersion $version, array $stages): void
    {
        foreach (array_values($stages) as $index => $stage) {
            $row = CatalogStage::query()->withoutGlobalScopes()->create([
                'clinic_id' => $version->clinic_id,
                'catalog_treatment_version_id' => $version->id,
                'position' => $index + 1,
                'name' => trim($stage['name']),
                'description' => $stage['description'] ?? null,
                'planned_sessions' => $stage['planned_sessions'] ?? null,
                'duration_minutes' => $stage['duration_minutes'] ?? null,
                'is_optional' => (bool) ($stage['is_optional'] ?? false),
            ]);
            foreach (array_values($stage['procedures'] ?? []) as $p => $procedure) {
                CatalogStageProcedure::query()->withoutGlobalScopes()->create([
                    'clinic_id' => $version->clinic_id,
                    'catalog_stage_id' => $row->id,
                    'clinical_procedure_id' => (int) $procedure['clinical_procedure_id'],
                    'position' => $p + 1,
                    'quantity' => max(1, (int) ($procedure['quantity'] ?? 1)),
                    'is_required' => (bool) ($procedure['is_required'] ?? true),
                ]);
            }
        }
    }

    /**
     * The version's settings with its declared targets (validated against
     * the treatment's specialty).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function settings(CatalogTreatment $treatment, array $config): array
    {
        return [
            ...((array) ($config['settings'] ?? [])),
            'targets' => $this->targets->assertDeclared($treatment->specialty_key, array_values((array) ($config['targets'] ?? []))),
        ];
    }

    public function publish(CatalogTreatmentVersion $version, User $by): CatalogTreatmentVersion
    {
        $this->access->assertCanManageCatalog($by, Clinic::query()->findOrFail($version->clinic_id));

        return DB::transaction(function () use ($version, $by) {
            $locked = CatalogTreatmentVersion::query()->withoutGlobalScopes()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw new DomainException('Only a draft version can be published.');
            }
            if (! $locked->stages()->withoutGlobalScopes()->exists()) {
                throw new DomainException('A treatment needs at least one stage.');
            }

            CatalogTreatmentVersion::query()->withoutGlobalScopes()
                ->where('catalog_treatment_id', $locked->catalog_treatment_id)->where('status', CatalogTreatmentVersion::PUBLISHED)
                ->get()->each(fn (CatalogTreatmentVersion $old) => $old->update(['status' => CatalogTreatmentVersion::RETIRED, 'retired_at' => now()]));

            $locked->update(['status' => CatalogTreatmentVersion::PUBLISHED, 'published_at' => now(), 'published_by' => $by->id]);
            CatalogTreatment::query()->withoutGlobalScopes()->whereKey($locked->catalog_treatment_id)->update(['current_version_id' => $locked->id]);
            $this->audit->record('catalog.version.published', $locked, ['version' => $locked->version, 'catalog_treatment_id' => $locked->catalog_treatment_id], ['clinic_id' => $locked->clinic_id]);

            return $locked;
        });
    }

    /** A new draft with the same configuration, to change and publish. */
    public function newVersionFrom(CatalogTreatmentVersion $source, User $by): CatalogTreatmentVersion
    {
        $source->load(['stages' => fn ($q) => $q->withoutGlobalScopes(), 'stages.procedures' => fn ($q) => $q->withoutGlobalScopes()]);

        return $this->draftVersion(CatalogTreatment::query()->withoutGlobalScopes()->findOrFail($source->catalog_treatment_id), [
            'treatment_type' => $source->treatment_type,
            'charging_model' => $source->charging_model,
            'targets' => $source->targets(),
            'planned_sessions' => $source->planned_sessions,
            'validity_days' => $source->validity_days,
            'default_duration_minutes' => $source->default_duration_minutes,
            'settings' => $source->settings,
            'stages' => $source->stages->map(fn (CatalogStage $stage) => [
                'name' => $stage->name, 'description' => $stage->description, 'planned_sessions' => $stage->planned_sessions,
                'duration_minutes' => $stage->duration_minutes, 'is_optional' => $stage->is_optional,
                'procedures' => $stage->procedures->map(fn (CatalogStageProcedure $p) => ['clinical_procedure_id' => $p->clinical_procedure_id, 'quantity' => $p->quantity, 'is_required' => $p->is_required])->all(),
            ])->all(),
        ], $by);
    }

    /** Stop (or allow again) new patients starting this treatment. Existing ones are unaffected. */
    public function setActive(CatalogTreatment $treatment, bool $active, User $by): void
    {
        $this->access->assertCanManageCatalog($by, Clinic::query()->findOrFail($treatment->clinic_id));
        $treatment->update(['is_active' => $active]);
        $this->audit->record($active ? 'catalog.treatment.activated' : 'catalog.treatment.deactivated', $treatment, [], ['clinic_id' => $treatment->clinic_id]);
    }

    /**
     * Create or edit a procedure in the clinic's catalog. Its price is not
     * here — prices are versions (`CatalogPricing`).
     *
     * @param  array{code: string, name: string, category?: ?string, description?: ?string, specialty_key?: ?string, default_duration_minutes?: ?int, is_billable?: bool, is_bookable?: bool, targets?: list<string>, sittings?: ?array{visits: int, step: int}}  $data
     */
    public function saveProcedure(Clinic $clinic, ?ClinicalProcedure $procedure, array $data, User $by): ClinicalProcedure
    {
        $this->access->assertCanManageCatalog($by, $clinic);
        if ($procedure && $procedure->clinic_id !== $clinic->id) {
            throw new InvalidArgumentException('That procedure belongs to another clinic.');
        }
        $specialty = filled($data['specialty_key'] ?? null)
            ? ($this->clinicSpecialties->active($clinic, $data['specialty_key'])?->specialty_key ?? throw new DomainException('Procedures belong to one of the clinic\'s active specialties.'))
            : null;
        $targets = $this->targets->assertDeclared($specialty, array_values((array) ($data['targets'] ?? [])));

        $procedure ??= new ClinicalProcedure(['clinic_id' => $clinic->id, 'is_active' => true]);
        $created = ! $procedure->exists;
        $procedure->fill([
            'clinic_id' => $clinic->id,
            'code' => trim($data['code']),
            'name' => trim($data['name']),
            // A key left out keeps what is stored; only a new procedure takes the defaults.
            'category' => $data['category'] ?? $procedure->category ?? throw new InvalidArgumentException('Choose a category for the procedure.'),
            'description' => array_key_exists('description', $data) ? $data['description'] : $procedure->description,
            'specialty_key' => $specialty,
            // The column defaults to 30 minutes and is never null.
            'default_duration_minutes' => $data['default_duration_minutes'] ?? $procedure->default_duration_minutes ?? 30,
            'is_billable' => (bool) ($data['is_billable'] ?? $procedure->is_billable ?? true),
            'is_bookable' => (bool) ($data['is_bookable'] ?? $procedure->is_bookable ?? true),
            // `sittings`: how many visits the service usually takes and the days between them; null clears the clinic's own setting.
            'metadata' => [
                ...((array) ($procedure->metadata ?? [])),
                'targets' => $targets,
                ...(array_key_exists('sittings', $data) ? ['sittings' => self::cleanSittings($data['sittings'])] : []),
            ],
        ])->save();
        $this->audit->record($created ? 'catalog.procedure.created' : 'catalog.procedure.updated', $procedure, ['code' => $procedure->code, 'changed' => array_keys($procedure->getChanges())], ['clinic_id' => $clinic->id]);

        return $procedure;
    }

    /** Stop (or allow again) a procedure being selected or booked. Recorded procedures are unaffected. */
    /**
     * A service's own visits: blank stage names are dropped, and when stages are named their count is the number of
     * visits. No stages left means the clinic only set a count and a gap, as before.
     *
     * @param  array<string, mixed>|null  $sittings
     * @return array<string, mixed>|null
     */
    private static function cleanSittings(?array $sittings): ?array
    {
        if ($sittings === null) {
            return null;
        }
        $stages = array_values(array_filter(array_map(fn ($name) => trim((string) $name), (array) ($sittings['stages'] ?? [])), fn (string $name) => $name !== ''));
        unset($sittings['stages']);

        return $stages === [] ? $sittings : [...$sittings, 'stages' => $stages, 'visits' => count($stages)];
    }

    public function setProcedureActive(ClinicalProcedure $procedure, bool $active, User $by): void
    {
        $this->access->assertCanManageCatalog($by, Clinic::query()->findOrFail($procedure->clinic_id));
        $procedure->update(['is_active' => $active]);
        $this->audit->record($active ? 'catalog.procedure.activated' : 'catalog.procedure.deactivated', $procedure, [], ['clinic_id' => $procedure->clinic_id]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateConfig(CatalogTreatment $treatment, array $config): void
    {
        if (! in_array($config['treatment_type'] ?? null, CatalogTreatmentVersion::TYPES, true)) {
            throw new InvalidArgumentException('Choose a treatment type: '.implode(', ', CatalogTreatmentVersion::TYPES).'.');
        }
        if (! in_array($config['charging_model'] ?? null, CatalogTreatmentVersion::CHARGING_MODELS, true)) {
            throw new InvalidArgumentException('Choose how the treatment is charged: '.implode(', ', CatalogTreatmentVersion::CHARGING_MODELS).'.');
        }
        if (($config['stages'] ?? []) === []) {
            throw new InvalidArgumentException('A treatment needs at least one stage.');
        }

        $procedureIds = collect($config['stages'])->flatMap(fn (array $stage) => array_column($stage['procedures'] ?? [], 'clinical_procedure_id'))->map(fn ($id) => (int) $id)->unique();
        $procedures = ClinicalProcedure::query()->withoutGlobalScopes()->where('clinic_id', $treatment->clinic_id)->whereIn('id', $procedureIds)->get()->keyBy('id');
        foreach ($procedureIds as $id) {
            $procedure = $procedures->get($id) ?? throw new InvalidArgumentException('Every procedure must come from this clinic\'s catalog.');
            if (! $procedure->is_active) {
                throw new DomainException("“{$procedure->name}” is inactive and cannot be added.");
            }
            if ($procedure->specialty_key !== null && $procedure->specialty_key !== $treatment->specialty_key) {
                throw new DomainException("“{$procedure->name}” belongs to another specialty.");
            }
        }

        if ($config['charging_model'] === 'per_procedure') {
            foreach ($config['stages'] as $stage) {
                if (($stage['procedures'] ?? []) === []) {
                    throw new InvalidArgumentException('A treatment charged per procedure needs procedures in every stage.');
                }
            }
        }
        if ($config['treatment_type'] === 'session_based' && empty($config['planned_sessions'])
            && collect($config['stages'])->every(fn (array $stage) => empty($stage['planned_sessions']))) {
            throw new InvalidArgumentException('A session-based treatment needs a number of sessions.');
        }
    }
}
