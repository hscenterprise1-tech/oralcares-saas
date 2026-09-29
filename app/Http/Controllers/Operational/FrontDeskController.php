<?php

namespace App\Http\Controllers\Operational;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\QueueEntry;
use App\Services\FrontDeskService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FrontDeskController extends Controller
{
    public function index()
    {
        $organizationId = auth()->user()->organization_id;

        $appointments = Appointment::where('organization_id', $organizationId)
            ->whereDate('scheduled_at', today())
            ->where('status', 'scheduled')
            ->with(['patient', 'provider'])
            ->orderBy('scheduled_at')
            ->get();

        $queue = QueueEntry::where('organization_id', $organizationId)
            ->whereIn('status', ['waiting', 'in_consultation'])
            ->with(['patient', 'provider'])
            ->orderByRaw("FIELD(status, 'in_consultation', 'waiting')")
            ->orderBy('priority', 'desc')
            ->orderBy('check_in_time', 'asc')
            ->get();

        // Fetch unpaid invoices for queue patients
        $queuePatientIds = $queue->pluck('patient_id')->unique();
        $unpaidInvoices = Invoice::whereIn('patient_id', $queuePatientIds)
            ->where('status', 'issued')
            ->where('balance_due', '>', 0)
            ->get()
            ->groupBy('patient_id');

        // Reception KPIs
        $registrationsToday = Patient::where('organization_id', $organizationId)
            ->whereDate('created_at', today())
            ->count();

        $revenueToday = Invoice::where('organization_id', $organizationId)
            ->whereDate('created_at', today())
            ->whereIn('status', ['paid', 'partially_paid'])
            ->sum('amount_paid') ?? 0;

        $pendingPayments = Invoice::where('organization_id', $organizationId)
            ->whereDate('created_at', today())
            ->where('balance_due', '>', 0)
            ->sum('balance_due') ?? 0;

        $waitingPatients = $queue->where('status', 'waiting')->count();
        $inConsultation = $queue->where('status', 'in_consultation')->count();
        $completedVisits = QueueEntry::where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->whereDate('created_at', today())
            ->count();

        $doctorsAvailable = Provider::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->count(); // In a real system, query schedule or shifts. We default to active providers.

        // Calculate average waiting time (in minutes) for today's completed/in-consultation visits
        $avgWaitingTime = QueueEntry::where('organization_id', $organizationId)
            ->whereIn('status', ['in_consultation', 'completed'])
            ->whereDate('created_at', today())
            ->whereNotNull('consultation_start_time')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, check_in_time, consultation_start_time)) as avg_wait')
            ->value('avg_wait') ?? 0;

        $kpis = [
            'registrationsToday' => $registrationsToday,
            'revenueToday' => (float) $revenueToday,
            'waitingPatients' => $waitingPatients,
            'doctorsAvailable' => $doctorsAvailable,
            'avgWaitingTime' => round((float) $avgWaitingTime),
            'inConsultation' => $inConsultation,
            'completedVisits' => $completedVisits,
            'pendingPayments' => (float) $pendingPayments,
        ];

        return Inertia::render('Operational/FrontDesk/Index', [
            'appointments' => $appointments,
            'queue' => $queue,
            'unpaidInvoices' => $unpaidInvoices,
            'kpis' => $kpis,
        ]);
    }

    public function checkIn(Appointment $appointment, FrontDeskService $service)
    {
        abort_if($appointment->organization_id !== auth()->user()->organization_id, 403);

        $service->checkInAppointment($appointment);

        return redirect()->back()->with('success', 'Patient checked in.');
    }

    public function walkIn(Request $request, FrontDeskService $service)
    {
        $validated = $request->validate([
            'patient_id' => 'required|exists:patients,id',
        ]);

        $patient = Patient::where('organization_id', auth()->user()->organization_id)
            ->findOrFail($validated['patient_id']);

        $service->addWalkIn($patient);

        return redirect()->back()->with('success', 'Walk-in added to queue.');
    }

    public function startConsultation(QueueEntry $queue, FrontDeskService $service)
    {
        abort_if($queue->organization_id !== auth()->user()->organization_id, 403);

        $encounter = $service->startConsultation($queue);

        // Redirect the doctor straight into the new encounter workspace
        return redirect()->route('clinical.encounters.workspace', $encounter->id);
    }

    public function rapidRegister(Request $request, FrontDeskService $service)
    {
        $validated = $request->validate([
            'mobile' => 'required|string|max:20',
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'gender' => 'nullable|string|in:male,female,other',
            'date_of_birth' => 'nullable|date',
        ]);

        $organizationId = auth()->user()->organization_id;

        // Create the patient
        $patient = Patient::create([
            'organization_id' => $organizationId,
            'mobile' => $validated['mobile'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'gender' => $validated['gender'],
            'date_of_birth' => $validated['date_of_birth'],
            'uhid' => 'TMP-'.strtoupper(uniqid()), // Real system should use an observer/generator
        ]);

        // Add to queue
        $service->addWalkIn($patient);

        return redirect()->back()->with('success', 'Patient registered and added to queue.');
    }
}
