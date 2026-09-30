<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\VisitRegistrationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientVisit>
 */
class PatientVisitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'branch_id' => Branch::factory(),
            'patient_id' => Patient::factory(),
            'department_id' => null,
            'doctor_id' => null,
            'registration_type' => VisitRegistrationType::New->value,
            'visit_date' => now()->toDateString(),
            'queue_status' => 'not_queued',
            'visit_status' => 'registered',
            'registration_fee_collected' => false,
            'notes' => null,
        ];
    }

    /**
     * Configure as a repeat visit.
     */
    public function repeat(): static
    {
        return $this->state(['registration_type' => VisitRegistrationType::Repeat->value]);
    }
}
