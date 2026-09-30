<?php

namespace App\Http\Controllers\Clinic;

use App\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Services\ClinicContext;
use App\Services\Patient\CaseNumberFormatter;
use App\Services\QueueStateMachine;
use App\Services\Reception\DoctorQueue;
use App\Services\Reception\DoctorRoster;
use App\Services\Reception\QueueBoard;
use App\Services\Reception\ReceptionCheckIn;
use App\Support\ClinicClock;
use App\VisitPriority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reception Live Queue. Every route sits behind `clinic_permission:queue.manage`
 * and every queue item is checked to belong to the active clinic and branch
 * (another clinic's item is a 404, not a 403). All status changes go through
 * `QueueStateMachine`.
 */
class ReceptionController extends Controller
{
    /**
     * Legacy `status` values the reception workflow already sent, mapped to
     * state-machine actions.
     */
    private const STATUS_ACTIONS = [
        'called' => 'call',
        'in_consultation' => 'start',
        'completed' => 'complete',
        'skipped' => 'skip',
        'waiting' => 'requeue',
        'cancelled' => 'cancel',
    ];

    public function __construct(
        protected ClinicContext $clinicContext,
        protected QueueStateMachine $machine,
    ) {}

    public function index(QueueBoard $board): Response
    {
        [$clinic, $branch] = $this->context();

        return Inertia::render('Clinic/Reception/Index', [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'board' => $board->forBranch($clinic, $branch),
        ]);
    }

    /**
     * Check-in modal search: matching patients with today's booking at this
     * branch, whether they are already queued, and who they would be sent to.
     */
    public function search(Request $request, DoctorRoster $roster, QueueBoard $board): JsonResponse
    {
        [$clinic, $branch] = $this->context();
        $query = trim((string) $request->input('q', ''));

        if (mb_strlen($query) < 3) {
            return response()->json(['patients' => []]);
        }

        $now = ClinicClock::now();
        $patients = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->matchingSearch($query)
            ->limit(8)
            ->get();

        $appointments = Appointment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->whereIn('patient_id', $patients->pluck('id'))
            ->whereDate('scheduled_at', $now->toDateString())
            ->whereIn('status', [AppointmentStatus::Scheduled, AppointmentStatus::Arrived])
            ->with('practitioner')
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy('patient_id');

        $queued = $board->todaysItems($clinic, $branch, $now)
            ->filter(fn (ClinicQueueItem $item) => in_array($item->status, QueueStateMachine::ACTIVE, true))
            ->keyBy(fn (ClinicQueueItem $item) => $item->patientVisit?->patient_id);

        $working = $roster->forBranch($clinic, $branch, $now)->pluck('employee.id')->all();

        return response()->json([
            'patients' => $patients->map(function (Patient $patient) use ($appointments, $queued, $working) {
                $appointment = $appointments->get($patient->id)?->first();
                $inQueue = $queued->get($patient->id);

                return [
                    'id' => $patient->id,
                    'name' => $patient->full_name,
                    'short_name' => $patient->short_name,
                    'phone' => $patient->phone,
                    'uhid' => $patient->uhid,
                    'appointment' => $appointment ? [
                        'id' => $appointment->id,
                        'time' => $appointment->scheduled_at->format(QueueBoard::DATETIME),
                        'doctor' => $appointment->practitioner ? ['id' => $appointment->practitioner->id, 'name' => $appointment->practitioner->name] : null,
                    ] : null,
                    'in_queue' => $inQueue ? [
                        'status' => $inQueue->status->value,
                        'case_short' => $inQueue->patientVisit?->caseNumber ? CaseNumberFormatter::shortLabel($inQueue->patientVisit->caseNumber->sequence) : null,
                    ] : null,
                    'assigned_doctor_id' => in_array($patient->current_practitioner_id, $working, true) ? $patient->current_practitioner_id : null,
                ];
            })->values(),
        ]);
    }

    public function checkIn(Request $request, ReceptionCheckIn $checkIn): RedirectResponse
    {
        [$clinic, $branch] = $this->context();

        $validated = $request->validate([
            'appointment_id' => ['nullable', 'integer'],
            'patient_id' => ['required_without:appointment_id', 'nullable', 'integer'],
            'practitioner_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::enum(VisitPriority::class)],
        ]);

        $appointment = isset($validated['appointment_id'])
            ? Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($validated['appointment_id'])
            : null;

        $patient = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->findOrFail($appointment?->patient_id ?? $validated['patient_id']);

        $doctor = isset($validated['practitioner_id'])
            ? ClinicEmployee::withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($validated['practitioner_id'])
            : null;

        $item = $checkIn->checkIn(
            $clinic,
            $branch,
            $patient,
            $appointment,
            $doctor,
            VisitPriority::tryFrom($validated['priority'] ?? '') ?? VisitPriority::Normal,
            $this->clinicContext->currentEmployee(),
        );

        $caseShort = $item->patientVisit?->caseNumber ? ' · '.CaseNumberFormatter::shortLabel($item->patientVisit->caseNumber->sequence) : '';
        $doctorName = $item->doctor?->name ?? 'the next free doctor';

        return back()->with('success', "{$patient->short_name}{$caseShort} checked in for {$doctorName}.");
    }

    /**
     * Kept for the older walk-in form: a walk-in is a check-in without an
     * appointment.
     */
    public function walkIn(Request $request, ReceptionCheckIn $checkIn): RedirectResponse
    {
        $request->request->remove('appointment_id');

        return $this->checkIn($request, $checkIn);
    }

    public function updateQueueStatus(ClinicQueueItem $item, Request $request, DoctorQueue $doctorQueue): RedirectResponse
    {
        [$clinic, $branch] = $this->context();
        $this->assertOwned($item, $clinic, $branch);

        $validated = $request->validate([
            'action' => ['nullable', Rule::in(['call', 'recall', 'start', 'complete', 'skip', 'requeue', 'cancel'])],
            'status' => ['required_without:action', 'nullable', Rule::in(array_keys(self::STATUS_ACTIONS))],
            'then_call_next' => ['boolean'],
        ]);

        $action = $validated['action'] ?? self::STATUS_ACTIONS[$validated['status']];
        $performer = $this->clinicContext->currentEmployee();

        if ($action === 'complete' && ($validated['then_call_next'] ?? false) && $performer) {
            $next = $doctorQueue->completeAndCallNext($clinic, $branch, $performer, $item);

            return back()->with('success', $next ? "Done. {$next->patientVisit?->patient?->short_name} has been called." : 'Done. No one else is waiting.');
        }

        $item = $this->machine->{$action}($item, $performer);

        return back()->with('success', $this->confirmation($action, $item));
    }

    public function updatePriority(ClinicQueueItem $item, Request $request): RedirectResponse
    {
        [$clinic, $branch] = $this->context();
        $this->assertOwned($item, $clinic, $branch);

        $validated = $request->validate(['priority' => ['required', Rule::enum(VisitPriority::class)]]);
        $item = $this->machine->setPriority($item, VisitPriority::from($validated['priority']), $this->clinicContext->currentEmployee());

        $name = $item->patientVisit?->patient?->short_name ?? 'Patient';

        return back()->with('success', $item->priority === VisitPriority::Emergency ? "{$name} marked as an emergency." : "{$name} is no longer marked as an emergency.");
    }

    public function callNext(DoctorQueue $doctorQueue): RedirectResponse
    {
        [$clinic, $branch] = $this->context();
        $doctor = $this->clinicContext->currentEmployee();
        abort_unless($doctor?->practitionerProfile, 403, 'Only a doctor can call their next patient.');

        $item = $doctorQueue->callNext($clinic, $branch, $doctor);

        return back()->with('success', "{$item->patientVisit?->patient?->short_name} has been called.");
    }

    /**
     * @return array{0: Clinic, 1: Branch}
     */
    private function context(): array
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403, 'No active clinic selected.');
        // Only a branch this member may open: never the clinic's main branch
        // on behalf of someone with no branch access.
        $branch = $this->clinicContext->requireBranch();

        return [$clinic, $branch];
    }

    private function assertOwned(ClinicQueueItem $item, Clinic $clinic, Branch $branch): void
    {
        $queue = $item->queue()->withoutGlobalScopes()->first();

        abort_unless($queue && $queue->clinic_id === $clinic->id && $queue->branch_id === $branch->id, 404);
    }

    private function confirmation(string $action, ClinicQueueItem $item): string
    {
        $name = $item->patientVisit?->patient?->short_name ?? 'Patient';

        return match ($action) {
            'call' => "{$name} has been called.",
            'recall' => "{$name} has been called again.",
            'start' => "{$name} is in consultation.",
            'complete' => "{$name} is done.",
            'skip' => "{$name} marked as not present.",
            'requeue' => "{$name} is back in the queue.",
            'cancel' => "{$name} has left the queue.",
            default => 'Queue updated.',
        };
    }
}
