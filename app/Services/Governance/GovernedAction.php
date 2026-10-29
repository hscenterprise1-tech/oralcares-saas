<?php

namespace App\Services\Governance;

use App\ClinicPermission;
use App\Models\DeletionRequest;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * One destructive action under owner governance: what it is called, who may
 * ask for it, how its record is found (always inside one clinic), and what
 * carrying it out does. No action destroys clinical history: records are
 * voided, removed (soft) or merged, and stay readable to authorised roles.
 */
interface GovernedAction
{
    /** Stable key, stored on the request: `document.remove`. */
    public function key(): string;

    /** What the button says: "Remove document". */
    public function label(): string;

    /** The record kind in the request: `document`. */
    public function targetType(): string;

    /** Who may ask for it (the owner may also do it directly). */
    public function requestPermission(): ClinicPermission;

    /** The record, only if it belongs to this clinic. */
    public function find(int $clinicId, int $targetId): ?Model;

    /**
     * @return array{label: string, patient_id: ?int, branch_id: ?int}
     */
    public function describe(Model $target): array;

    /** Changes when the record changes in a way that matters to the decision. */
    public function fingerprint(Model $target): string;

    /**
     * @throws DomainException when the record cannot be acted on now
     */
    public function assertActionable(Model $target): void;

    /**
     * Validate what the action needs beyond its target (a merge names the
     * patient to keep). Stored on the request; empty for most actions.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    public function payload(Model $target, array $input, int $clinicId): array;

    /** Carry it out. Runs inside the approval transaction. */
    public function execute(Model $target, DeletionRequest $request, User $actor): void;
}
