<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ClinicalEncounter;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\QueueEntry;
use App\Services\PatientRegistrationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PatientManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = Patient::query()->with('profile');

        if ($request->has('search')) {
            $query->searchByName($request->search);
        }

        return Inertia::render('Clinical/Patients/Index', [
            'patients' => $query->paginate(15),
        ]);
    }

    public function create()
    {
        return Inertia::render('Clinical/Patients/Create');
    }

    public function store(Request $request, PatientRegistrationService $registrationService)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string',
        ]);

        $patient = $registrationService->registerPatient(
            auth()->user()->organization_id,
            $validated
        );

        return redirect()->route('clinical.patients.show', $patient);
    }

    public function show(Patient $patient)
    {
        $patient->load([
            'profile',
            'alerts',
            'documents',
        ]);

        $organizationId = auth()->user()->organization_id;

        // Financial queries
        $invoices = Invoice::where('patient_id', $patient->id)
            ->where('organization_id', $organizationId)
            ->latest()
            ->get();

        $totalInvoiced = $invoices->sum('total_amount');
        $totalOutstanding = $invoices->where('balance_due', '>', 0)->sum('balance_due');
        $totalPaid = $invoices->sum('amount_paid');
        $pendingInvoicesCount = $invoices->where('balance_due', '>', 0)->count();

        // Last Payment logic (Requires Payment model or inference)
        // Here we just grab the latest invoice with some amount paid as fallback if Payment doesn't exist yet,
        // but ideally we query payments table.
        $lastPayment = Payment::where('patient_id', $patient->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->latest()
            ->first() ?? null;

        // Fetch Next Appointment
        $nextAppointment = Appointment::where('patient_id', $patient->id)
            ->where('organization_id', $organizationId)
            ->where('scheduled_at', '>', now())
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at')
            ->first();

        // Queue
        $currentQueue = QueueEntry::where('patient_id', $patient->id)
            ->where('organization_id', $organizationId)
            ->whereDate('created_at', today())
            ->whereIn('status', ['waiting', 'in_consultation'])
            ->first();

        // Active Encounter
        $activeEncounter = ClinicalEncounter::where('patient_id', $patient->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'in_progress')
            ->first();

        // Last Visit
        $lastVisit = ClinicalEncounter::where('patient_id', $patient->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->latest('ended_at')
            ->first();

        // Computed Status Engine
        $computedStatus = 'Checked Out';
        if ($activeEncounter) {
            $computedStatus = 'In Consultation';
        } elseif ($currentQueue && $currentQueue->status === 'waiting') {
            $computedStatus = 'Waiting';
        } elseif ($pendingInvoicesCount > 0) {
            $computedStatus = 'Awaiting Payment';
        }

        // Timeline Preview (Load 3 most recent encounters with details)
        $recentEncounters = ClinicalEncounter::where('patient_id', $patient->id)
            ->where('organization_id', $organizationId)
            ->with(['clinicalNotes', 'diagnoses', 'prescriptions', 'provider'])
            ->latest()
            ->limit(3)
            ->get();

        // Patient Snapshot counts
        $snapshot = [
            'allergiesCount' => $patient->alerts->where('type', 'allergy')->count(),
            'activeMedsCount' => Prescription::where('patient_id', $patient->id)
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->count(),
        ];

        return Inertia::render('Clinical/Patients/Show', [
            'patient' => $patient,
            'computedStatus' => $computedStatus,
            'financials' => [
                'invoices' => $invoices->take(5),
                'totalInvoiced' => $totalInvoiced,
                'totalOutstanding' => $totalOutstanding,
                'totalPaid' => $totalPaid,
                'pendingInvoicesCount' => $pendingInvoicesCount,
                'lastInvoice' => $invoices->first(),
                'lastPayment' => $lastPayment,
            ],
            'snapshot' => $snapshot,
            'nextAppointment' => $nextAppointment,
            'currentQueue' => $currentQueue,
            'activeEncounter' => $activeEncounter,
            'lastVisit' => $lastVisit,
            'recentEncounters' => $recentEncounters,
        ]);
    }

    // Lazy Load Endpoint for Full Timeline using Cursor Pagination
    public function timeline(Patient $patient)
    {
        $organizationId = auth()->user()->organization_id;

        $encounters = ClinicalEncounter::where('patient_id', $patient->id)
            ->where('organization_id', $organizationId)
            ->with(['clinicalNotes', 'diagnoses', 'prescriptions', 'provider'])
            ->latest()
            ->cursorPaginate(5);

        return response()->json($encounters);
    }
}
