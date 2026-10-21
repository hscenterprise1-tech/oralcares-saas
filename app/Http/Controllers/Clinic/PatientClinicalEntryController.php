<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\PatientStatus;
use App\Services\ClinicContext;
use App\Services\Patient\PatientClinicalEntries;
use App\Support\Clinical\DefaultClinicalCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Clinical staff changing a patient's recorded conditions, allergies or
 * medications after registration. Authorised by `PatientPolicy::writeClinicalData`
 * (clinical.edit, the member's branches, the assigned practitioner), so a
 * role without clinical permission — Reception — is refused with 403 even
 * though it recorded the first entries at registration.
 *
 * The list sent is the full active list for that kind: what is left out is
 * resolved (kept as history), never deleted.
 */
class PatientClinicalEntryController extends Controller
{
    public function __construct(
        private ClinicContext $clinicContext,
        private PatientClinicalEntries $entries,
    ) {}

    public function update(Request $request, Patient $patient, string $kind): JsonResponse
    {
        $clinic = $this->clinicContext->clinic();
        abort_unless($clinic && $patient->clinic_id === $clinic->id, 404);
        abort_unless(in_array($kind, DefaultClinicalCatalogue::ENTRY_KINDS, true), 404);
        $this->authorize('writeClinicalData', $patient);
        abort_unless(in_array($patient->status?->value ?? PatientStatus::Active->value, PatientStatus::working(), true), 403, 'Archived or merged records are read-only.');

        $validated = $request->validate([
            'entries' => ['present', 'array', 'max:30'],
            'entries.*.key' => ['nullable', 'string', 'max:64'],
            'entries.*.label' => ['nullable', 'string', 'max:160'],
            'entries.*.severity' => ['nullable', 'in:'.implode(',', DefaultClinicalCatalogue::SEVERITIES)],
            'entries.*.details' => ['nullable', 'array'],
            'entries.*.details.dose' => ['nullable', 'string', 'max:160'],
            'entries.*.details.frequency' => ['nullable', 'string', 'max:160'],
            'entries.*.details.notes' => ['nullable', 'string', 'max:160'],
            'entries.*.details.reaction' => ['nullable', 'string', 'max:160'],
        ]);

        $specialties = DB::table('patient_specialties as ps')->join('clinic_specialties as cs', 'cs.id', '=', 'ps.clinic_specialty_id')
            ->where('ps.clinic_id', $clinic->id)->where('ps.patient_id', $patient->id)->where('ps.status', 'active')
            ->pluck('cs.specialty_key')->all();
        $entries = collect($validated['entries'])->map(fn (array $entry) => [
            'key' => $entry['key'] ?? null,
            'label' => $entry['label'] ?? null,
            'severity' => $entry['severity'] ?? null,
            'details' => isset($entry['details']) ? collect($entry['details'])->only(['dose', 'frequency', 'notes', 'reaction'])->filter()->all() ?: null : null,
        ])->all();

        $this->entries->adoptLegacy($patient, $kind);
        $prepared = $this->entries->prepare($clinic->id, $kind, $entries, 'entries', $specialties);
        if ($this->entries->replace($patient, $kind, $prepared, 'clinical', $request->user())) {
            PatientTimelineEvent::create([
                'organization_id' => $clinic->organization_id,
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'actor_id' => $request->user()->id,
                'event_type' => 'clinical_entries_updated',
                'context' => ['kind' => $kind, 'updated_by' => $request->user()->name],
            ]);
        }

        return response()->json(['entries' => $this->entries->labelsFor($patient, $kind)]);
    }
}
