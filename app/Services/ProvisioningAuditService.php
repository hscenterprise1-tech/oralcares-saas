<?php

namespace App\Services;

use App\Models\ProvisioningAudit;

class ProvisioningAuditService
{
    public function startAudit(string $profile): ProvisioningAudit
    {
        return ProvisioningAudit::create([
            'profile' => $profile,
            'status' => 'started',
            'version' => '1.0',
        ]);
    }

    public function completeAudit(ProvisioningAudit $audit, int $clinicId, array $entities, int $durationMs): void
    {
        $audit->update([
            'clinic_id' => $clinicId,
            'status' => 'completed',
            'created_entities' => $entities,
            'duration_ms' => $durationMs,
        ]);
    }

    public function failAudit(ProvisioningAudit $audit, string $errorMessage): void
    {
        $audit->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }
}
