<?php

namespace App\Services\Onboarding;

use App\ClinicStatus;
use App\Events\OnboardingSessionCompleted;
use App\Events\OnboardingSessionStarted;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\OnboardingSession;
use App\Models\OnboardingSessionStep;
use App\Models\OnboardingStepSubmission;
use App\Models\User;
use App\OnboardingSessionStatus;
use App\OnboardingStepStatus;
use App\Services\Provisioning\EntitlementResolver;
use App\Services\Security\SecurityAudit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The reusable onboarding engine.
 *
 * Owns sessions and steps: their state machine and timestamps, auto-saved
 * drafts, idempotent submissions and audit events. What a step means lives
 * in its section; which steps a session has comes from its template.
 *
 * Every write locks the session row, so two tabs or a retried request
 * cannot apply the same step twice or race a completion.
 */
class OnboardingEngine
{
    /** Drafts are working copies, not storage: keep them small. */
    public const MAX_DRAFT_BYTES = 65536;

    public function __construct(
        protected OnboardingTemplates $templates,
        protected SecurityAudit $audit,
        protected EntitlementResolver $entitlements,
    ) {}

    /**
     * Start (or return) the clinic's own onboarding. Idempotent: the session
     * is unique per clinic, and steps are created once.
     */
    public function startForClinic(Clinic $clinic, ?User $owner = null): OnboardingSession
    {
        return $this->start($clinic, null, $owner, $this->templates->forClinic($clinic));
    }

    /**
     * Start (or return) one staff member's profile onboarding.
     */
    public function startForStaff(ClinicEmployee $employee, ?User $user = null): OnboardingSession
    {
        return $this->start($employee->clinic, $employee, $user, $this->templates->forEmployee($employee));
    }

    /**
     * The clinic's onboarding session, if it has one.
     */
    public function clinicSession(Clinic $clinic): ?OnboardingSession
    {
        return OnboardingSession::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('subject_key', OnboardingSession::KIND_CLINIC)
            ->first();
    }

    public function staffSession(ClinicEmployee $employee): ?OnboardingSession
    {
        return OnboardingSession::withoutGlobalScopes()
            ->where('clinic_id', $employee->clinic_id)
            ->where('subject_key', OnboardingSession::subjectKeyFor($employee))
            ->first();
    }

    public function context(OnboardingSession $session, ?User $actor): OnboardingContext
    {
        $clinic = $session->clinic()->withoutGlobalScopes()->firstOrFail();
        $employee = $session->clinic_employee_id
            ? ClinicEmployee::withoutGlobalScopes()->where('clinic_id', $clinic->id)->find($session->clinic_employee_id)
            : null;

        return new OnboardingContext(
            session: $session,
            clinic: $clinic,
            branch: $this->primaryBranch($clinic, $employee),
            actor: $actor,
            employee: $employee,
            options: $this->templates->options($session->template_key, $clinic),
        );
    }

    /**
     * Everything the wizard needs to render, with each step's draft laid over
     * its initial values so a returning owner sees what they left.
     *
     * @return array<string, mixed>
     */
    public function view(OnboardingSession $session, ?User $actor, ?string $stepKey = null): array
    {
        $this->refreshBlock($session);
        $context = $this->context($session, $actor);
        $steps = $this->steps($session);
        $currentKey = $stepKey && $steps->has($stepKey) ? $stepKey : ($session->current_step ?? $steps->keys()->first());
        $current = $steps->get($currentKey);
        $section = $this->templates->section($currentKey);

        return [
            'session' => [
                'id' => $session->id,
                'kind' => $session->kind,
                'status' => $session->status->value,
                'template' => $session->template_key,
                'template_label' => $this->templates->template($session->template_key)['label'],
                'current_step' => $session->current_step,
                'blocked_reason' => $session->blocked_reason,
                'started_at' => $session->started_at?->toIso8601String(),
                'completed_at' => $session->completed_at?->toIso8601String(),
                'progress' => [
                    'done' => $steps->filter(fn (OnboardingSessionStep $step) => $step->status->isDone())->count(),
                    'total' => $steps->count(),
                ],
            ],
            'steps' => $steps->values()->map(function (OnboardingSessionStep $step) use ($context) {
                $section = $this->templates->section($step->step_key);

                return [
                    'key' => $step->step_key,
                    'title' => $section->title($context),
                    'status' => $step->status->value,
                    'required' => $step->is_required,
                    'completed_at' => $step->completed_at?->toIso8601String(),
                ];
            })->all(),
            'step' => [
                'key' => $currentKey,
                'title' => $section->title($context),
                'description' => $section->description($context),
                'component' => $section->component(),
                'required' => $current->is_required,
                'status' => $current->status->value,
                'values' => array_replace($section->initial($context), (array) ($current->draft ?? [])),
                'has_draft' => $current->draft !== null,
                'draft_saved_at' => $current->draft_saved_at?->toIso8601String(),
                'meta' => $section->meta($context),
            ],
        ];
    }

