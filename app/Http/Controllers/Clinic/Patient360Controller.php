<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\AttachedDocument;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicEmployee;
use App\Models\DeletionRequest;
use App\Models\Patient;
use App\Models\PatientMerge;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use App\PatientStatus;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\ClinicContext;
use App\Services\Patient\Patient360\Patient360Access;
use App\Services\Patient\Patient360\Patient360SectionPolicy;
use App\Services\Patient\Patient360\Patient360Sections;
use App\Services\Patient\Patient360\Patient360Summary;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\Physio\PhysioCareSummary;
use App\Services\Security\Malware\ScannedUploadIntake;
use App\Services\Security\Malware\UploadRejectedException;
use App\Services\Security\SecurityAudit;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\Clinical\DefaultClinicalCatalogue;
use App\Support\ClinicClock;
use App\Support\ClinicFrontend;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Patient 360: the patient's workspace.
 *
 * The first response carries only the first screen — identity, state, the
 * quick clinical context and what the user may do. The overview is deferred;
 * every other section is fetched from `Patient360SectionController` when it
 * is opened, a page at a time.
 *
 * Access: the patient must be in the active clinic (`PatientPolicy::view`);
 * clinical, billing and appointment parts follow their own permissions, and
 * branch-held records are limited to the member's branches.
 */
