<?php

namespace App\Services;

class ClinicalDomainValidationService
{
    /**
     * Validate Patient status lifecycle transition.
     */
    public function validatePatientStatusTransition(string $old, string $new): bool
    {
        $allowed = [
            'active' => ['inactive', 'archived'],
            'inactive' => ['active', 'archived'],
            'archived' => ['active'],
        ];

        return in_array($new, $allowed[$old] ?? []);
    }

    /**
     * Validate Clinical Encounter/Visit lifecycle transition.
     */
    public function validateEncounterStatusTransition(string $old, string $new): bool
    {
        $allowed = [
            'checked_in' => ['waiting', 'cancelled'],
            'waiting' => ['called', 'cancelled'],
            'called' => ['in_consultation', 'cancelled'],
            'in_consultation' => ['completed', 'sent_to_xray', 'sent_to_lab', 'sent_to_billing', 'cancelled'],
            'sent_to_xray' => ['completed', 'in_consultation', 'cancelled'],
            'sent_to_lab' => ['completed', 'in_consultation', 'cancelled'],
            'sent_to_billing' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];

        return in_array($new, $allowed[$old] ?? []);
    }

    /**
     * Validate Treatment Record lifecycle transition.
     */
    public function validateTreatmentStatusTransition(string $old, string $new): bool
    {
        $allowed = [
            'proposed' => ['in_progress', 'completed', 'aborted'],
            'in_progress' => ['completed', 'aborted'],
            'completed' => [],
            'aborted' => [],
        ];

        return in_array($new, $allowed[$old] ?? []);
    }

    /**
     * Validate Prescription lifecycle transition.
     */
    public function validatePrescriptionStatusTransition(string $old, string $new): bool
    {
        $allowed = [
            'active' => ['expired', 'discontinued'],
            'expired' => [],
            'discontinued' => [],
        ];

        return in_array($new, $allowed[$old] ?? []);
    }

    /**
     * Validate Lab Order lifecycle transition.
     */
    public function validateLabOrderStatusTransition(string $old, string $new): bool
    {
        $allowed = [
            'ordered' => ['sent_to_lab', 'cancelled'],
            'sent_to_lab' => ['received', 'cancelled'],
            'received' => [],
            'cancelled' => [],
        ];

        return in_array($new, $allowed[$old] ?? []);
    }
}
