<?php

namespace App\Services\Governance;

use App\Models\DeletionRequest;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Governance\Actions\ArchivePatient;
use App\Services\Governance\Actions\MergePatient;
use App\Services\Governance\Actions\RemoveDocument;
use App\Services\Governance\Actions\VoidBill;
use App\Services\Governance\Actions\VoidEncounter;
use App\Services\Governance\Actions\VoidEndodonticRecord;
use App\Services\Governance\Actions\VoidImplantRecord;
use App\Services\Governance\Actions\VoidOrthodonticRecord;
use App\Services\Governance\Actions\VoidToothEntry;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Patient\Patient360\Patient360Access;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Destructive-action governance for the clinic.
 *
 * Nobody but the clinic owner carries out a destructive action. Everyone
 * else — administrators included — asks, with a reason, and the owner
 * approves, rejects or asks for clarification, in Owner Mode. An owner may
 * also act directly (in Owner Mode); that still leaves an executed request,
 * so every destructive action has the same trail.
 *
 * Every decision is checked here, never taken from the browser: the
 * approver is this clinic's owner in Owner Mode, the request is this
 * clinic's and still open, not expired, and its record has not changed
 * since it was asked for. Approval runs under a row lock and is idempotent:
 * a second approval of an executed request does nothing.
 */
