<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientMedicalHistory;
use App\Models\PatientTimelineEvent;
use App\PatientStatus;
use App\Services\ClinicContext;
use App\Support\Clinical\DefaultClinicalCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Clinical staff managing a patient's safety alerts and medical-history
 * notes from Patient 360. Authorised by `PatientPolicy::writeClinicalData`
 * (clinical.edit, the member's branches, the assigned practitioner).
 *
 * - An alert records who added it; removing one resolves it (who, when),
 *   never deletes it. Only staff-recorded alerts (`manual`, `flag`) are
 *   managed here; the copies kept of allergies and conditions follow their
 *   source records.
 * - Notes record who changed them and when; the previous text goes to the
 *   timeline, so earlier versions stay readable.
 */
class PatientSafetyController extends Controller
{
    public function __construct(private ClinicContext $clinicContext) {}

    public function storeAlert(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorizeWrite($patient);
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'severity' => ['required', Rule::in(DefaultClinicalCatalogue::SEVERITIES)],
        ]);

        $alert = $patient->alerts()->create([
            'clinic_id' => $patient->clinic_id,
            'type' => 'manual',
            'description' => trim($validated['description']),
            'severity' => $validated['severity'],
            'created_by' => $request->user()->id,
        ]);
        $this->timeline($patient, $request, 'safety_alert_added', ['alert_id' => $alert->id, 'description' => $alert->description, 'severity' => $alert->severity]);

        return back()->with('success', 'Safety alert added.');
    }

    public function resolveAlert(Request $request, Patient $patient, int $alert): RedirectResponse
    {
        $this->authorizeWrite($patient);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        /** @var PatientAlert $record */
        $record = $patient->alerts()->whereIn('type', ['manual', 'flag'])->findOrFail($alert);
        $record->forceFill(['resolved_at' => now(), 'resolved_by' => $request->user()->id])->save();
        $this->timeline($patient, $request, 'safety_alert_resolved', ['alert_id' => $record->id, 'description' => $record->description, 'reason' => $validated['reason'] ?? null]);

        return back()->with('success', 'Safety alert removed. It stays in the patient\'s history.');
    }

    public function updateNotes(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorizeWrite($patient);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $notes = trim((string) ($validated['notes'] ?? '')) === '' ? null : trim($validated['notes']);

        DB::transaction(function () use ($patient, $notes, $request) {
            $clinic = $this->clinicContext->clinic();
            $history = PatientMedicalHistory::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->lockForUpdate()->first()
                ?? new PatientMedicalHistory(['clinic_id' => $patient->clinic_id, 'organization_id' => $clinic?->organization_id, 'patient_id' => $patient->id]);
            $previous = $history->notes;
            if ($previous === $notes) {
                return;
            }
            $history->forceFill(['notes' => $notes, 'notes_updated_by' => $request->user()->id, 'notes_updated_at' => now()])->save();
            $this->timeline($patient, $request, 'medical_notes_updated', ['previous' => $previous]);
        });

        return back()->with('success', 'Notes saved.');
    }

    private function authorizeWrite(Patient $patient): void
    {
        $clinic = $this->clinicContext->clinic();
        abort_unless($clinic && $patient->clinic_id === $clinic->id, 404);
        $this->authorize('writeClinicalData', $patient);
        abort_unless(in_array($patient->status?->value ?? PatientStatus::Active->value, PatientStatus::working(), true), 403, 'Merged records are read-only.');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function timeline(Patient $patient, Request $request, string $event, array $context): void
    {
        PatientTimelineEvent::create([
            'organization_id' => $this->clinicContext->clinic()?->organization_id,
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'actor_id' => $request->user()->id,
            'event_type' => $event,
            'context' => [...$context, 'by' => $request->user()->name],
        ]);
    }
}
