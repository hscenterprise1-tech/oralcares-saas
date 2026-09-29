<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AttachedDocument;
use App\Models\ClinicEmployee;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\PatientVisit;
use App\Models\Prescription;
use App\Services\ClinicContext;
use App\Services\Security\Malware\ScannedUploadIntake;
use App\Services\Security\Malware\UploadRejectedException;
use App\Services\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Patient360Controller extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext
    ) {}

    /**
     * Display the Patient 360 Central Workspace.
     */
    public function show(Request $request, Patient $patient): Response
    {
        $this->authorize('view', $patient);

        // Record access: who opened this patient's record, and when.
        app(SecurityAudit::class)->record('patient.viewed', $patient);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic || $patient->clinic_id !== $clinic->id) {
            abort(403, 'Patient does not belong to the active clinic context.');
        }

        $patient->load(['profile', 'medicalHistory', 'alerts', 'currentPractitioner']);

        // 1. Check Today's Operational Status (Active Appointment or Visit today)
        $todayAppointment = Appointment::where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->whereDate('scheduled_at', today())
            ->with(['practitioner'])
            ->latest('scheduled_at')
            ->first();

        $todayVisit = PatientVisit::where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->whereDate('visit_date', today())
            ->with(['doctor', 'queueItem'])
            ->latest()
            ->first();

        // 2. Upcoming Appointment (next future appointment)
        $upcomingAppointment = Appointment::where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->where('scheduled_at', '>=', now())
            ->whereNotIn('status', ['cancelled', 'completed', 'no_show'])
            ->with(['practitioner', 'branch'])
            ->orderBy('scheduled_at', 'asc')
            ->first();

        // 3. Aggregate Unified Timeline
        $timelineEvents = $this->gatherUnifiedTimeline($patient, $clinic);

        // 4. Appointments History
        $appointments = Appointment::where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->with(['practitioner', 'branch', 'creator'])
            ->orderBy('scheduled_at', 'desc')
            ->paginate(10, ['*'], 'appts_page');

        // 5. Visits History
        $visits = PatientVisit::where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->with(['doctor', 'branch', 'caseNumber', 'queueItem'])
            ->orderBy('visit_date', 'desc')
            ->paginate(10, ['*'], 'visits_page');

        // 6. Billing Invoices (Protected)
        //
        // Paginated rather than `->get()` — a patient billed for years could
        // otherwise have every invoice they've ever had pulled into memory
        // on every single page view, not just when the Billing tab is
        // opened. The summary cards still need to reflect *all* invoices,
        // not just the page shown, so they're their own aggregate queries
        // rather than a sum over the (now-partial) fetched collection.
        $canViewBilling = auth()->user()?->hasClinicPermission(ClinicPermission::BillingView) ?? false;
        $invoices = $this->emptyPage();
        $billingSummary = ['total_billed' => 0, 'total_paid' => 0, 'total_balance' => 0];

        if ($canViewBilling) {
            $invoiceQuery = Invoice::where('organization_id', $clinic->organization_id)->where('patient_id', $patient->id);

            $billingSummary = [
                'total_billed' => (clone $invoiceQuery)->sum('total_amount'),
                'total_paid' => (clone $invoiceQuery)->sum('amount_paid'),
                'total_balance' => (clone $invoiceQuery)->sum('balance_due'),
            ];

            $invoices = $invoiceQuery
                ->orderBy('created_at', 'desc')
                ->paginate(10, ['*'], 'invoices_page')
                ->through(fn ($inv) => [
                    'id' => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'status' => $inv->status,
                    'total_amount' => (float) $inv->total_amount,
                    'amount_paid' => (float) $inv->amount_paid,
                    'balance_due' => (float) $inv->balance_due,
                    'issued_at' => $inv->issued_at?->format('d M Y') ?? $inv->created_at?->format('d M Y'),
                ]);
        }

        // 7. Prescriptions (Protected) — same unbounded-history concern as
        // invoices above, same fix.
        $canViewClinical = auth()->user()?->hasClinicPermission(ClinicPermission::ClinicalView) ?? false;
        $prescriptions = $this->emptyPage();
        if ($canViewClinical) {
            $prescriptions = Prescription::where('clinic_id', $clinic->id)
                ->where('patient_id', $patient->id)
                ->with(['prescriber', 'items'])
                ->orderBy('created_at', 'desc')
                ->paginate(10, ['*'], 'prescriptions_page')
                ->through(fn ($rx) => [
                    'id' => $rx->id,
                    'prescription_number' => $rx->prescription_number ?? sprintf('RX-%d', $rx->id),
                    'status' => $rx->status ?? 'active',
                    'prescribed_by' => $rx->prescriber?->name ?? 'Attending Practitioner',
                    'created_at' => $rx->created_at?->format('d M Y'),
                    'items_count' => $rx->items->count(),
                    'items' => $rx->items->map(fn ($i) => [
                        'id' => $i->id,
                        'name' => $i->medication_name ?? $i->item_name ?? 'Medication',
                        'dosage' => $i->dosage ?? '1 tablet',
                        'frequency' => $i->frequency ?? 'Once daily',
                        'duration' => $i->duration ?? '5 days',
                    ]),
                ]);
        }

        // 8. Documents — same fix again: a long-standing patient's full
        // document history is not something every page view needs to load.
        $documents = AttachedDocument::where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->with('uploader')
            ->orderBy('created_at', 'desc')
            ->paginate(10, ['*'], 'documents_page')
            ->through(fn ($doc) => [
                'id' => $doc->id,
                'file_name' => $doc->file_name,
                'file_size_bytes' => $doc->file_size_bytes,
                'document_type' => $doc->document_type,
                'uploaded_by' => $doc->uploader?->name ?? 'Staff',
                'created_at' => $doc->created_at?->format('d M Y, h:i A'),
                // An ordinary portal link, not a signed bearer URL. Opening it
                // needs a signed-in session in this clinic and patient access,
                // and every download is audited (see `downloadDocument`).
                'download_url' => $doc->isCleared() ? route('clinic.patients.documents.download', [$patient, $doc->id]) : null,
                'scan_status' => $doc->scan_status,
            ]);

        // 9. Available Practitioners for Action Modals
        $practitioners = ClinicEmployee::activePractitioners($clinic->id);

        return Inertia::render('Clinic/Patients/Show', [
            'patient' => [
                'id' => $patient->id,
                'uhid' => $patient->uhid ?? '—',
                'name' => $patient->full_name,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'phone' => $patient->phone,
                'alternate_phone' => $patient->alternate_phone,
                'email' => $patient->email,
                'age' => $patient->age,
                'gender' => $patient->gender,
                'blood_group' => $patient->blood_group,
                'status' => $patient->status?->value ?? 'active',
                'created_at' => $patient->created_at?->format('d M Y'),
                'profile' => $patient->profile ? [
                    'address' => $patient->profile->address,
                    'city' => $patient->profile->city,
                    'state' => $patient->profile->state,
                    'pincode' => $patient->profile->pincode ?? $patient->profile->postal_code,
                    'emergency_contact_name' => $patient->profile->emergency_contact_name,
                    'emergency_contact_phone' => $patient->profile->emergency_contact_phone,
                ] : null,
                'alerts' => $patient->alerts->map(fn ($alert) => [
                    'id' => $alert->id,
                    'type' => $alert->type,
                    'description' => $alert->description,
                    'severity' => $alert->severity,
                ]),
                'allergies' => $patient->medicalHistory?->allergies ?? [],
                'current_practitioner' => $patient->currentPractitioner ? [
                    'id' => $patient->currentPractitioner->id,
                    'name' => $patient->currentPractitioner->name,
                ] : null,
            ],
            'todayStatus' => [
                'has_active_today' => ($todayAppointment !== null || $todayVisit !== null),
                'appointment' => $todayAppointment ? [
                    'id' => $todayAppointment->id,
                    'scheduled_time' => $todayAppointment->scheduled_at?->format('h:i A'),
                    'practitioner_name' => $todayAppointment->practitioner?->name ?? 'Assigned Provider',
                    'status' => $todayAppointment->status?->value,
                ] : null,
                'visit' => $todayVisit ? [
                    'id' => $todayVisit->id,
                    'queue_status' => $todayVisit->queue_status ?? 'waiting',
                    'visit_status' => $todayVisit->visit_status ?? 'checked_in',
                    'doctor_name' => $todayVisit->doctor?->name ?? 'Attending Provider',
                    'queue_position' => $todayVisit->queueItem?->position,
                ] : null,
            ],
            'upcomingAppointment' => $upcomingAppointment ? [
                'id' => $upcomingAppointment->id,
                'date' => $upcomingAppointment->scheduled_at?->format('d M Y'),
                'time' => $upcomingAppointment->scheduled_at?->format('h:i A'),
                'practitioner_name' => $upcomingAppointment->practitioner?->name ?? 'Assigned Provider',
                'branch_name' => $upcomingAppointment->branch?->name ?? 'Main Clinic',
                'status' => $upcomingAppointment->status?->value,
            ] : null,
            'timeline' => $timelineEvents,
            'appointments' => $appointments,
            'visits' => $visits,
            'billing' => [
                'is_accessible' => $canViewBilling,
                'summary' => $billingSummary,
                'invoices' => $invoices,
            ],
            'prescriptions' => [
                'is_accessible' => $canViewClinical,
                'items' => $prescriptions,
            ],
            'documents' => $documents,
            'practitioners' => $practitioners,
            'permissions' => [
                'can_view_billing' => $canViewBilling,
                'can_view_clinical' => $canViewClinical,
                'can_manage_docs' => auth()->user()?->hasClinicPermission(ClinicPermission::DocumentsManage) ?? true,
                'can_edit_patient' => auth()->user()?->hasClinicPermission(ClinicPermission::PatientEdit) ?? true,
                // Same permission as editing demographics — Reception and
                // Admin/Owner, never Practitioner — reused rather than a
                // dedicated permission, matching this policy's convention
                // elsewhere (see `PatientPolicy::writeClinicalData()`).
                'can_transfer_patient' => auth()->user()?->hasClinicPermission(ClinicPermission::PatientEdit) ?? false,
            ],
        ]);
    }

    /**
     * Dedicated Timeline endpoint (JSON or tab).
     */
    public function timeline(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic || $patient->clinic_id !== $clinic->id) {
            abort(403);
        }

        $events = $this->gatherUnifiedTimeline($patient, $clinic);

        return response()->json($events);
    }

    /**
     * Dedicated Billing tab endpoint with backend permission gate.
     */
    public function billing(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        app(SecurityAudit::class)->record('patient.billing_viewed', $patient);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic || $patient->clinic_id !== $clinic->id) {
            abort(403, 'Unauthorized clinic context.');
        }

        if (! auth()->user()?->hasClinicPermission(ClinicPermission::BillingView)) {
            abort(403, 'Unauthorized. Billing view permission required.');
        }

        $invoices = Invoice::where('organization_id', $clinic->organization_id)
            ->where('patient_id', $patient->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'invoices' => $invoices,
            'total_balance' => $invoices->sum('balance_due'),
        ]);
    }

    /**
     * Dedicated Clinical tab endpoint with backend permission gate.
     */
    public function clinical(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        app(SecurityAudit::class)->record('patient.clinical_viewed', $patient);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic || $patient->clinic_id !== $clinic->id) {
            abort(403, 'Unauthorized clinic context.');
        }

        if (! auth()->user()?->hasClinicPermission(ClinicPermission::ClinicalView)) {
            abort(403, 'Unauthorized. Clinical record view permission required.');
        }

        $prescriptions = Prescription::where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->with(['prescriber', 'items'])
            ->get();

        return response()->json([
            'prescriptions' => $prescriptions,
        ]);
    }

    /**
     * Secure Patient Document Upload.
     */
    public function uploadDocument(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorize('view', $patient);

        $clinic = $this->clinicContext->clinic();
        if (! $clinic || $patient->clinic_id !== $clinic->id) {
            abort(403, 'Unauthorized clinic context.');
        }

        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'document_type' => ['nullable', 'string', 'in:medical_report,id_proof,xray,lab_report,other'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('document');
        // `title` is optional; reading it with `?:` alone crashed every upload
        // sent without one ("Undefined array key").
        $fileName = ($validated['title'] ?? null) ?: $file->getClientOriginalName();

        // Quarantine → malware scan → private disk. Nothing reaches the private
        // disk, or gets a database row, without a clean verdict.
        try {
            $admitted = app(ScannedUploadIntake::class)->admit($file, "clinic_{$clinic->id}/patients/{$patient->id}/documents");
        } catch (UploadRejectedException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        $doc = AttachedDocument::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'uploaded_by' => auth()->id(),
            'file_name' => $fileName,
            'file_path' => $admitted['path'],
            'file_size_bytes' => $file->getSize(),
            'document_type' => $validated['document_type'] ?? 'medical_report',
            'scan_status' => AttachedDocument::SCAN_CLEAN,
            'scanned_at' => now(),
            'scanner' => $admitted['scanner'],
            'file_sha256' => $admitted['sha256'],
        ]);

        PatientTimelineEvent::create([
            'organization_id' => $clinic->organization_id,
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'actor_id' => auth()->id(),
            'event_type' => 'document_uploaded',
            'context' => [
                'file_name' => $fileName,
                'document_type' => $doc->document_type,
                'uploaded_by' => auth()->user()?->name ?? 'Staff',
            ],
        ]);

        app(SecurityAudit::class)->record('document.uploaded', $doc, [
            'patient_id' => $patient->id,
            'document_type' => $doc->document_type,
            'size_bytes' => $doc->file_size_bytes,
        ]);

        return back()->with('success', "Document '{$fileName}' uploaded securely.");
    }

    /**
     * Stream one of a patient's documents from private storage.
     *
     * This replaces a 60-minute signed URL to the generic file endpoint, which
     * anyone holding the link could open without signing in, from any clinic,
     * with nothing recorded. Now the document is looked up through the patient
     * (`patient_id`) and the clinic scope, so a document id from another
     * patient or clinic is a 404. Patient access is checked by the same policy
     * as the Patient 360 page, and each download is written to the security
     * trail.
     */
    public function downloadDocument(Patient $patient, int $document): StreamedResponse
    {
        $this->authorize('view', $patient);

        $clinic = $this->clinicContext->clinic();
        abort_if(! $clinic || $patient->clinic_id !== $clinic->id, 404);

        $doc = AttachedDocument::query()
            ->where('patient_id', $patient->id)
            ->where('clinic_id', $clinic->id)
            ->findOrFail($document);

        // A document that has not passed a malware scan is never served.
        abort_unless($doc->isCleared(), 404);
        abort_unless(Storage::disk('private')->exists($doc->file_path), 404);

        app(SecurityAudit::class)->record('document.downloaded', $doc, ['patient_id' => $patient->id]);

        return Storage::disk('private')->download($doc->file_path, $this->safeDownloadName($doc));
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

    /**
     * Helper to build a unified chronological activity feed from all domain models.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gatherUnifiedTimeline(Patient $patient, $clinic): array
    {
        $events = collect();

        // Each source below is capped to its most recent rows rather than
        // fetched in full: the feed only ever shows the latest 12 events
        // (`PatientTimelineFeed limit={12}` on the frontend), but without a
        // cap here a patient with years of history would have every
        // timeline event, appointment, visit and invoice they've ever had
        // pulled into memory and sorted on every single page view — a cost
        // that grows without bound the longer a patient has been coming to
        // the clinic. 50 per source is generous headroom over the 12 shown.
        $timelineSourceLimit = 50;

        // 1. System & Operational timeline events
        $timelineRecords = PatientTimelineEvent::where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->with('actor')
            ->latest()
            ->limit($timelineSourceLimit)
            ->get();

        foreach ($timelineRecords as $event) {
            $actorName = $event->actor?->name ?? ($event->context['operator'] ?? ($event->context['registered_by'] ?? 'Staff'));
            $title = match ($event->event_type) {
                'registered' => 'Patient Registered',
                'appointment_checked_in' => 'Appointment Checked In',
                'walk_in_registered' => 'Walk-in Registered',
                'document_uploaded' => 'Document Uploaded',
                'demographics_updated' => 'Demographic Details Updated',
                'doctor_transferred' => 'Doctor Transferred',
                default => ucwords(str_replace('_', ' ', $event->event_type)),
            };

            $category = match ($event->event_type) {
                'registered' => 'system',
                'appointment_checked_in', 'walk_in_registered' => 'reception',
                'document_uploaded' => 'document',
                'demographics_updated', 'doctor_transferred' => 'record',
                default => 'system',
            };

            $events->push([
                'id' => 'evt-'.$event->id,
                'type' => $event->event_type,
                'category' => $category,
                'title' => $title,
                'description' => $this->formatEventDescription($event),
                'timestamp' => $event->created_at ? $event->created_at->toISOString() : now()->toISOString(),
                'formatted_time' => $event->created_at ? $event->created_at->format('d M Y, h:i A') : '—',
                'actor_name' => $actorName,
                'badge_color' => match ($category) {
                    'reception' => 'emerald',
                    'document' => 'purple',
                    'billing' => 'amber',
                    'clinical' => 'blue',
                    'record' => 'slate',
                    default => 'indigo',
                },
            ]);
        }

        // 2. Appointments
        $appts = Appointment::where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->with(['practitioner', 'creator'])
            ->latest()
            ->limit($timelineSourceLimit)
            ->get();

        foreach ($appts as $appt) {
            $practitionerName = $appt->practitioner?->name ?? 'Attending Provider';
            $creatorName = $appt->creator?->name ?? 'Staff';
            $scheduledTime = $appt->scheduled_at ? $appt->scheduled_at->format('d M Y, h:i A') : '—';

            $events->push([
                'id' => 'appt-'.$appt->id,
                'type' => 'appointment_scheduled',
                'category' => 'appointment',
                'title' => 'Appointment Scheduled',
                'description' => "Scheduled with {$practitionerName} for {$scheduledTime} ({$appt->status?->value})",
                'timestamp' => $appt->created_at ? $appt->created_at->toISOString() : now()->toISOString(),
                'formatted_time' => $appt->created_at ? $appt->created_at->format('d M Y, h:i A') : '—',
                'actor_name' => $creatorName,
                'badge_color' => 'blue',
            ]);
        }

        // 3. Visits
        $visits = PatientVisit::where('clinic_id', $clinic->id)
            ->where('patient_id', $patient->id)
            ->with(['doctor', 'caseNumber'])
            ->latest()
            ->limit($timelineSourceLimit)
            ->get();

        foreach ($visits as $visit) {
            $docName = $visit->doctor?->name ?? 'Practitioner';
            $caseNum = $visit->caseNumber?->case_number ?? 'Case #'.$visit->id;

            $events->push([
                'id' => 'visit-'.$visit->id,
                'type' => 'visit_created',
                'category' => 'clinical',
                'title' => 'Clinical Visit Initiated',
                'description' => "Visit recorded with {$docName} (Case: {$caseNum}, Status: {$visit->visit_status})",
                'timestamp' => $visit->created_at ? $visit->created_at->toISOString() : now()->toISOString(),
                'formatted_time' => $visit->created_at ? $visit->created_at->format('d M Y, h:i A') : '—',
                'actor_name' => $docName,
                'badge_color' => 'teal',
            ]);
        }

        // 4. Invoices
        $invoices = Invoice::where('organization_id', $clinic->organization_id)
            ->where('patient_id', $patient->id)
            ->latest()
            ->limit($timelineSourceLimit)
            ->get();

        foreach ($invoices as $inv) {
            $events->push([
                'id' => 'inv-'.$inv->id,
                'type' => 'invoice_created',
                'category' => 'billing',
                'title' => 'Invoice Generated',
                'description' => "Invoice #{$inv->invoice_number} for ₹".number_format($inv->total_amount, 2)." ({$inv->status})",
                'timestamp' => $inv->created_at ? $inv->created_at->toISOString() : now()->toISOString(),
                'formatted_time' => $inv->created_at ? $inv->created_at->format('d M Y, h:i A') : '—',
                'actor_name' => 'Billing Desk',
                'badge_color' => 'amber',
            ]);
        }

        return $events->sortByDesc('timestamp')->values()->all();
    }

    private function formatEventDescription(PatientTimelineEvent $event): string
    {
        $context = $event->context ?? [];

        return match ($event->event_type) {
            'registered' => 'Patient registered at front desk (UHID assigned).',
            'appointment_checked_in' => 'Checked in at reception. Assigned Queue #'.($context['queue_position'] ?? '1'),
            'walk_in_registered' => 'Walk-in patient arrived and queued at position #'.($context['queue_position'] ?? '1'),
            'document_uploaded' => 'Attached document: '.($context['file_name'] ?? 'File'),
            'demographics_updated' => 'Updated: '.(! empty($context['updated_fields']) ? implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $context['updated_fields'])) : 'record details'),
            'doctor_transferred' => ($context['from_practitioner'] ?? null)
                ? "Reassigned from {$context['from_practitioner']} to {$context['to_practitioner']}".(! empty($context['reason']) ? " — {$context['reason']}" : '')
                : "Assigned to {$context['to_practitioner']}".(! empty($context['reason']) ? " — {$context['reason']}" : ''),
            default => 'Event logged.',
        };
    }

    /**
     * The same shape a real `LengthAwarePaginator` sends to the frontend
     * (`data`/`links`/`from`/`to`/`total`), for a tab the acting user
     * isn't permitted to see any of. Kept distinct from actually querying
     * with a `0 = 1` filter so a denied tab costs nothing at the database.
     */
    private function emptyPage(): array
    {
        return ['data' => [], 'links' => [], 'from' => null, 'to' => null, 'total' => 0];
    }
}