class DeletionGovernance
{
    /** @var array<string, GovernedAction>|null */
    private ?array $actions = null;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected OwnerMode $ownerMode,
        protected Patient360Access $access,
        protected SecurityAudit $audit,
    ) {}

    /**
     * @return array<string, GovernedAction>
     */
    public function actions(): array
    {
        return $this->actions ??= collect([
            ArchivePatient::class, RemoveDocument::class, VoidEncounter::class, VoidToothEntry::class, VoidBill::class,
            VoidEndodonticRecord::class, VoidImplantRecord::class, VoidOrthodonticRecord::class, MergePatient::class,
        ])->map(fn (string $class) => app($class))->keyBy(fn (GovernedAction $action) => $action->key())->all();
    }

    public function action(string $key): GovernedAction
    {
        return $this->actions()[$key] ?? throw new InvalidArgumentException('Unknown action.');
    }

    /**
     * How this user may take an action: `direct` (owner), `request`, or null.
     */
    public function modeFor(User $user, string $actionKey): ?string
    {
        if ($this->ownerMode->isOwner()) {
            return 'direct';
        }

        return $user->hasClinicPermission($this->action($actionKey)->requestPermission()) ? 'request' : null;
    }

    /**
     * Ask the owner to carry out an action on a record.
     *
     * @throws AuthorizationException|DomainException
     */
    public function request(User $user, string $actionKey, int $targetId, string $reason, ?string $note = null, array $payload = []): DeletionRequest
    {
        $action = $this->action($actionKey);
        $clinic = $this->clinicContext->clinic() ?? throw new AuthorizationException('No active clinic.');
        if (! $user->hasClinicPermission($action->requestPermission())) {
            throw new AuthorizationException('You cannot ask for this.');
        }
        $reason = $this->reason($reason);

        $target = $action->find($clinic->id, $targetId) ?? throw new DomainException('That record was not found.');
        $about = $action->describe($target);
        $this->assertBranch($about['branch_id']);
        $action->assertActionable($target);
        $payload = $action->payload($target, $payload, $clinic->id);

        try {
            $request = DB::transaction(fn () => DeletionRequest::query()->create([
                'organization_id' => $clinic->organization_id,
                'clinic_id' => $clinic->id,
                'branch_id' => $about['branch_id'],
                'patient_id' => $about['patient_id'],
                'requester_user_id' => $user->id,
                'action_type' => $action->key(),
                'target_type' => $action->targetType(),
                'target_id' => $target->getKey(),
                'target_label' => mb_substr($about['label'], 0, 255),
                'target_fingerprint' => $action->fingerprint($target),
                'reason' => $reason,
                'note' => $this->text($note),
                'payload' => $payload ?: null,
                'status' => DeletionRequest::PENDING,
                'open_key' => $this->openKey($clinic->id, $action->key(), (int) $target->getKey()),
                'thread' => [$this->entry($user, 'requested', $reason)],
                'expires_at' => now()->addDays(DeletionRequest::LIFETIME_DAYS),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('This is already waiting for the owner\'s decision.');
        }

        $this->record('deletion.requested', $request, ['reason' => $reason]);

        return $request;
    }

    /**
     * The owner acting directly (Owner Mode). Recorded as an executed request.
     *
     * @throws AuthorizationException|DomainException
     */
    public function executeDirectly(User $owner, string $actionKey, int $targetId, string $reason, array $payload = []): DeletionRequest
    {
        $this->assertOwnerDecision();
        $action = $this->action($actionKey);
        $clinic = $this->clinicContext->clinic();
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($owner, $action, $clinic, $targetId, $reason, $payload) {
            $target = $this->lockTarget($action, $clinic->id, $targetId) ?? throw new DomainException('That record was not found.');
            $action->assertActionable($target);
            $about = $action->describe($target);
            $payload = $action->payload($target, $payload, $clinic->id);

            try {
                $request = DeletionRequest::query()->create([
                    'organization_id' => $clinic->organization_id,
                    'clinic_id' => $clinic->id,
                    'branch_id' => $about['branch_id'],
                    'patient_id' => $about['patient_id'],
                    'requester_user_id' => $owner->id,
                    'action_type' => $action->key(),
                    'target_type' => $action->targetType(),
                    'target_id' => $target->getKey(),
                    'target_label' => mb_substr($about['label'], 0, 255),
                    'target_fingerprint' => $action->fingerprint($target),
                    'reason' => $reason,
                    'payload' => $payload ?: null,
                    'status' => DeletionRequest::EXECUTED,
                    'direct' => true,
                    'approver_user_id' => $owner->id,
                    'approved_at' => now(),
                    'executed_at' => now(),
                    'thread' => [$this->entry($owner, 'executed', $reason)],
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('This record changed while you were acting on it. Reload and try again.');
            }

            // An open request for the same record is answered by this action.
            DeletionRequest::query()->where('open_key', $this->openKey($clinic->id, $action->key(), (int) $target->getKey()))
                ->update(['status' => DeletionRequest::EXECUTED, 'open_key' => null, 'approver_user_id' => $owner->id, 'approved_at' => now(), 'executed_at' => now(), 'approval_note' => "Carried out directly by the owner (request #{$request->id})."]);

            $action->execute($target, $request, $owner);
            $this->record('deletion.executed', $request, ['direct' => true, 'reason' => $reason]);

            return $request;
        });
    }

    /**
     * @throws AuthorizationException|DomainException
     */
    public function approve(User $owner, DeletionRequest $request, ?string $note = null): DeletionRequest
    {
        $this->assertOwnerDecision();

        return DB::transaction(function () use ($owner, $request, $note) {
            $locked = $this->lockRequest($request);
            if ($locked->status === DeletionRequest::EXECUTED) {
                return $locked; // already approved and carried out: nothing to do twice
            }
            $this->assertDecidable($locked);

            $action = $this->action($locked->action_type);
            $target = $this->lockTarget($action, $locked->clinic_id, (int) $locked->target_id);
            if (! $target) {
                throw new DomainException('The record no longer exists. Reject this request.');
            }
            if (! hash_equals($locked->target_fingerprint, $action->fingerprint($target))) {
                throw new DomainException('The record has changed since this was requested. Reject it, and ask for a fresh request if it is still needed.');
            }
            $action->assertActionable($target);

            $thread = $locked->thread ?? [];
            $thread[] = $this->entry($owner, 'approved', $note);
            $locked->update([
                'status' => DeletionRequest::EXECUTED,
                'open_key' => null,
                'approver_user_id' => $owner->id,
                'approval_note' => $this->text($note),
                'approved_at' => now(),
                'executed_at' => now(),
                'thread' => $thread,
            ]);

            $action->execute($target, $locked, $owner);
            $this->record('deletion.approved', $locked, ['note' => $this->text($note)]);
            $this->record('deletion.executed', $locked, ['direct' => false]);

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException|DomainException
     */
    public function reject(User $owner, DeletionRequest $request, string $reason): DeletionRequest
    {
        $this->assertOwnerDecision();
        $reason = $this->reason($reason, 'Say why the request is rejected.');

        return DB::transaction(function () use ($owner, $request, $reason) {
            $locked = $this->lockRequest($request);
            if ($locked->status === DeletionRequest::REJECTED) {
                return $locked;
            }
            $this->assertOpen($locked);

            $locked->update([
                'status' => DeletionRequest::REJECTED,
                'open_key' => null,
                'approver_user_id' => $owner->id,
                'rejected_reason' => $reason,
                'rejected_at' => now(),
                'thread' => [...($locked->thread ?? []), $this->entry($owner, 'rejected', $reason)],
            ]);
            $this->record('deletion.rejected', $locked, ['reason' => $reason]);

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException|DomainException
     */
    public function askClarification(User $owner, DeletionRequest $request, string $question): DeletionRequest
    {
        $this->assertOwnerDecision();
        $question = $this->reason($question, 'Say what you need to know.');

        return DB::transaction(function () use ($owner, $request, $question) {
            $locked = $this->lockRequest($request);
            $this->assertDecidable($locked);

            $locked->update([
                'status' => DeletionRequest::CLARIFICATION,
                'approver_user_id' => $owner->id,
                'thread' => [...($locked->thread ?? []), $this->entry($owner, 'clarification_requested', $question)],
            ]);
            $this->record('deletion.clarification_requested', $locked, ['question' => $question]);

            return $locked;
        });
    }

    /**
     * The requester answering the owner's question; the request goes back to the owner.
     *
     * @throws AuthorizationException|DomainException
     */
    public function respond(User $user, DeletionRequest $request, string $answer): DeletionRequest
    {
        $answer = $this->reason($answer, 'Write your answer.');

        return DB::transaction(function () use ($user, $request, $answer) {
            $locked = $this->lockRequest($request);
            if ($locked->requester_user_id !== $user->id) {
                throw new AuthorizationException('Only the person who asked can answer.');
            }
            if ($locked->status !== DeletionRequest::CLARIFICATION) {
                throw new DomainException('The owner has not asked anything about this request.');
            }

            $locked->update([
                'status' => DeletionRequest::PENDING,
                'thread' => [...($locked->thread ?? []), $this->entry($user, 'responded', $answer)],
            ]);
            $this->record('deletion.clarified', $locked, ['answer' => $answer]);

            return $locked;
        });
    }

    /**
     * The requester withdrawing their own request.
     *
     * @throws AuthorizationException|DomainException
     */
    public function cancel(User $user, DeletionRequest $request): DeletionRequest
    {
        return DB::transaction(function () use ($user, $request) {
            $locked = $this->lockRequest($request);
            if ($locked->requester_user_id !== $user->id) {
                throw new AuthorizationException('Only the person who asked can withdraw it.');
            }
            if ($locked->status === DeletionRequest::CANCELLED) {
                return $locked;
            }
            $this->assertOpen($locked);

            $locked->update([
                'status' => DeletionRequest::CANCELLED,
                'open_key' => null,
                'cancelled_at' => now(),
                'thread' => [...($locked->thread ?? []), $this->entry($user, 'cancelled', null)],
            ]);
            $this->record('deletion.cancelled', $locked);

            return $locked;
        });
    }

    /**
     * Close undecided requests past their lifetime (scheduled daily).
     */
    public function expireStale(): int
    {
        $count = 0;
        DeletionRequest::query()->withoutGlobalScopes()
            ->whereIn('status', [DeletionRequest::PENDING, DeletionRequest::CLARIFICATION])
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($requests) use (&$count) {
                foreach ($requests as $request) {
                    $count += DeletionRequest::query()->withoutGlobalScopes()->whereKey($request->id)
                        ->whereIn('status', [DeletionRequest::PENDING, DeletionRequest::CLARIFICATION])
                        ->update(['status' => DeletionRequest::EXPIRED, 'open_key' => null]);
                }
            });

        return $count;
    }

    private function assertOwnerDecision(): void
    {
        if (! $this->ownerMode->authorized()) {
            throw new AuthorizationException($this->ownerMode->isOwner()
                ? 'Enter Owner Mode to decide destructive actions.'
                : 'Only the clinic owner decides destructive actions.');
        }
    }

    private function assertDecidable(DeletionRequest $request): void
    {
        $this->assertOpen($request);
        if ($request->isExpired()) {
            $request->update(['status' => DeletionRequest::EXPIRED, 'open_key' => null]);
            throw new DomainException('This request has expired. Ask for a fresh one if it is still needed.');
        }
    }

    private function assertOpen(DeletionRequest $request): void
    {
        if (! $request->isOpen()) {
            throw new DomainException('This request has already been decided.');
        }
    }

    /**
     * A record held at a branch is only asked about by someone who may open that branch.
     */
    private function assertBranch(?int $branchId): void
    {
        $allowed = $this->access->branchIds();
        if ($branchId !== null && $allowed !== null && ! in_array($branchId, $allowed, true)) {
            throw new AuthorizationException('That record is at a branch you cannot open.');
        }
    }

    private function lockRequest(DeletionRequest $request): DeletionRequest
    {
        $clinicId = $this->clinicContext->clinic()?->id;

        /** @var DeletionRequest|null $locked */
        $locked = DeletionRequest::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereKey($request->id)->lockForUpdate()->first();

        return $locked ?? throw new DomainException('That request was not found.');
    }

    private function lockTarget(GovernedAction $action, int $clinicId, int $targetId): ?Model
    {
        $found = $action->find($clinicId, $targetId);

        return $found ? $found->newQueryWithoutScopes()->whereKey($found->getKey())->lockForUpdate()->first() : null;
    }

    private function openKey(int $clinicId, string $action, int $targetId): string
    {
        return "{$clinicId}:{$action}:{$targetId}";
    }

    private function reason(string $reason, string $message = 'Give a reason of at least 10 characters.'): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException($message);
        }

        return mb_substr($reason, 0, 2000);
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 2000);
    }

    /**
     * @return array{at: string, user_id: int, name: string, event: string, note: ?string}
     */
    private function entry(User $user, string $event, ?string $note): array
    {
        return ['at' => now()->toIso8601String(), 'user_id' => $user->id, 'name' => $user->name, 'event' => $event, 'note' => $note];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(string $event, DeletionRequest $request, array $extra = []): void
    {
        $this->audit->record($event, $request, [
            'action' => $request->action_type,
            'target_type' => $request->target_type,
            'target_id' => $request->target_id,
            'target' => $request->target_label,
            'patient_id' => $request->patient_id,
            'branch_id' => $request->branch_id,
            ...$extra,
        ], ['clinic_id' => $request->clinic_id]);
    }
}