    /**
     * Auto-save a working copy of a step. Drafts never touch the clinic's
     * configuration; only keys the section validates are kept.
     *
     * @param  array<string, mixed>  $draft
     */
    public function saveDraft(OnboardingSession $session, string $stepKey, array $draft, ?User $actor): OnboardingSessionStep
    {
        return DB::transaction(function () use ($session, $stepKey, $draft, $actor) {
            $session = $this->lock($session);
            $this->assertAcceptsChanges($session);
            $step = $this->step($session, $stepKey);

            $allowed = collect(array_keys($this->templates->section($stepKey)->rules($this->context($session, $actor))))
                ->map(fn (string $rule) => explode('.', $rule)[0])
                ->unique()
                ->all();
            $draft = array_intersect_key($draft, array_flip($allowed));

            if (strlen((string) json_encode($draft)) > self::MAX_DRAFT_BYTES) {
                throw ValidationException::withMessages(['draft' => 'This draft is too large to save.']);
            }

            $step->update([
                'draft' => $draft,
                'draft_saved_at' => now(),
                'started_at' => $step->started_at ?? now(),
                'status' => $step->status === OnboardingStepStatus::Pending ? OnboardingStepStatus::InProgress : $step->status,
            ]);

            $this->markActive($session, $actor);

            return $step;
        });
    }

