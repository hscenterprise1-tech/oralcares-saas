<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\ClinicContext;
use App\Services\Communication\ManualShare;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Patient 360 → Messages → send on WhatsApp (`ManualShare`). The member must
 * be able to open the patient; each kind then checks its own permission and
 * the record's state. Every send is security-audited with identifiers only.
 */
class PatientMessageShareController extends Controller
{
    public function __construct(private ClinicContext $clinicContext) {}

    public function store(Request $request, Patient $patient, ManualShare $share, SecurityAudit $audit): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic();
        abort_unless($clinic && $patient->clinic_id === $clinic->id, 404);
        $this->authorize('view', $patient);
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ManualShare::KINDS))],
            'id' => ['required', 'integer'],
        ]);

        try {
            $notification = $share->share($patient, $request->user(), $validated['kind'], (int) $validated['id']);
        } catch (DomainException $e) {
            return back()->withErrors(['share' => $e->getMessage()]);
        }

        $audit->record('patient.message_shared', $patient, ['kind' => $validated['kind'], 'record_id' => (int) $validated['id'], 'notification_id' => $notification->id]);

        return back()->with('success', 'Queued for WhatsApp. Its status shows in Messages.');
    }
}