class Patient360Controller extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected Patient360Access $access,
        protected Patient360Summary $summary,
        protected Patient360SectionPolicy $policy,
    ) {}

    public function show(Request $request, Patient $patient): Response|RedirectResponse
    {
        $this->authorize('view', $patient);
        $this->assertInClinic($patient);
        // A merged record is kept for history; its chart lives on the survivor.
        if ($patient->status === PatientStatus::Merged) {
            $survivorId = PatientMerge::query()->where('clinic_id', $patient->clinic_id)->where('source_patient_id', $patient->id)->value('survivor_patient_id');
            if ($survivorId) {
                return redirect()->route('clinic.patients.show', $survivorId)->with('success', 'This record was merged into this patient.');
            }
        }
        // A deleted record is kept in the database as history; it no longer opens.
        if ($patient->status === PatientStatus::Deleted) {
            return redirect()->route('clinic.patients.index')->with('success', 'The patient was deleted.');
        }
        app(SecurityAudit::class)->record('patient.viewed', $patient);

        $user = $request->user();
        $lens = $this->policy->lens($user, $patient);
        $fields = $lens['fields'];
        $open = $fields['clinical'] ? $this->access->openConsultation($user, $patient) : null;
        $pending = $this->policy->pendingRequests($user, $patient);
        $base = fn (string $section) => route('clinic.patients.360.section', [$patient->id, $section]);

        // A section is shown once its screen is built (`ClinicFrontend`); the policy decided who may open it.
        $sections = collect($lens['sections'])->map(fn (array $section) => [...$section, 'built' => ClinicFrontend::patient360Section($section['key'])])->values()->all();
        $built = array_column(array_filter($sections, fn (array $section) => $section['built']), 'key');

        return Inertia::render('ClinicPortal/Patients/Show', [
            'patient' => $this->summary->identity($patient, $user),
            'state' => $this->summary->state($patient, $user),
            'context' => $this->summary->context($patient, $user),
            'profile' => $this->summary->profile($patient, $user),
            // Each open section's first page, exactly as its endpoint answers it, so every zone opens with its data.
            'preloaded' => $this->preloaded($patient, $user, Patient360SectionPolicy::zonesFor($built)),
            // Sent with the page (not deferred): the Overview opens complete, with no second request.
            'overview' => fn () => [
                ...$this->summary->overview($patient, $user),
                // The latest documents, exactly as the Documents section lists them for this member (null when they may not open it).
                'documents' => $this->policy->allows($user, $patient, 'documents')
                    ? collect(app(Patient360Sections::class)->documents($patient, $user, 1)['data'])->take(4)->values()->all()
                    : null,
                // Physiotherapy at a glance (read-only) and where the desk confirms today's session; null outside physiotherapy clinics.
                'physio_care' => app(PhysioCareSummary::class)->forPatient($patient, $user, $this->clinicContext->branch()),
            ],
            'lens' => [
                'persona' => $lens['persona'],
                'default_section' => $lens['default_section'],
                'owner' => $lens['owner'],
                'owner_mode' => $lens['owner_mode'],
                'governance' => $lens['governance'],
                'fields' => collect($fields)->only(['clinical', 'clinical_write', 'financial', 'refunds', 'investigation_results', 'dental', 'physio'])->all(),
                'pending_requests' => $pending,
                // Records with a request already waiting on the owner, as "action:target".
                'open_requests' => DeletionRequest::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                    ->whereNotNull('open_key')->limit(200)->get(['action_type', 'target_id'])
                    ->map(fn (DeletionRequest $r) => "{$r->action_type}:{$r->target_id}")->values()->all(),
            ],
            'sections' => $sections,
            // The seven zones (`Patient360SectionPolicy::ZONES`), each with the sections this member may open.
            'zones' => Patient360SectionPolicy::zonesFor($built),
            // Above the zones, on every one: the patient's open cases (modules the clinic offers).
            'active_cases' => $this->policy->allows($user, $patient, 'cases') ? app(Patient360Sections::class)->activeCases($patient, $user) : [],
            // Every role opens on the Overview; its own sections are one tap away.
            'open_section' => 'overview',
            'endpoints' => [
                'timeline' => $base('timeline'),
                'visits' => $base('visits'),
                'clinical' => $base('encounters'),
                'appointments' => $base('appointments'),
                'treatment' => $base('treatment'),
                'cases' => $base('cases'),
                'prescriptions' => $base('prescriptions'),
                'investigations' => $base('investigations'),
                'billing' => $base('billing'),
                'documents' => $base('documents'),
                'consents' => route('clinic.patients.consents.index', $patient, false),
                'follow-ups' => $base('follow-ups'),
                'communication' => $base('communications'),
                'requests' => $base('requests'),
                'audit' => $base('audit'),
                'dental' => $base('dental'),
                'physio' => $base('physio'),
                'availability' => $fields['appointments'] ? route('clinic.appointments.availability') : null,
                'governance_request' => route('clinic.approvals.store'),
                'governance_direct' => route('clinic.approvals.direct'),
                'owner_mode' => route('clinic.owner-mode.store'),
                'patients_index' => route('clinic.patients.index'),
            ],
            'actions' => collect($this->policy->actions($user, $patient, $open, $pending))
                ->map(fn (array $action) => [...$action, 'available' => $this->actionAvailable($action)])->values()->all(),
            'links' => [
                'index' => route('clinic.patients.index'),
                // The Billing register filtered to this patient, for members it admits (money, billing.view, a billing workspace).
                'bills' => $this->access->canViewBilling($user) && $user->hasClinicPermission(ClinicPermission::BillingView)
                    && array_intersect(['command_center', 'front_desk', 'billing'], app(ClinicAccess::class)->workspaces()) !== [] && filled($patient->uhid)
                    ? route('clinic.billing.index', ['q' => $patient->uhid]) : null,
            ],
            // Changing the medical-history conditions: clinical staff allowed to write this patient's record
            // (`PatientClinicalEntryController`, which checks the same again), the clinic's own options.
            'condition_editor' => $user->can('writeClinicalData', $patient)
                ? [
                    'url' => route('clinic.patients.clinical-entries.update', [$patient, DefaultClinicalCatalogue::KIND_CONDITION]),
                    'options' => collect(app(ClinicalCatalogue::class)->effective($patient->clinic_id, DefaultClinicalCatalogue::KIND_CONDITION, $this->specialtyKeys($patient)))
                        ->map(fn (array $item) => ['key' => $item['key'], 'name' => $item['name'], 'severity' => $item['severity'] ?? null])->values()->all(),
                    'severities' => DefaultClinicalCatalogue::SEVERITIES,
                    // Whether a name outside the clinic's list may be typed (Settings → Quick Care); the endpoint enforces it.
                    'custom_allowed' => ! app(PatientClinicalEntries::class)->conditionsFromCatalogue($patient->clinic_id),
                ] : null,
            // Managing safety alerts and the medical-history notes (`PatientSafetyController`, which checks the same again).
            'safety_editor' => $user->can('writeClinicalData', $patient)
                ? [
                    'alerts' => route('clinic.patients.alerts.store', $patient),
                    'resolve' => route('clinic.patients.alerts.resolve', [$patient, '__ALERT__']),
                    'notes' => route('clinic.patients.medical-notes.update', $patient),
                    'severities' => DefaultClinicalCatalogue::SEVERITIES,
                ] : null,
            // Practitioners only, with the specialty they recorded (or none).
            'practitioners' => ($fields['appointments'] || $this->access->canManageFollowUps($user) || $user->can('update', $patient))
                ? ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('status', 'active')
                    ->whereHas('practitionerProfile')->with('practitionerProfile:id,clinic_employee_id,specialty')
                    ->orderBy('name')->limit(200)->get(['id', 'name'])
                    ->map(fn (ClinicEmployee $employee) => ['id' => $employee->id, 'name' => $employee->name, 'specialty' => $employee->practitionerProfile?->specialty])
                    ->values()
                : [],
        ]);
    }

    /**
     * The printable patient summary: the page's own data for this member
     * (the same role and branch rules), on paper. Audited.
     */
    public function print(Request $request, Patient $patient): \Illuminate\Http\Response|RedirectResponse
    {
        $this->authorize('view', $patient);
        $clinic = $this->assertInClinic($patient);
        if ($patient->status === PatientStatus::Merged) {
            $survivorId = PatientMerge::query()->where('clinic_id', $patient->clinic_id)->where('source_patient_id', $patient->id)->value('survivor_patient_id');
            if ($survivorId) {
                return redirect()->route('clinic.patients.print', $survivorId);
            }
        }
        $user = $request->user();
        app(SecurityAudit::class)->record('patient.summary_printed', $patient);

        return response()->view('clinic.patient_summary_printable', [
            'clinic' => $clinic,
            'patient' => $this->summary->identity($patient, $user),
            'profile' => $this->summary->profile($patient, $user),
            'context' => $this->summary->context($patient, $user),
            'overview' => $this->summary->overview($patient, $user),
            'printedBy' => $user->name,
            'printedAt' => ClinicClock::now(),
        ]);
    }

    /**
     * The page an action opens, for actions that open one. An action whose
     * page or section is not built yet is sent but not offered.
     */
    private const ACTION_PAGES = [
        'continue_consultation' => 'clinic.consultations.show',
        'start_consultation' => 'clinic.consultations.show',
        'prescribe' => 'clinic.consultations.show',
        'order_investigation' => 'clinic.consultations.show',
        'treatment_plan' => 'clinic.consultations.show',
        'book' => 'clinic.appointments.store',
        'edit' => 'clinic.patients.edit',
        'transfer' => 'clinic.patients.transfer',
        'upload_document' => 'clinic.patients.documents.store',
        'approvals' => 'clinic.approvals.index',
        'checkout' => 'clinic.billing.checkout',
    ];

    /**
     * The first page of every section in this member's zones, keyed by the
     * page's endpoint name and built by the same `Patient360Sections` call its
     * endpoint makes (first page, default filters), so a zone opens with its
     * data instead of waiting on a request per card. Later pages, filters and
     * refreshes still go through `Patient360SectionController`. A section that
     * fails to build is left out; the page fetches it as before.
     *
     * @param  list<array{key: string, label: string, sections: list<string>}>  $zones
     * @return array<string, mixed>
     */
    private function preloaded(Patient $patient, User $user, array $zones): array
    {
        $sections = app(Patient360Sections::class);
        $builders = [
            'timeline' => ['timeline', fn () => $sections->timeline($patient, $user, null, 1)],
            'visits' => ['visits', fn () => $sections->visits($patient, $user, 1)],
            'clinical' => ['encounters', fn () => $sections->encounters($patient, $user, 1)],
            'appointments' => ['appointments', fn () => $sections->appointments($patient, $user, 'upcoming', 1)],
            'treatment' => ['treatment', fn () => $sections->treatment($patient, $user, 1)],
            'cases' => ['cases', fn () => $sections->cases($patient, $user)],
            'prescriptions' => ['prescriptions', fn () => $sections->prescriptions($patient, $user, 1)],
            'investigations' => ['investigations', fn () => $sections->investigations($patient, $user, null, 1)],
            'billing' => ['billing', fn () => $sections->billing($patient, $user, 1)],
            'documents' => ['documents', fn () => $sections->documents($patient, $user, 1)],
            'follow-ups' => ['follow-ups', fn () => $sections->followUps($patient, $user)],
            'communication' => ['communications', fn () => $sections->communications($patient, $user, 1)],
            'requests' => ['requests', fn () => $sections->requests($patient, $user, 1)],
            'audit' => ['audit', fn () => $sections->audit($patient, 1)],
            'dental' => ['dental', fn () => $sections->dental($patient, $user)],
            'physio' => ['physio', fn () => $sections->physio($patient, $user)],
        ];
        $keys = array_unique(array_merge(...array_map(fn (array $zone) => $zone['sections'], $zones ?: [['sections' => []]])));

        $preloaded = [];
        foreach ($keys as $key) {
            if (! isset($builders[$key]) || ! $this->policy->allows($user, $patient, $builders[$key][0])) {
                continue;
            }
            try {
                $preloaded[$key] = $builders[$key][1]();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $preloaded;
    }

    /**
     * Whether the page can offer an action now: check-in opens the desk's own
     * intake popup; a section action needs its section built; anything else
     * needs its page built.
     *
     * @param  array<string, mixed>  $action
     */
    private function actionAvailable(array $action): bool
    {
        return match (true) {
            // Check-in opens the desk's intake popup; Assign doctor is a dialog on this page; the summary prints from its own route.
            in_array($action['key'], ['check_in', 'transfer', 'print'], true) => true,
            // The Billing section reads; collecting a payment stays on the Billing page.
            $action['key'] === 'collect_payment' => false,
            isset($action['section']) => ClinicFrontend::patient360Section($action['section']),
            default => isset(self::ACTION_PAGES[$action['key']]) && ClinicFrontend::available(self::ACTION_PAGES[$action['key']]),
        };
    }

    /**
     * Secure document upload: quarantine → malware scan → private disk. A
     * file can be filed against one of this patient's consultations or
     * investigation results.
     */
    public function uploadDocument(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorize('view', $patient);
        $clinic = $this->assertInClinic($patient);

        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'document_type' => ['nullable', 'string', 'in:medical_report,id_proof,xray,intraoral_photo,lab_report,other'],
            // Dental images are filed against the teeth they show.
            'tooth_numbers' => ['nullable', 'array', 'max:32'],
            'tooth_numbers.*' => ['integer', 'between:11,85'],
            'title' => ['nullable', 'string', 'max:255'],
            'encounter_id' => ['nullable', 'integer'],
            'clinical_order_item_id' => ['nullable', 'integer'],
        ]);

        $encounterId = null;
        if (! empty($validated['encounter_id'])) {
            $encounterId = ClinicalEncounter::query()->where('clinic_id', $clinic->id)->where('patient_id', $patient->id)
                ->whereKey($validated['encounter_id'])->value('id') ?? abort(404);
        }
        $orderItem = null;
        if (! empty($validated['clinical_order_item_id'])) {
            $orderItem = ClinicalOrderItem::query()->where('clinical_order_items.clinic_id', $clinic->id)
                ->whereHas('order', fn ($order) => $order->where('patient_id', $patient->id))
                ->with('order:id,encounter_id')
                ->find($validated['clinical_order_item_id']) ?? abort(404);
            $encounterId ??= $orderItem->order->encounter_id;
        }

        $file = $request->file('document');
        $fileName = ($validated['title'] ?? null) ?: $file->getClientOriginalName();

        try {
            $admitted = app(ScannedUploadIntake::class)->admit($file, "clinic_{$clinic->id}/patients/{$patient->id}/documents");
        } catch (UploadRejectedException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        $doc = AttachedDocument::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'encounter_id' => $encounterId,
            'clinical_order_item_id' => $orderItem?->id,
            'uploaded_by' => auth()->id(),
            'file_name' => $fileName,
            'file_path' => $admitted['path'],
            'file_size_bytes' => $file->getSize(),
            'document_type' => $validated['document_type'] ?? 'medical_report',
            'scan_status' => AttachedDocument::SCAN_CLEAN,
            'scanned_at' => now(),
            'scanner' => $admitted['scanner'],
            'file_sha256' => $admitted['sha256'],
            'tooth_numbers' => ! empty($validated['tooth_numbers']) ? array_values(array_unique(array_map('intval', $validated['tooth_numbers']))) : null,
        ]);

        PatientTimelineEvent::create([
            'organization_id' => $clinic->organization_id,
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'actor_id' => auth()->id(),
            'event_type' => 'document_uploaded',
            'context' => ['file_name' => $fileName, 'document_type' => $doc->document_type, 'uploaded_by' => auth()->user()?->name ?? 'Staff'],
        ]);

        app(SecurityAudit::class)->record('document.uploaded', $doc, [
            'patient_id' => $patient->id,
            'document_type' => $doc->document_type,
            'size_bytes' => $doc->file_size_bytes,
        ]);

        return back()->with('success', "Document '{$fileName}' uploaded securely.");
    }

    /**
     * Stream one of the patient's documents from private storage, looked up
     * through the patient and the clinic (another patient's id is a 404).
     * `?inline=1` opens a PDF or image in the browser instead of downloading.
     * Every access is audited.
     */
    public function downloadDocument(Request $request, Patient $patient, int $document): StreamedResponse
    {
        $this->authorize('view', $patient);
        $clinic = $this->assertInClinic($patient);

        $doc = AttachedDocument::query()->where('patient_id', $patient->id)->where('clinic_id', $clinic->id)->findOrFail($document);

        abort_unless($doc->isCleared(), 404);
        // A clinical document (a report, an image, a result) opens only for clinical roles.
        abort_unless($this->policy->canOpenDocument($request->user(), $patient, $doc), 403);
        abort_unless(Storage::disk('private')->exists($doc->file_path), 404);

        $inline = $request->boolean('inline') && in_array(strtolower(pathinfo($doc->file_path, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);
        app(SecurityAudit::class)->record($inline ? 'document.previewed' : 'document.downloaded', $doc, ['patient_id' => $patient->id]);

        return $inline
            ? Storage::disk('private')->response($doc->file_path, $this->safeDownloadName($doc), ['X-Content-Type-Options' => 'nosniff'])
            : Storage::disk('private')->download($doc->file_path, $this->safeDownloadName($doc));
    }

    /**
     * The patient's active specialties: the condition options offered are the
     * ones `PatientClinicalEntryController` will accept for this patient.
     *
     * @return list<string>
     */
    private function specialtyKeys(Patient $patient): array
    {
        return DB::table('patient_specialties as ps')->join('clinic_specialties as cs', 'cs.id', '=', 'ps.clinic_specialty_id')
            ->where('ps.clinic_id', $patient->clinic_id)->where('ps.patient_id', $patient->id)->where('ps.status', 'active')
            ->pluck('cs.specialty_key')->all();
    }

    private function assertInClinic(Patient $patient): Clinic
    {
        $clinic = $this->clinicContext->clinic();
        abort_if(! $clinic || $patient->clinic_id !== $clinic->id, 404);

        return $clinic;
    }

    /**
     * The stored name with anything a header or a file system would trip on
     * removed, and the stored file's extension kept.
     */
    private function safeDownloadName(AttachedDocument $doc): string
    {
        $extension = pathinfo($doc->file_path, PATHINFO_EXTENSION);
        $base = Str::of(pathinfo((string) $doc->file_name, PATHINFO_FILENAME))->ascii()->replaceMatches('/[^A-Za-z0-9 ._-]+/', '')->trim()->limit(120, '');

        return ($base->isEmpty() ? 'document' : (string) $base).($extension ? ".{$extension}" : '');
    }
}