    /**
     * Submit a step. Idempotent on `$idempotencyKey`: a repeat with the same
     * payload is answered without applying again; the same key with a
     * different payload is refused.
     *
     * @param  array<string, mixed>  $input
     * @return array{replayed: bool, next: ?string, completed: bool}
     */
    public function submit(OnboardingSession $session, string $stepKey, array $input, string $idempotencyKey, ?User $actor): array
    {
        $hash = hash('sha256', (string) json_encode($this->canonical($input)));

        try {
            return DB::transaction(function () use ($session, $stepKey, $input, $idempotencyKey, $actor, $hash) {
                $session = $this->lock($session);
                $step = $this->step($session, $stepKey);

                $previous = OnboardingStepSubmission::withoutGlobalScopes()
                    ->where('onboarding_session_step_id', $step->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($previous) {
                    if (! hash_equals($previous->payload_hash, $hash)) {
                        throw new OnboardingConflictException('This save was already used for different details. Reload and try again.');
                    }

                    return ['replayed' => true, 'next' => $session->current_step, 'completed' => $session->isCompleted()];
                }

                $this->assertAcceptsChanges($session);
                $context = $this->context($session, $actor);
                $section = $this->templates->section($stepKey);
                $rules = $section->rules($context);
                $data = Validator::make($input, $rules, $this->messages(), $this->attributeNames($section, $context, $rules))->validate();

                if ($stepKey === $this->finalStepKey($session)) {
                    $this->assertReadyToFinish($session, $stepKey);
                }

                $section->apply($context, $data);

                $step->update([
                    'status' => OnboardingStepStatus::Completed,
                    'data' => $data,
                    'draft' => null,
                    'draft_saved_at' => null,
                    'started_at' => $step->started_at ?? now(),
                    'completed_at' => now(),
                    'submission_count' => $step->submission_count + 1,
                ]);

                OnboardingStepSubmission::create([
                    'onboarding_session_step_id' => $step->id,
                    'clinic_id' => $session->clinic_id,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $hash,
                    'user_id' => $actor?->id,
                ]);

                $this->audit->record('onboarding.step_completed', $session, ['step' => $stepKey, 'kind' => $session->kind], $this->auditScope($session, $actor));

                $completed = false;
                if ($stepKey === $this->finalStepKey($session)) {
                    $this->complete($session, $actor);
                    $completed = true;
                } else {
                    $this->markActive($session, $actor);
                    $session->update(['current_step' => $this->nextOpenStep($session, $stepKey)]);
                }

                return ['replayed' => false, 'next' => $session->current_step, 'completed' => $completed];
            });
        } catch (QueryException $e) {
            // Two identical submissions racing past the lookup: the unique
            // index lets one win; the other is a replay.
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'onboarding_submission_key_unique') || str_contains($message, 'onboarding_step_submissions.idempotency_key')) {
                return ['replayed' => true, 'next' => $session->fresh()?->current_step, 'completed' => (bool) $session->fresh()?->isCompleted()];
            }

            throw $e;
        }
    }

    /**
     * Skip an optional step.
     */
    public function skip(OnboardingSession $session, string $stepKey, ?User $actor): void
    {
        DB::transaction(function () use ($session, $stepKey, $actor) {
            $session = $this->lock($session);
            $this->assertAcceptsChanges($session);
            $step = $this->step($session, $stepKey);

            if ($step->is_required) {
                throw ValidationException::withMessages(['step' => 'This step is needed to finish setup.']);
            }

            if (! $step->status->isDone()) {
                $step->update(['status' => OnboardingStepStatus::Skipped, 'skipped_at' => now(), 'draft' => null]);
                $this->audit->record('onboarding.step_skipped', $session, ['step' => $stepKey], $this->auditScope($session, $actor));
            }

            $this->markActive($session, $actor);
            $session->update(['current_step' => $this->nextOpenStep($session, $stepKey)]);
        });
    }

    public function pause(OnboardingSession $session, ?User $actor): void
    {
        DB::transaction(function () use ($session, $actor) {
            $session = $this->lock($session);

            if ($session->status === OnboardingSessionStatus::Paused || ! $session->status->canMoveTo(OnboardingSessionStatus::Paused)) {
                return;
            }

            $session->update(['status' => OnboardingSessionStatus::Paused, 'paused_at' => now()]);
            $this->audit->record('onboarding.paused', $session, [], $this->auditScope($session, $actor));
        });
    }

    /**
     * Resume a paused session (opening the wizard again does this).
     */
    public function resume(OnboardingSession $session, ?User $actor): void
    {
        DB::transaction(function () use ($session, $actor) {
            $session = $this->lock($session);

            if ($session->status !== OnboardingSessionStatus::Paused) {
                return;
            }

            $session->update(['status' => OnboardingSessionStatus::InProgress, 'paused_at' => null, 'last_activity_at' => now()]);
            $this->audit->record('onboarding.resumed', $session, [], $this->auditScope($session, $actor));
        });
    }

    /**
     * Block a clinic session while the clinic cannot be used (suspended, or
     * its subscription window closed), and lift the block once it can.
     */
    public function refreshBlock(OnboardingSession $session): void
    {
        if ($session->isCompleted()) {
            return;
        }

        $reason = $this->blockReason($session);

        if ($reason && $session->status !== OnboardingSessionStatus::Blocked) {
            $session->update([
                'status_before_block' => $session->status->value,
                'status' => OnboardingSessionStatus::Blocked,
                'blocked_reason' => $reason,
                'blocked_at' => now(),
            ]);
            $this->audit->record('onboarding.blocked', $session, ['reason' => $reason], ['clinic_id' => $session->clinic_id]);
        } elseif (! $reason && $session->status === OnboardingSessionStatus::Blocked) {
            $session->update([
                'status' => OnboardingSessionStatus::tryFrom((string) $session->status_before_block) ?? OnboardingSessionStatus::Pending,
                'status_before_block' => null,
                'blocked_reason' => null,
                'blocked_at' => null,
            ]);
            $this->audit->record('onboarding.unblocked', $session, [], ['clinic_id' => $session->clinic_id]);
        }
    }

    /**
     * A one-line summary of every completed step, for the review.
     *
     * @return list<array{key: string, title: string, status: string, lines: list<string>}>
     */
    public function review(OnboardingSession $session, ?User $actor): array
    {
        $context = $this->context($session, $actor);

        return $this->steps($session)
            ->reject(fn (OnboardingSessionStep $step) => $step->step_key === $this->finalStepKey($session))
            ->values()
            ->map(function (OnboardingSessionStep $step) use ($context) {
                $section = $this->templates->section($step->step_key);

                return [
                    'key' => $step->step_key,
                    'title' => $section->title($context),
                    'status' => $step->status->value,
                    'required' => $step->is_required,
                    'lines' => $step->status === OnboardingStepStatus::Completed ? $section->summary($context) : [],
                ];
            })->all();
    }

    private function start(Clinic $clinic, ?ClinicEmployee $employee, ?User $owner, string $templateKey): OnboardingSession
    {
        $template = $this->templates->template($templateKey);
        $subjectKey = OnboardingSession::subjectKeyFor($employee);

        $session = DB::transaction(function () use ($clinic, $employee, $owner, $templateKey, $template, $subjectKey) {
            $session = OnboardingSession::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)
                ->where('subject_key', $subjectKey)
                ->lockForUpdate()
                ->first();

            if ($session) {
                return $session;
            }

            $session = OnboardingSession::create([
                'clinic_id' => $clinic->id,
                'subject_key' => $subjectKey,
                'kind' => $employee ? OnboardingSession::KIND_STAFF : OnboardingSession::KIND_CLINIC,
                'clinic_employee_id' => $employee?->id,
                'template_key' => $templateKey,
                'template_version' => $template['version'] ?? 1,
                'status' => OnboardingSessionStatus::Pending,
                'current_step' => $template['sections'][0],
                'owner_user_id' => $owner?->id,
            ]);

            $context = $this->context($session, $owner);

            foreach ($template['sections'] as $position => $sectionKey) {
                $section = $this->templates->section($sectionKey);

                OnboardingSessionStep::create([
                    'onboarding_session_id' => $session->id,
                    'clinic_id' => $clinic->id,
                    'step_key' => $sectionKey,
                    'position' => $position,
                    'is_required' => $section->required($context),
                    'status' => OnboardingStepStatus::Pending,
                ]);

                $section->prepare($context);
            }

            $this->audit->record('onboarding.session_created', $session, ['template' => $templateKey, 'kind' => $session->kind], ['clinic_id' => $clinic->id, 'actor_user_id' => $owner?->id]);

            return $session;
        });

        return $session;
    }

    /**
     * @return Collection<string, OnboardingSessionStep>
     */
    private function steps(OnboardingSession $session)
    {
        return OnboardingSessionStep::withoutGlobalScopes()
            ->where('onboarding_session_id', $session->id)
            ->orderBy('position')
            ->get()
            ->keyBy('step_key');
    }

    private function step(OnboardingSession $session, string $stepKey): OnboardingSessionStep
    {
        $step = OnboardingSessionStep::withoutGlobalScopes()
            ->where('onboarding_session_id', $session->id)
            ->where('step_key', $stepKey)
            ->lockForUpdate()
            ->first();

        if (! $step) {
            throw ValidationException::withMessages(['step' => 'That step is not part of this setup.']);
        }

        return $step;
    }

    private function lock(OnboardingSession $session): OnboardingSession
    {
        return OnboardingSession::withoutGlobalScopes()->lockForUpdate()->findOrFail($session->id);
    }

    private function assertAcceptsChanges(OnboardingSession $session): void
    {
        if ($session->status === OnboardingSessionStatus::Blocked) {
            throw ValidationException::withMessages(['step' => $session->blocked_reason ?: 'Setup is on hold.']);
        }

        if ($session->isCompleted()) {
            throw ValidationException::withMessages(['step' => 'Setup is already complete. Change these details from settings.']);
        }
    }

    private function assertReadyToFinish(OnboardingSession $session, string $finalKey): void
    {
        $missing = $this->steps($session)
            ->reject(fn (OnboardingSessionStep $step) => $step->step_key === $finalKey)
            ->filter(fn (OnboardingSessionStep $step) => $step->is_required && $step->status !== OnboardingStepStatus::Completed)
            ->keys()
            ->all();

        if ($missing !== []) {
            $context = $this->context($session, null);
            $titles = collect($missing)->map(fn (string $key) => $this->templates->section($key)->title($context))->implode(', ');

            throw ValidationException::withMessages(['step' => "Finish these first: {$titles}."]);
        }
    }

    private function complete(OnboardingSession $session, ?User $actor): void
    {
        $session->update([
            'status' => OnboardingSessionStatus::Completed,
            'completed_at' => now(),
            'current_step' => $this->finalStepKey($session),
            'paused_at' => null,
            'last_activity_at' => now(),
        ]);

        $this->audit->record('onboarding.completed', $session, ['kind' => $session->kind], $this->auditScope($session, $actor));

        DB::afterCommit(fn () => event(new OnboardingSessionCompleted($session->fresh(), $actor)));
    }

    /**
     * Pending or paused sessions become in progress on any activity.
     */
    private function markActive(OnboardingSession $session, ?User $actor): void
    {
        $wasPending = $session->status === OnboardingSessionStatus::Pending;

        if (in_array($session->status, [OnboardingSessionStatus::Pending, OnboardingSessionStatus::Paused], true)) {
            $session->update([
                'status' => OnboardingSessionStatus::InProgress,
                'started_at' => $session->started_at ?? now(),
                'paused_at' => null,
            ]);
        }

        $session->update(['last_activity_at' => now()]);

        if ($wasPending) {
            $this->audit->record('onboarding.started', $session, ['kind' => $session->kind], $this->auditScope($session, $actor));
            DB::afterCommit(fn () => event(new OnboardingSessionStarted($session->fresh(), $actor)));
        }
    }

    private function nextOpenStep(OnboardingSession $session, string $after): string
    {
        $steps = $this->steps($session);
        $keys = $steps->keys()->all();
        $from = array_search($after, $keys, true);
        $ordered = [...array_slice($keys, $from + 1), ...array_slice($keys, 0, $from + 1)];

        foreach ($ordered as $key) {
            if (! $steps[$key]->status->isDone()) {
                return $key;
            }
        }

        return $this->finalStepKey($session);
    }

    private function finalStepKey(OnboardingSession $session): string
    {
        $sections = $this->templates->template($session->template_key)['sections'];

        return end($sections);
    }

    private function blockReason(OnboardingSession $session): ?string
    {
        $clinic = Clinic::withoutGlobalScopes()->find($session->clinic_id);

        if (! $clinic || $clinic->status !== ClinicStatus::Active) {
            return 'This clinic is not active. Contact OralCares support.';
        }

        $access = $this->entitlements->forClinic($clinic);
        if ($access['has_subscription'] && ! $access['grants_access']) {
            return 'The clinic subscription is not active. Contact OralCares support.';
        }

        return null;
    }

    private function primaryBranch(Clinic $clinic, ?ClinicEmployee $employee): ?Branch
    {
        if ($employee?->primary_branch_id) {
            $branch = Branch::withoutGlobalScopes()->where('clinic_id', $clinic->id)->find($employee->primary_branch_id);
            if ($branch) {
                return $branch;
            }
        }

        return Branch::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, int|null>
     */
    private function auditScope(OnboardingSession $session, ?User $actor): array
    {
        return ['clinic_id' => $session->clinic_id, 'actor_user_id' => $actor?->id];
    }

    /**
     * Plain-language wording for the checks a person is most likely to trip,
     * instead of the framework's "The service ids field is required".
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'service_ids.required' => 'Choose at least one service you provide.',
            'service_ids.min' => 'Choose at least one service you provide.',
            'confirmed.accepted' => 'Tick the box to confirm that the details are correct.',
        ];
    }

    /**
     * Field names for messages: the section's own, else the last segment of
     * the key ("invites.*.email" → "email").
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, string>
     */
    private function attributeNames(OnboardingSection $section, OnboardingContext $context, array $rules): array
    {
        $names = [];
        foreach (array_keys($rules) as $key) {
            $last = collect(explode('.', $key))->reject(fn (string $part) => $part === '*')->last();
            $names[$key] = str_replace('_', ' ', (string) $last);
        }

        return array_replace($names, $section->attributes($context));
    }

    /**
     * Key order must not change the payload hash.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
