<?php

namespace App\Services;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\Models\QueueEntry;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FrontDeskService
{
    /**
     * Check in an existing appointment and add to queue.
     */
    public function checkInAppointment(Appointment $appointment): QueueEntry
    {
        if ($appointment->status !== AppointmentStatus::Scheduled) {
            throw new Exception('Only scheduled appointments can be checked in.');
        }

        return DB::transaction(function () use ($appointment) {
            $appointment->update([
                'status' => 'checked_in',
            ]);

            return QueueEntry::create([
                'organization_id' => $appointment->organization_id,
                'branch_id' => $appointment->branch_id,
                'patient_id' => $appointment->patient_id,
                'provider_id' => $appointment->provider_id,
                'appointment_id' => $appointment->id,
                'status' => 'waiting',
                'check_in_time' => now(),
            ]);
        });
    }

    /**
     * Add a walk-in patient directly to the queue.
     */
    public function addWalkIn(Patient $patient, ?User $provider = null, ?int $branchId = null): QueueEntry
    {
        return QueueEntry::create([
            'organization_id' => $patient->organization_id,
            'branch_id' => $branchId,
            'patient_id' => $patient->id,
            'provider_id' => $provider?->id,
            'status' => 'waiting',
            'check_in_time' => now(),
        ]);
    }

    /**
     * Start a consultation from the queue.
     */
    public function startConsultation(QueueEntry $queue): ClinicalEncounter
    {
        if ($queue->status !== 'waiting') {
            throw new Exception('Only waiting queue entries can start consultation.');
        }

        return DB::transaction(function () use ($queue) {
            $queue->update([
                'status' => 'in_consultation',
                'called_time' => now(),
            ]);

            $patient = $queue->patient;
            $clinic = $patient?->clinic;
            $branchId = $queue->branch_id ?? $clinic?->branches()->first()?->id ?? 1;
            $clinicId = $clinic?->id ?? $patient?->clinic_id ?? 1;

            return ClinicalEncounter::create([
                'organization_id' => $queue->organization_id ?? $clinic?->organization_id,
                'clinic_id' => $clinicId,
                'branch_id' => $branchId,
                'patient_id' => $queue->patient_id,
                'doctor_id' => $queue->provider_id, // Default to assigned provider, if any
                'encounter_number' => 'ENC-'.date('Ymd').'-'.strtoupper(Str::random(4)),
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
        });
    }
}
