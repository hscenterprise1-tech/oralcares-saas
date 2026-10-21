<?php

namespace Tests\Support;

use App\ClinicRole;
use App\Models\Branch;
use App\Models\CatalogTreatment;
use App\Models\CatalogTreatmentVersion;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Treatment\CatalogPricing;
use App\Services\Treatment\TreatmentCatalog;
use Spatie\Permission\PermissionRegistrar;

/**
 * A dental + physiotherapy clinic with a procedure catalog, a dentist
 * (doctorA), a physiotherapist, and helpers to act as a member and to build
 * catalog treatments — the shared setup of the treatment engine tests.
 */
class TreatmentFixture
{
    public ReceptionFixture $f;

    /** @var array<string, ClinicalProcedure> keyed by code */
    public array $procedures = [];

    public ClinicEmployee $physio;

    public static function make(string $slug = 'treatment-clinic'): self
    {
        foreach ([['general_dentistry', 'dental'], ['physiotherapy', 'rehabilitation']] as [$key, $category]) {
            HealthcareSpecialtyMaster::query()->firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'category' => $category, 'category_label' => str($category)->headline(), 'is_active' => true]);
        }

        $fixture = new self;
        $fixture->f = ReceptionFixture::make($slug);
        $fixture->f->clinic->update(['specialties' => ['dental', 'physiotherapy']]);

        foreach ([['ACC', 'Access opening', 'dental'], ['CLN', 'Cleaning & shaping', 'dental'], ['OBT', 'Obturation', 'dental'], ['FIL', 'Composite filling', 'dental'], ['PHY', 'Physiotherapy session', 'physiotherapy']] as [$code, $name, $specialty]) {
            $fixture->procedures[$code] = ClinicalProcedure::query()->withoutGlobalScopes()->create([
                'clinic_id' => $fixture->f->clinic->id, 'code' => $code, 'name' => $name, 'category' => 'Treatment', 'specialty_key' => $specialty, 'is_active' => true,
            ]);
        }

        $fixture->physio = $fixture->f->doctor('Ms. Physio');
        $fixture->physio->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);

        return $fixture;
    }

    /**
     * Act as a member: what the clinic middleware sets up for a request.
     *
     * @param  array<string, int>  $session
     */
    public function actAs(User $user, array $session): User
    {
        auth()->login($user);
        $context = app(ClinicContext::class);
        $context->clear();
        $context->setMembership(ClinicMembership::query()->withoutGlobalScopes()->findOrFail($session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID]));
        $context->setBranch(Branch::query()->withoutGlobalScopes()->findOrFail($session[ClinicContext::SESSION_BRANCH_ID]));
        setPermissionsTeamId($session[ClinicContext::SESSION_BRANCH_ID]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user;
    }

    public function actAsOwner(): User
    {
        return $this->actAs($this->f->owner, $this->f->session);
    }

    /** A member holding one clinic role, acted as. */
    public function actAsRole(ClinicRole $role): User
    {
        [$user, $session] = $this->f->memberWithRole($role);

        return $this->actAs($user, $session);
    }

    /**
     * A published Root Canal Treatment: three stages, one procedure each,
     * priced for the charging model.
     */
    public function rct(string $charging = 'upfront', bool $requiresTooth = true, float $price = 8000): CatalogTreatmentVersion
    {
        $owner = $this->actAsOwner();
        $catalog = app(TreatmentCatalog::class);
        $treatment = CatalogTreatment::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->where('code', 'RCT')->first()
            ?? $catalog->createTreatment($this->f->clinic, ['specialty_key' => 'dental', 'code' => 'RCT', 'name' => 'Root Canal Treatment'], $owner);

        $version = $catalog->draftVersion($treatment, [
            'treatment_type' => 'multi_stage', 'charging_model' => $charging, 'targets' => $requiresTooth ? ['tooth'] : [],
            'stages' => [
                ['name' => 'Access opening', 'procedures' => [['clinical_procedure_id' => $this->procedures['ACC']->id]]],
                ['name' => 'Cleaning & shaping', 'procedures' => [['clinical_procedure_id' => $this->procedures['CLN']->id]]],
                ['name' => 'Obturation', 'is_optional' => false, 'procedures' => [['clinical_procedure_id' => $this->procedures['OBT']->id]]],
            ],
        ], $owner);

        $pricing = app(CatalogPricing::class);
        match ($charging) {
            'upfront' => $pricing->set($this->f->clinic, 'catalog_treatment_version_id', $version->id, $price, null, $owner),
            'per_stage' => $version->stages->each(fn ($stage, $i) => $pricing->set($this->f->clinic, 'catalog_stage_id', $stage->id, [3000, 2500, 2500][$i], null, $owner)),
            'per_procedure' => collect(['ACC' => 2000, 'CLN' => 3000, 'OBT' => 3000])->each(fn ($amount, $code) => $pricing->set($this->f->clinic, 'clinical_procedure_id', $this->procedures[$code]->id, $amount, null, $owner)),
        };

        return $catalog->publish($version, $owner);
    }

    /** A published session-based physiotherapy treatment: 10 sessions, charged per session. */
    public function backRehab(): CatalogTreatmentVersion
    {
        $owner = $this->actAsOwner();
        $catalog = app(TreatmentCatalog::class);
        $treatment = $catalog->createTreatment($this->f->clinic, ['specialty_key' => 'physiotherapy', 'code' => 'LBR', 'name' => 'Lower Back Rehabilitation'], $owner);
        $version = $catalog->draftVersion($treatment, [
            'treatment_type' => 'session_based', 'charging_model' => 'per_procedure', 'planned_sessions' => 10, 'validity_days' => 90,
            'stages' => [['name' => 'Sessions', 'planned_sessions' => 10, 'procedures' => [['clinical_procedure_id' => $this->procedures['PHY']->id]]]],
        ], $owner);
        app(CatalogPricing::class)->set($this->f->clinic, 'clinical_procedure_id', $this->procedures['PHY']->id, 800, null, $owner);

        return $catalog->publish($version, $owner);
    }
}
