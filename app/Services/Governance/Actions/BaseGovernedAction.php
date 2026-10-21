<?php

namespace App\Services\Governance\Actions;

use App\Models\Clinic;
use App\Models\DeletionRequest;
use App\Models\PatientTimelineEvent;
use App\Services\Governance\GovernedAction;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared plumbing for governed actions: the fingerprint format and the
 * patient-timeline entry every executed action leaves.
 */
abstract class BaseGovernedAction implements GovernedAction
{
    /**
     * Most actions need nothing but their target.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function payload(Model $target, array $input, int $clinicId): array
    {
        if ($input !== []) {
            throw new DomainException('This action takes no further details.');
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    protected function hash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function timeline(DeletionRequest $request, string $event, array $context = []): void
    {
        if (! $request->patient_id) {
            return;
        }

        PatientTimelineEvent::create([
            'organization_id' => Clinic::query()->whereKey($request->clinic_id)->value('organization_id'),
            'clinic_id' => $request->clinic_id,
            'patient_id' => $request->patient_id,
            'actor_id' => auth()->id(),
            'event_type' => $event,
            'context' => ['deletion_request_id' => $request->id, 'label' => $request->target_label, 'reason' => $request->reason, ...$context],
        ]);
    }
}
