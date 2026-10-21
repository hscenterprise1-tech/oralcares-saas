<?php

namespace App\Http\Controllers\Clinic;

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
use App\PatientStatus;
use App\Services\ClinicContext;
use App\Services\Patient\Patient360\Patient360Access;
use App\Services\Patient\Patient360\Patient360SectionPolicy;
use App\Services\Patient\Patient360\Patient360Summary;
use App\Services\Security\Malware\ScannedUploadIntake;
use App\Services\Security\Malware\UploadRejectedException;
use App\Services\Security\SecurityAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        app(SecurityAudit::class)->record('patient.viewed', $patient);

        $user = $request->user();
        $lens = $this->policy->lens($user, $patient);
        $fields = $lens['fields'];
        $open = $fields['clinical'] ? $this->access->openConsultation($user, $patient) : null;
        $pending = $this->policy->pendingRequests($user, $patient);
        $base = fn (string $section) => route('clinic.patients.360.section', [$patient->id, $section]);

        return Inertia::render('ClinicPortal/Patients/Show', [
            'patient' => $this->summary->identity($patient),
            'state' => $this->summary->state($patient, $user),
            'context' => $this->summary->context($patient, $user),
            'overview' => Inertia::defer(fn () => $this->summary->overview($patient, $user)),
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
            'sections' => $lens['sections'],
            'endpoints' => [
                'timeline' => $base('timeline'),
                'clinical' => $base('encounters'),
                'appointments' => $base('appointments'),
                'treatment' => $base('treatment'),
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
            'actions' => $this->policy->actions($user, $patient, $open, $pending),
            // Practitioners only, with the specialty they recorded (or none).
            'practitioners' => ($fields['appointments'] || $this->access->canManageFollowUps($user))
                ? ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('status', 'active')
                    ->whereHas('practitionerProfile')->with('practitionerProfile:id,clinic_employee_id,specialty')
                    ->orderBy('name')->limit(200)->get(['id', 'name'])
                    ->map(fn (ClinicEmployee $employee) => ['id' => $employee->id, 'name' => $employee->name, 'specialty' => $employee->practitionerProfile?->specialty])
                    ->values()
                : [],
        ]);
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
