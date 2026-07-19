<?php

namespace App\Services;

use App\Models\ApiAuditLog;
use App\Models\ClinicEmployee;

class ApiAuditService
{
    /**
     * Log an API request.
     */
    public function logRequest(
        ?ClinicEmployee $employee,
        string $endpoint,
        string $method,
        ?string $ipAddress = null,
        int $statusCode = 200,
        ?array $payload = null
    ): ApiAuditLog {
        // Scrub sensitive medical/personal identifiers from logging
        $scrubbed = null;
        if ($payload) {
            $scrubbed = $this->scrubPayload($payload);
        }

        return ApiAuditLog::create([
            'clinic_employee_id' => $employee?->id,
            'endpoint' => $endpoint,
            'method' => $method,
            'ip_address' => $ipAddress,
            'status_code' => $statusCode,
            'payload_summary' => $scrubbed,
        ]);
    }

    /**
     * Recursively remove sensitive parameters from API request payloads before writing audits.
     */
    protected function scrubPayload(array $payload): array
    {
        $sensitiveKeys = [
            'patient_id', 'notes', 'description', 'reason', 'gender',
            'date_of_birth', 'dob', 'phone', 'email', 'diagnosis_notes',
            'prescription_number', 'billing_number', 'drug_name',
        ];

        foreach ($payload as $key => $val) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $payload[$key] = '[SCRUBBED_SENSITIVE_DATA]';
            } elseif (is_array($val)) {
                $payload[$key] = $this->scrubPayload($val);
            }
        }

        return $payload;
    }
}
