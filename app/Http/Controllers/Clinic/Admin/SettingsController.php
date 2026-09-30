<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\UpdateCaseNumberConfigRequest;
use App\Models\ClinicSettingAudit;
use App\Services\ClinicContext;
use App\Services\Patient\CaseNumberConfigService;
use App\Services\PatientFieldConfigService;
use App\Services\Reception\PatientFlowConfigService;
use App\Support\ClinicClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
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

        return Inertia::render('Clinic/Admin/Settings/Index', [
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
     * hidden. Read by both `Clinic/Patients/Create.tsx` (what renders) and
     * `RegisterPatientRequest::rules()` (what's enforced) — see
     * `PatientFieldConfigService` for why those two can't drift apart.
     */
    public function updatePatientFields(Request $request, ClinicContext $context): RedirectResponse
    {
        $clinic = $context->clinic();

        $states = implode(',', PatientFieldConfigService::STATES);
        $rules = [];
        foreach (array_keys(PatientFieldConfigService::CONFIGURABLE_FIELDS) as $field) {
            $rules[$field] = ['required', 'string', "in:{$states}"];
        }

        $validated = $request->validate($rules);

        $this->fieldConfigService->update($clinic, $validated);

        return back()->with('success', 'Patient registration fields updated.');
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
