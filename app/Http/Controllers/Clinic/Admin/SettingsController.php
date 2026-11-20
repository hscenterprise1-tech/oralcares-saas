<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\ClinicRole;
use App\Http\Controllers\Concerns\LinksSettingsPages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\UpdateCaseNumberConfigRequest;
use App\Models\Clinic;
use App\Models\ClinicSettingAudit;
use App\Models\ClinicWhatsAppSettings;
use App\Services\Appointments\AppointmentSettingsService;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\ClinicContext;
use App\Services\Patient\CaseNumberConfigService;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\ClinicWorkAlerts;
use App\Services\Patient\PatientAutomation;
use App\Services\PatientFieldConfigService;
use App\Services\Physio\PhysioCareSummary;
use App\Services\Physio\PhysioCatalogue;
use App\Services\Reception\PatientFlowConfigService;
use App\Services\WhatsApp\ClinicWhatsApp;
use App\Support\ClinicAccess\ClinicModules;
use App\Support\Clinical\DefaultClinicalCatalogue;
use App\Support\ClinicClock;
use App\Support\Specialty\PracticeAreas;
use App\Support\Specialty\SpecialtyRegistry;
use App\VisitPriority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SettingsController extends Controller
{
    use LinksSettingsPages;

    public function __construct(
        protected PatientFieldConfigService $fieldConfigService,
        protected CaseNumberConfigService $caseNumberConfigService,
        protected PatientFlowConfigService $patientFlowConfigService,
    ) {}

    /**
     * Display the clinic's own settings — branding, and patient
     * registration fields, more sections as the admin area grows.
     */
    public function index(ClinicContext $context): Response
    {
        $clinic = $context->clinic();

        return Inertia::render('ClinicPortal/Settings/General', [
            'settings_links' => $this->settingsLinks($clinic),
            'links' => [
                'branding' => route('admin.settings.update'),
                'check_in' => route('admin.settings.check-in'),
                'quick_care' => route('admin.settings.quick-care'),
                'patient_flow' => route('admin.settings.patient-flow'),
                'case_number' => route('admin.settings.case-number'),
            ],
            'branding' => [
                'display_name' => $clinic->display_name,
                'name' => $clinic->name,
                'logo_url' => $clinic->logo_url,
            ],
            /*
             * Schema-only for now — no Billing/Invoice page reads this yet.
             * Captured up front so whichever billing model the app settles
             * on (see `Invoice` vs `PatientBilling`, an open decision for
             * when that module is actually built) can read a clinic's
             * invoice identity from day one instead of retrofitting it.
             */
            'invoiceBranding' => [
                'legal_name' => $clinic->legal_name,
                'use_clinic_logo_for_invoice' => $clinic->use_clinic_logo_for_invoice,
                'invoice_logo_url' => $clinic->invoice_logo_url,
                'has_own_invoice_logo' => (bool) $clinic->invoice_logo_path,
            ],
            'patientFieldConfig' => $this->fieldConfigService->resolve($clinic),
            'patientFlow' => $this->patientFlowConfigService->resolve($clinic),
            // Front desk intake: check-in options and Quick Care (allergy status, alerts).
            'intake' => [
                'check_in' => $this->patientFlowConfigService->checkIn($clinic),
                'quick_care' => $this->patientFlowConfigService->quickCare($clinic),
                'priorities' => array_map(fn (VisitPriority $priority) => ['value' => $priority->value, 'label' => $priority->label()], VisitPriority::cases()),
                // The queue module itself (plan, add-on or company override); the switch above only hides positions.
                'queue_available' => app(ClinicModules::class)->enabled($clinic, 'queue'),
                'roles' => array_map(fn (ClinicRole $role) => ['value' => $role->value, 'label' => $role->value], ClinicRole::cases()),
                // The clinic's modules with their specialties and practice areas: what a catalogue item may be limited to.
                'domains' => $this->domains($clinic),
                'visit_types' => collect(app(ClinicalCatalogue::class)->effective($clinic->id, DefaultClinicalCatalogue::KIND_VISIT_TYPE))->map(fn (array $type) => ['key' => $type['key'], 'name' => $type['name']])->values()->all(),
                'patient_fields' => collect($this->fieldConfigService->definitions($clinic))->only(['allergies', 'medical_conditions'])->all(),
            ],
            'caseNumber' => $this->caseNumberConfigService->forSettings($clinic) + [
                'recent_changes' => ClinicSettingAudit::where('clinic_id', $clinic->id)
                    ->where('section', CaseNumberConfigService::AUDIT_SECTION)
                    ->with('user:id,name')
                    ->latest('created_at')
                    ->limit(5)
                    ->get()
                    ->map(fn (ClinicSettingAudit $audit) => [
                        'id' => $audit->id,
                        'changed_by' => $audit->user?->name ?? 'System',
                        'changed_at' => ClinicClock::fromUtc($audit->created_at)->format('Y-m-d\TH:i:s'),
                        'old_values' => $audit->old_values,
                        'new_values' => $audit->new_values,
                    ]),
            ],
        ]);
    }

    /**
     * Update which patient-registration fields are required, optional or
     * hidden. Read by both `ClinicPortal/Patients/Create.tsx` (what renders) and
     * `RegisterPatientRequest::rules()` (what's enforced) — see
     * `PatientFieldConfigService` for why those two can't drift apart.
     */
    public function updatePatientFields(Request $request, ClinicContext $context): RedirectResponse
    {
        $clinic = $context->clinic();

        // Each field is optional (only the ones sent change) and may be a
        // bare state or a definition. First name and mobile are not
        // configurable and are never accepted here.
        $states = implode(',', PatientFieldConfigService::STATES);
        $roles = implode(',', ClinicRole::values());
        $rules = [];
        foreach (array_keys(PatientFieldConfigService::CONFIGURABLE_FIELDS) as $field) {
            $rules[$field] = ['sometimes', function (string $attribute, mixed $value, \Closure $fail) use ($states) {
                if (is_string($value) && in_array($value, explode(',', $states), true)) {
                    return;
                }
                if (! is_array($value)) {
                    $fail('Choose required, optional or hidden.');
                }
            }];
            $rules["{$field}.state"] = ['sometimes', "in:{$states}"];
            $rules["{$field}.order"] = ['sometimes', 'integer', 'min:0', 'max:9999'];
            $rules["{$field}.new_case"] = ['sometimes', 'boolean'];
            $rules["{$field}.full_page"] = ['sometimes', 'boolean'];
            $rules["{$field}.specialties"] = ['sometimes', 'nullable', 'array'];
            // Only modules this clinic practises: a field cannot be scoped to one it does not offer.
            $rules["{$field}.specialties.*"] = [Rule::in(app(ClinicSpecialties::class)->activeKeys($clinic))];
            $rules["{$field}.visible_roles"] = ['sometimes', 'nullable', 'array'];
            $rules["{$field}.visible_roles.*"] = ["in:{$roles}"];
            $rules["{$field}.editable_roles"] = ['sometimes', 'nullable', 'array'];
            $rules["{$field}.editable_roles.*"] = ["in:{$roles}"];
            $rules["{$field}.label"] = ['sometimes', 'nullable', 'string', 'max:'.PatientFieldConfigService::LABEL_MAX];
            $rules["{$field}.help"] = ['sometimes', 'nullable', 'string', 'max:'.PatientFieldConfigService::HELP_MAX];
        }

        $validated = $request->validate($rules);
        $config = collect($validated)->only(array_keys(PatientFieldConfigService::CONFIGURABLE_FIELDS))->all();

        $this->fieldConfigService->update($clinic, $config, $request->user());

        return back()->with('success', 'Patient registration fields updated.');
    }

    /**
     * The owner's Patient Form settings screen: every configurable field's
     * full setting, the fields that cannot be changed and why, the starting
     * templates, the clinic's lists (visit reasons and safety lists) and the
     * Quick Care safety options — everything the existing save endpoints
     * accept, so the screen previews and saves the real configuration.
     */
    public function patientForm(ClinicContext $context, ClinicalCatalogue $catalogue): Response
    {
        $clinic = $context->clinic();
        $registry = app(SpecialtyRegistry::class);
        $lists = [
            DefaultClinicalCatalogue::KIND_VISIT_REASON, DefaultClinicalCatalogue::KIND_CONDITION, DefaultClinicalCatalogue::KIND_ALLERGY,
            DefaultClinicalCatalogue::KIND_MEDICATION, DefaultClinicalCatalogue::KIND_SURGERY, DefaultClinicalCatalogue::KIND_FAMILY_HISTORY, DefaultClinicalCatalogue::KIND_ALERT,
        ];

        return Inertia::render('ClinicPortal/Settings/PatientForm', [
            'settings_links' => $this->settingsLinks($context->clinic()),
            'modules' => collect(app(ClinicSpecialties::class)->activeKeys($clinic))
                ->map(fn (string $key) => ['key' => $key, 'label' => $registry->get($key)['label'] ?? $key])->values()->all(),
            'fields' => $this->fieldConfigService->definitions($clinic),
            'customised' => $this->fieldConfigService->customised($clinic),
            'system_fields' => [
                ['key' => 'first_name', 'label' => 'First name', 'reason' => 'Every patient needs a name to be told apart and found again.'],
                ['key' => 'phone', 'label' => 'Mobile', 'reason' => 'The mobile number finds an existing patient before a second record is made.'],
            ],
            'roles' => ClinicRole::values(),
            'templates' => collect(PatientFieldConfigService::TEMPLATES)->map(fn (array $template, string $key) => ['key' => $key, 'label' => $template['label'], 'description' => $template['description']])->values()->all(),
            'limits' => ['label' => PatientFieldConfigService::LABEL_MAX, 'help' => PatientFieldConfigService::HELP_MAX],
            'quick_care' => $this->patientFlowConfigService->quickCare($clinic),
            'visit_reason' => $this->patientFlowConfigService->checkIn($clinic)['visit_reason'],
            'lists' => collect($lists)->mapWithKeys(fn (string $kind) => [$kind => $catalogue->effective($clinic->id, $kind, null, includeDisabled: true)])->all(),
            'links' => [
                'fields' => route('admin.settings.patient-fields'),
                'template' => route('admin.settings.patient-fields.template'),
                'quick_care' => route('admin.settings.quick-care'),
                'automation' => route('admin.settings.automation'),
                'physio' => $this->physioLink($clinic),
                'self' => route('admin.settings.patient-form'),
                'check_in' => route('admin.settings.check-in'),
                'catalogue' => route('admin.settings.catalogue.index', ['kind' => '__KIND__']),
            ],
        ]);
    }

    /**
     * Apply a starting template (Dental, Physiotherapy, Combined). A field the
     * clinic has already set keeps its setting unless the owner asked to
     * replace customised fields. Audited.
     */
    public function applyPatientFieldTemplate(Request $request, ClinicContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'template' => ['required', Rule::in(array_keys(PatientFieldConfigService::TEMPLATES))],
            'replace_customised' => ['sometimes', 'boolean'],
        ]);
        $result = $this->fieldConfigService->applyTemplate($context->clinic(), $validated['template'], $request->user(), (bool) ($validated['replace_customised'] ?? false));
        $kept = count($result['kept']);

        return back()->with('success', PatientFieldConfigService::TEMPLATES[$validated['template']]['label'].' template applied'
            .($kept > 0 ? " — {$kept} field".($kept === 1 ? '' : 's').' you had already set kept as they were.' : '.'));
    }

    /** Check-in settings: whether the visit reason is asked (required/optional/hidden) and whether priority is offered. Audited. */
    public function updateCheckIn(Request $request, ClinicContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'visit_reason' => ['sometimes', 'in:'.implode(',', PatientFieldConfigService::STATES)],
            'priority' => ['sometimes', 'boolean'],
            'priorities' => ['sometimes', 'array'],
            'priorities.*' => [Rule::enum(VisitPriority::class)],
            'queue' => ['sometimes', 'boolean'],
            'show_fee' => ['sometimes', 'boolean'],
            'payment' => ['sometimes', Rule::in([PatientFlowConfigService::PAY_AFTER_CONSULTATION, PatientFlowConfigService::PAY_AT_CHECK_IN])],
        ]);

        $this->patientFlowConfigService->updateCheckIn($context->clinic(), $validated, $request->user());

        return back()->with('success', 'Check-in settings saved.');
    }

    /**
     * The modules this clinic practises, each with its registry specialties
     * and practice areas.
     *
     * @return list<array{key: string, label: string, specialties: list<string>, areas: list<array{key: string, label: string}>}>
     */
    private function domains(Clinic $clinic): array
    {
        $areas = app(PracticeAreas::class);
        $registry = app(SpecialtyRegistry::class);

        return collect($registry->forClinic($clinic))
            ->map(fn (string $specialty) => $areas->moduleOfSpecialty($specialty))->filter()->unique()->values()
            ->map(fn (string $module) => [
                'key' => $module,
                'label' => $areas->moduleLabel($module),
                'specialties' => collect(array_keys($registry->all()))->filter(fn (string $key) => $areas->moduleOfSpecialty($key) === $module)->values()->all(),
                'areas' => collect($areas->ofModule($module))->map(fn (array $area, string $key) => ['key' => $key, 'label' => $area['label']])->values()->all(),
            ])->all();
    }

    /**
     * Quick Care at the desk: allergy status and whether alerts are offered
     * (and to which roles). Audited. Vitals are no longer offered: vitals
     * settings sent here are ignored.
     */
    public function updateQuickCare(Request $request, ClinicContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'alert_roles' => ['sometimes', 'nullable', 'array'],
            'alert_roles.*' => [Rule::in(ClinicRole::values())],
            'allergy_status' => ['sometimes', 'in:'.implode(',', PatientFieldConfigService::STATES)],
            'alerts' => ['sometimes', 'boolean'],
            'conditions_from_catalogue' => ['sometimes', 'boolean'],
        ]);

        $this->patientFlowConfigService->updateQuickCare($context->clinic(), $validated, $request->user());

        return back()->with('success', 'Quick Care settings saved.');
    }

    /** The clinic's view of one catalogue kind, disabled items included: what an owner configures. */
    public function catalogue(string $kind, ClinicContext $context, ClinicalCatalogue $catalogue): JsonResponse
    {
        abort_unless(in_array($kind, DefaultClinicalCatalogue::KINDS, true), 404);

        return response()->json(['items' => $catalogue->effective($context->clinic()->id, $kind, null, includeDisabled: true)]);
    }

    /** Change how the clinic uses one catalogue item (rename, reorder, enable, require, specialties, archive). Audited. */
    public function configureCatalogueItem(Request $request, string $kind, string $key, ClinicContext $context, ClinicalCatalogue $catalogue): JsonResponse
    {
        abort_unless(in_array($kind, DefaultClinicalCatalogue::KINDS, true), 404);
        $validated = $request->validate($this->catalogueRules());

        try {
            $catalogue->configure($context->clinic(), $request->user(), $kind, $key, $validated);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return response()->json(['items' => $catalogue->effective($context->clinic()->id, $kind, null, includeDisabled: true)]);
    }

    /** Add an item of the clinic's own to a catalogue kind. Audited. */
    public function addCatalogueItem(Request $request, string $kind, ClinicContext $context, ClinicalCatalogue $catalogue): JsonResponse
    {
        abort_unless(in_array($kind, DefaultClinicalCatalogue::KINDS, true), 404);
        $validated = $request->validate(['name' => ['required', 'string', 'max:120'], ...$this->catalogueRules()]);

        $key = $catalogue->addCustom($context->clinic(), $request->user(), $kind, $validated['name'], collect($validated)->except('name')->all());

        return response()->json(['key' => $key, 'items' => $catalogue->effective($context->clinic()->id, $kind, null, includeDisabled: true)], 201);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function catalogueRules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'severity' => ['sometimes', 'nullable', 'in:'.implode(',', DefaultClinicalCatalogue::SEVERITIES)],
            'specialty_keys' => ['sometimes', 'nullable', 'array'],
            'specialty_keys.*' => ['string', 'max:64'],
            'practice_area_keys' => ['sometimes', 'nullable', 'array'],
            'practice_area_keys.*' => ['string', 'max:60'],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'enabled' => ['sometimes', 'nullable', 'boolean'],
            'required' => ['sometimes', 'nullable', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Settings > Patient Flow. Rooms off hides rooms everywhere (queue,
     * Now/Next, check-in, appointment booking) without clearing stored ones.
     */
    public function updatePatientFlow(Request $request, ClinicContext $context): RedirectResponse
    {
        $validated = $request->validate(['rooms_enabled' => ['required', 'boolean']]);

        $this->patientFlowConfigService->update($context->clinic(), ['rooms_enabled' => (bool) $validated['rooms_enabled']], $request->user());

        return back()->with('success', $validated['rooms_enabled'] ? 'Rooms are now shown in patient flow.' : 'Rooms are now hidden from patient flow.');
    }

    /**
     * Update the clinic's case-number format. Prefix, separator, digits and
     * start number apply to the next number issued; year display, year style
     * and the yearly restart wait for the next period. Numbers already issued
     * never change. Every change is written to `clinic_setting_audits`.
     */
    /** Recall intervals offered (months); 0 turns the dental recall off. */
    public const RECALL_MONTHS = [0, 3, 6, 9, 12];

    /**
     * The owner's automation settings in one place: what runs by itself for
     * patients (missed appointments, dental recall) and which WhatsApp
     * messages go out (`ClinicWhatsApp` automations).
     */
    public function automation(ClinicContext $context, AppointmentSettingsService $appointments, ClinicWhatsApp $whatsApp, PatientAutomation $automation): Response
    {
        $clinic = $context->clinic();
        $booking = $appointments->resolve($clinic);
        $settings = $whatsApp->settings($clinic);
        $groups = (array) config('clinic_whatsapp.groups', []);

        return Inertia::render('ClinicPortal/Settings/Automation', [
            'settings_links' => $this->settingsLinks($context->clinic()),
            'patient' => [
                'auto_no_show' => $booking['auto_no_show'],
                'no_show_after_minutes' => $booking['no_show_after_minutes'],
                'recall_months' => $automation->recallMonths($clinic),
                'recall_choices' => self::RECALL_MONTHS,
                'staff_alerts' => app(ClinicWorkAlerts::class)->enabled($clinic),
                'morning_digest' => (bool) ($clinic->communication_config['morning_digest'] ?? true),
                'auto_assign_doctor' => $automation->assignsFirstDoctor($clinic),
            ],
            'whatsapp' => [
                'enabled' => (bool) $settings->enabled,
                'require_opt_in' => (bool) ($settings->require_opt_in ?? true),
                'status' => $whatsApp->status($clinic, $settings),
                'automations' => collect($whatsApp->availableAutomations($clinic))->map(fn (array $item, string $key) => [
                    'key' => $key,
                    'label' => $item['label'],
                    'group' => $groups[$item['group']] ?? $item['group'],
                    'enabled' => $settings->automationEnabled($key),
                ])->values()->all(),
            ],
            'links' => ['update' => route('admin.settings.automation.update'), 'patient_form' => route('admin.settings.patient-form'), 'self' => route('admin.settings.automation'), 'physio' => $this->physioLink($clinic)],
        ]);
    }

    /**
     * The owner's physiotherapy price list: the treatments given in a session
     * (usual minutes, price for a visit paid on the day) and the session
     * packages the clinic sells (`PhysioCatalogue`). Only for a clinic that
     * practises physiotherapy.
     */
    public function physio(ClinicContext $context, PhysioCatalogue $catalogue, ClinicSpecialties $specialties): Response
    {
        $clinic = $context->clinic();
        abort_if($specialties->active($clinic, PhysioCareSummary::SPECIALTY) === null, 404);

        return Inertia::render('ClinicPortal/Settings/Physio', [
            'settings_links' => $this->settingsLinks($context->clinic()),
            'catalogue' => $catalogue->resolve($clinic),
            'exercise_options' => [
                'categories' => collect(PhysioCatalogue::EXERCISE_CATEGORIES)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
                'levels' => collect(PhysioCatalogue::LEVELS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
            ],
            'links' => ['update' => route('admin.settings.physio.update'), 'self' => route('admin.settings.physio'), 'patient_form' => route('admin.settings.patient-form'), 'automation' => route('admin.settings.automation')],
        ]);
    }

    /** The Physio settings tab, for a clinic that practises physiotherapy. */
    private function physioLink(Clinic $clinic): ?string
    {
        return app(ClinicSpecialties::class)->active($clinic, PhysioCareSummary::SPECIALTY) !== null ? route('admin.settings.physio') : null;
    }

    public function updatePhysio(Request $request, ClinicContext $context, PhysioCatalogue $catalogue, ClinicSpecialties $specialties): RedirectResponse
    {
        $clinic = $context->clinic();
        abort_if($specialties->active($clinic, PhysioCareSummary::SPECIALTY) === null, 404);
        $data = $request->validate([
            'treatments' => ['present', 'array', 'max:40'],
            'treatments.*.key' => ['nullable', 'string', 'max:64'],
            'treatments.*.label' => ['required', 'string', 'max:60'],
            'treatments.*.minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'treatments.*.price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'treatments.*.active' => ['boolean'],
            'addons' => ['present', 'array', 'max:40'],
            'addons.*.key' => ['nullable', 'string', 'max:64'],
            'addons.*.label' => ['required', 'string', 'max:60'],
            'addons.*.minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'addons.*.price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'addons.*.active' => ['boolean'],
            'problems' => ['present', 'array', 'max:40'],
            'problems.*.key' => ['nullable', 'string', 'max:64'],
            'problems.*.label' => ['required', 'string', 'max:60'],
            'problems.*.sided' => ['boolean'],
            'problems.*.active' => ['boolean'],
            'problems.*.types' => ['required', 'array', 'min:1', 'max:12'],
            'problems.*.types.*.key' => ['nullable', 'string', 'max:64'],
            'problems.*.types.*.label' => ['required', 'string', 'max:60'],
            'problems.*.types.*.treatments' => ['present', 'array', 'max:20'],
            'problems.*.types.*.treatments.*' => ['string', 'max:64'],
            // Empty: the visit costs the treatments given; set: the type's fixed (bundle) price.
            'problems.*.types.*.price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'problems.*.packages' => ['present', 'array', 'max:12'],
            'problems.*.packages.*.id' => ['nullable', 'string', 'max:20'],
            'problems.*.packages.*.name' => ['required', 'string', 'max:60'],
            'problems.*.packages.*.sessions' => ['required', 'integer', 'min:1', 'max:200'],
            'problems.*.packages.*.price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'problems.*.packages.*.valid_days' => ['required', 'integer', 'min:1', 'max:730'],
            'problems.*.packages.*.types' => ['present', 'array', 'max:12'],
            'problems.*.packages.*.types.*' => ['string', 'max:64'],
            'problems.*.diagnoses' => ['nullable', 'array', 'max:30'],
            'problems.*.diagnoses.*' => ['string', 'max:120'],
            'problems.*.exercises' => ['nullable', 'array', 'max:30'],
            'problems.*.exercises.*' => ['string', 'max:64'],
            // The clinic's exercise library (no pictures or videos).
            'exercises' => ['sometimes', 'array', 'max:200'],
            'exercises.*.key' => ['nullable', 'string', 'max:64'],
            'exercises.*.label' => ['required', 'string', 'max:150'],
            'exercises.*.category' => ['nullable', 'in:'.implode(',', array_keys(PhysioCatalogue::EXERCISE_CATEGORIES))],
            'exercises.*.description' => ['nullable', 'string', 'max:500'],
            'exercises.*.instructions' => ['nullable', 'string', 'max:500'],
            'exercises.*.precautions' => ['nullable', 'string', 'max:500'],
            'exercises.*.level' => ['nullable', 'in:'.implode(',', array_keys(PhysioCatalogue::LEVELS))],
            'exercises.*.equipment' => ['nullable', 'string', 'max:120'],
            'exercises.*.sets' => ['nullable', 'integer', 'min:0', 'max:50'],
            'exercises.*.reps' => ['nullable', 'integer', 'min:0', 'max:500'],
            'exercises.*.hold' => ['nullable', 'integer', 'min:0', 'max:600'],
            'exercises.*.active' => ['boolean'],
        ]);

        try {
            $catalogue->save($clinic, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['physio' => $e->getMessage()]);
        }

        return back()->with('success', 'Physio price list saved.');
    }

    public function updateAutomation(Request $request, ClinicContext $context, AppointmentSettingsService $appointments, ClinicWhatsApp $whatsApp): RedirectResponse
    {
        $clinic = $context->clinic();
        $data = $request->validate([
            'auto_no_show' => ['required', 'boolean'],
            'no_show_after_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'recall_months' => ['required', 'integer', Rule::in(self::RECALL_MONTHS)],
            'staff_alerts' => ['sometimes', 'boolean'],
            'morning_digest' => ['sometimes', 'boolean'],
            'auto_assign_doctor' => ['sometimes', 'boolean'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'require_opt_in' => ['required', 'boolean'],
            'automations' => ['present', 'array', 'max:60'],
            'automations.*' => ['string', Rule::in(array_keys(ClinicWhatsApp::catalog()))],
        ]);

        // Only the two booking settings change; the rest of the appointment settings stay as they are.
        $appointments->update($clinic, [...$appointments->resolve($clinic), 'auto_no_show' => $data['auto_no_show'], 'no_show_after_minutes' => $data['no_show_after_minutes']], $request->user());

        $before = (array) ($clinic->communication_config ?? []);
        $changed = array_filter([
            'recall_months' => $data['recall_months'],
            'staff_alerts' => array_key_exists('staff_alerts', $data) ? (bool) $data['staff_alerts'] : null,
            'morning_digest' => array_key_exists('morning_digest', $data) ? (bool) $data['morning_digest'] : null,
            'auto_assign_doctor' => array_key_exists('auto_assign_doctor', $data) ? (bool) $data['auto_assign_doctor'] : null,
        ], fn ($value, string $key) => $value !== null && ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            $clinic->update(['communication_config' => [...$before, ...$changed]]);
            ClinicSettingAudit::create([
                'clinic_id' => $clinic->id, 'user_id' => $request->user()->id, 'section' => 'automation',
                'old_values' => array_intersect_key($before, $changed), 'new_values' => $changed,
            ]);
        }

        $whatsApp->configure($clinic, [
            'enabled' => $data['whatsapp_enabled'],
            'mode' => $whatsApp->settings($clinic)->mode ?? ClinicWhatsAppSettings::COMMON,
            'require_opt_in' => $data['require_opt_in'],
            'automations' => $data['automations'],
        ], $request->user());

        return back()->with('success', 'Automation settings saved.');
    }

    public function updateCaseNumber(UpdateCaseNumberConfigRequest $request, ClinicContext $context): RedirectResponse
    {
        $this->caseNumberConfigService->update($context->clinic(), $request->validated(), $request->user());

        return back()->with('success', 'Case number format saved.');
    }

    /**
     * Update the clinic's branding.
     *
     * `logo`/`invoice_logo` are stored on the `public` disk, not through
     * `FileEngine` — that service scopes to `Organization` for private,
     * virus-scanned clinical documents served through signed URLs. A
     * clinic's logo is the opposite: shown on the pre-auth login page before
     * any session exists, so it has to be a plain public URL like any other
     * static brand asset.
     */
    public function update(Request $request, ClinicContext $context): RedirectResponse
    {
        $clinic = $context->clinic();

        $validated = $request->validate([
            /*
             * SVG deliberately excluded — same reasoning as
             * `CrmDocumentController`'s document upload: an SVG can carry an
             * embedded script, and this file is served straight back out as
             * a public URL on the pre-auth login page.
             */
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'display_name' => ['nullable', 'string', 'max:255'],

            'legal_name' => ['nullable', 'string', 'max:255'],
            'use_clinic_logo_for_invoice' => ['boolean'],
            'invoice_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            if ($clinic->logo_path) {
                Storage::disk('public')->delete($clinic->logo_path);
            }

            $validated['logo_path'] = $request->file('logo')->store('clinics/'.$clinic->id, 'public');
        }
        unset($validated['logo']);

        if ($request->hasFile('invoice_logo')) {
            if ($clinic->invoice_logo_path) {
                Storage::disk('public')->delete($clinic->invoice_logo_path);
            }

            $validated['invoice_logo_path'] = $request->file('invoice_logo')->store('clinics/'.$clinic->id.'/invoice', 'public');
        }
        unset($validated['invoice_logo']);

        $clinic->update($validated);

        return back()->with('success', 'Branding updated successfully.');
    }
}
