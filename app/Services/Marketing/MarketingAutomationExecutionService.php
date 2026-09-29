<?php

namespace App\Services\Marketing;

use App\ActivityType;
use App\Jobs\ResumeMarketingAutomationExecution;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\MarketingAutomationExecution;
use App\Models\MarketingAutomationExecutionStep;
use App\Models\MarketingContact;
use App\Models\MarketingTag;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Task;
use App\Models\User;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationPriority;
use App\Shared\Notification\Services\NotificationEngineService;
use App\UserType;
use Illuminate\Support\Str;

class MarketingAutomationExecutionService
{
    public function __construct(
        protected MarketingAutomationEligibilityService $eligibilityService,
        protected WhatsAppMessageService $messageService,
        protected WhatsAppLeadService $leadService,
        protected NotificationEngineService $notifications
    ) {}

    /**
     * Resume every execution whose delay step has come due.
     *
     * A delay step parks an execution in 'waiting' with a next_run_at, but
     * nothing was polling for that timestamp — the resume job existed and
     * was correctly guarded, but no scheduled entry ever dispatched it, so
     * every delay step simply never resumed. This is that missing poll.
     *
     * @return int number of resume jobs dispatched
     */
    public function dispatchDueExecutions(): int
    {
        $dueIds = MarketingAutomationExecution::query()
            ->where('status', 'waiting')
            ->where('next_run_at', '<=', now())
            ->pluck('id');

        foreach ($dueIds as $id) {
            ResumeMarketingAutomationExecution::dispatch($id);
        }

        return $dueIds->count();
    }

    /**
     * Execute the current or next step in an automation execution.
     */
    public function runExecution(MarketingAutomationExecution $execution): void
    {
        $automation = $execution->automation;
        if (! $automation || $automation->status !== 'active') {
            $execution->update([
                'status' => 'stopped',
                'stopped_at' => now(),
                'stop_reason' => 'automation_not_active',
            ]);

            return;
        }

        // Re-check safety gate
        $eligibility = $this->eligibilityService->checkEligibility($execution, $automation);
        if (! $eligibility['eligible']) {
            $execution->update([
                'status' => 'stopped',
                'stopped_at' => now(),
                'stop_reason' => $eligibility['reason'],
            ]);

            return;
        }

        $definition = $automation->definition;
        $steps = $definition['steps'] ?? [];
        if (empty($steps)) {
            $execution->update(['status' => 'completed', 'completed_at' => now()]);

            return;
        }

        // Determine starting index
        $currentIndex = 0;
        if ($execution->current_step_id) {
            foreach ($steps as $idx => $step) {
                if ($step['id'] === $execution->current_step_id) {
                    // Check if current step is complete
                    $stepLog = MarketingAutomationExecutionStep::where('execution_id', $execution->id)
                        ->where('automation_step_id', $step['id'])
                        ->first();
                    if ($stepLog && $stepLog->status === 'completed') {
                        $currentIndex = $idx + 1;
                    } else {
                        $currentIndex = $idx;
                    }
                    break;
                }
            }
        }

        if ($currentIndex >= count($steps)) {
            $execution->update(['status' => 'completed', 'completed_at' => now()]);

            return;
        }

        $execution->update(['status' => 'running', 'started_at' => $execution->started_at ?: now()]);

        // Loop through remaining steps
        for ($i = $currentIndex; $i < count($steps); $i++) {
            $step = $steps[$i];
            $stepId = $step['id'];
            $execution->update(['current_step_id' => $stepId]);

            // Pre-step safety check
            $stepEligibility = $this->eligibilityService->checkEligibility($execution, $automation);
            if (! $stepEligibility['eligible']) {
                $execution->update([
                    'status' => 'stopped',
                    'stopped_at' => now(),
                    'stop_reason' => $stepEligibility['reason'],
                ]);

                return;
            }

            $stepRecord = MarketingAutomationExecutionStep::firstOrCreate([
                'execution_id' => $execution->id,
                'automation_step_id' => $stepId,
            ], [
                'step_type' => $step['type'],
                'status' => 'running',
                'started_at' => now(),
                'input_payload' => $step,
            ]);

            /*
             * Deliberately not wrapped in a try/catch here. A step's own
             * business-rule failures (unapproved template, no phone, and so
             * on) come back as a normal ['action' => 'failed'] return value
             * and are handled — and made terminal — right below. An actual
             * thrown Throwable means something unexpected happened (a
             * network blip, a DB deadlock) — this is exactly what
             * ProcessMarketingAutomationExecution's tries/backoff exists
             * for, and it only gets a chance to retry if the exception is
             * allowed to reach it rather than being swallowed here.
             * $execution and $stepRecord are both left at 'running' so a
             * retry resumes this same step: firstOrCreate() above finds the
             * existing step row rather than creating a new one, so the
             * idempotency key handed to the send layer doesn't change
             * between attempts.
             */
            $result = $this->executeSingleStep($execution, $step, $stepRecord);

            if ($result['action'] === 'wait') {
                // Delay step scheduled: pause loop and mark execution waiting
                $execution->update([
                    'status' => 'waiting',
                    'next_run_at' => $result['next_run_at'],
                ]);
                $stepRecord->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'scheduled_at' => $result['next_run_at'],
                ]);

                return;
            }

            if ($result['action'] === 'stop') {
                $execution->update([
                    'status' => 'stopped',
                    'stopped_at' => now(),
                    'stop_reason' => $result['reason'] ?? 'step_stopped',
                ]);
                $stepRecord->update(['status' => 'completed', 'completed_at' => now()]);

                return;
            }

            if ($result['action'] === 'failed') {
                $execution->update([
                    'status' => 'failed',
                    'last_error' => $result['error'] ?? 'Step execution failed.',
                ]);
                $stepRecord->update([
                    'status' => 'failed',
                    'error_message' => $result['error'] ?? 'Step failed.',
                ]);

                return;
            }

            // Step succeeded
            $stepRecord->update([
                'status' => 'completed',
                'completed_at' => now(),
                'output_payload' => $result['output'] ?? null,
            ]);
        }

        // All steps executed
        $execution->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /**
     * Execute a single workflow step.
     */
    protected function executeSingleStep(
        MarketingAutomationExecution $execution,
        array $step,
        MarketingAutomationExecutionStep $stepRecord
    ): array {
        $stepType = $step['type'];

        switch ($stepType) {
            case 'condition':
                $passed = $this->evaluateConditions($execution, $step['conditions'] ?? []);
                if (! $passed) {
                    return ['action' => 'stop', 'reason' => 'condition_not_met'];
                }

                return ['action' => 'continue', 'output' => ['condition_passed' => true]];

            case 'delay':
                $duration = (int) ($step['duration'] ?? 1);
                $unit = $step['unit'] ?? 'minutes';
                $delayTime = match ($unit) {
                    'hours' => now()->addHours($duration),
                    'days' => now()->addDays($duration),
                    default => now()->addMinutes($duration),
                };

                return ['action' => 'wait', 'next_run_at' => $delayTime];

            case 'send_template':
                return $this->executeSendTemplateAction($execution, $step, $stepRecord);

            case 'create_lead':
                return $this->executeCreateLeadAction($execution, $step);

            case 'handover_to_human':
                return $this->executeHandoverAction($execution, $step);

            case 'create_task':
                return $this->executeCreateTaskAction($execution, $step);

            case 'add_lead_activity':
                return $this->executeAddLeadActivityAction($execution, $step);

            case 'add_tag':
                return $this->executeTagAction($execution, $step, 'add');

            case 'remove_tag':
                return $this->executeTagAction($execution, $step, 'remove');

            case 'suppress_contact':
                if ($execution->contact) {
                    $execution->contact->update(['marketing_status' => 'suppressed']);
                }

                return ['action' => 'continue', 'output' => ['contact_suppressed' => true]];

            case 'stop_automation':
                return ['action' => 'stop', 'reason' => 'user_stop_step'];

            default:
                return ['action' => 'continue'];
        }
    }

    /**
     * Evaluate rule conditions against execution context.
     */
    public function evaluateConditions(MarketingAutomationExecution $execution, array $conditions): bool
    {
        $match = $conditions['match'] ?? 'all';
        $rules = $conditions['rules'] ?? [];
        if (empty($rules)) {
            return true;
        }

        $results = [];
        $contact = $execution->contact ?: ($execution->contact_id ? MarketingContact::find($execution->contact_id) : null);
        $lead = $execution->lead ?: ($execution->lead_id ? Lead::find($execution->lead_id) : null);
        $conversation = $execution->conversation ?: ($execution->conversation_id ? MarketingWhatsAppConversation::find($execution->conversation_id) : null);
        $eventPayload = $execution->triggerEvent?->payload ?? [];

        foreach ($rules as $rule) {
            $field = $rule['field'] ?? '';
            $operator = $rule['operator'] ?? 'is';
            $expected = $rule['value'] ?? null;

            $actual = match ($field) {
                'contact.name' => $contact?->name,
                'contact.phone' => $contact?->phone,
                'contact.organization' => $contact?->organization,
                'contact.city' => $contact?->city,
                'contact.marketing_status' => $contact?->marketing_status,
                'contact.tag' => $contact?->tags()->pluck('name')->toArray(),
                'lead.status' => $lead?->status?->value ?? (string) $lead?->status,
                'lead.source' => $lead?->source,
                'conversation.status' => $conversation?->status,
                'conversation.unread_count' => $conversation?->unread_count,
                // Which quick-reply button the contact tapped, from the trigger event
                // that started this execution (only set for `whatsapp.button_reply`).
                'event.button_id' => $eventPayload['button_id'] ?? null,
                'event.button_title' => $eventPayload['button_title'] ?? null,
                default => null,
            };

            $passed = $this->compareValues($actual, $operator, $expected);
            $results[] = $passed;
        }

        if ($match === 'any') {
            return in_array(true, $results, true);
        }

        return ! in_array(false, $results, true);
    }

    protected function compareValues(mixed $actual, string $operator, mixed $expected): bool
    {
        if (is_array($actual) && $operator === 'is') {
            return in_array($expected, $actual, true);
        }

        return match ($operator) {
            'is', 'equals' => strtolower((string) $actual) === strtolower((string) $expected),
            'is_not' => strtolower((string) $actual) !== strtolower((string) $expected),
            'contains' => str_contains(strtolower((string) $actual), strtolower((string) $expected)),
            'does_not_contain' => ! str_contains(strtolower((string) $actual), strtolower((string) $expected)),
            'starts_with' => str_starts_with(strtolower((string) $actual), strtolower((string) $expected)),
            'exists' => ! empty($actual),
            'does_not_exist' => empty($actual),
            'greater_than' => (float) $actual > (float) $expected,
            'less_than' => (float) $actual < (float) $expected,
            default => false,
        };
    }

    /**
     * Send Approved WhatsApp Template Action with Idempotency Key.
     */
    protected function executeSendTemplateAction(
        MarketingAutomationExecution $execution,
        array $step,
        MarketingAutomationExecutionStep $stepRecord
    ): array {
        $templateId = (int) ($step['template_id'] ?? 0);
        $eligibility = $this->eligibilityService->checkTemplateActionEligibility($execution, $templateId);
        if (! $eligibility['eligible']) {
            return ['action' => 'stop', 'reason' => $eligibility['reason']];
        }

        $template = MarketingWhatsAppTemplate::find($templateId);
        $account = MarketingWhatsAppAccount::find($template->whatsapp_account_id);
        $phone = $execution->contact?->phone ?: $execution->conversation?->phone;

        if (! $phone) {
            return ['action' => 'failed', 'error' => 'No target phone number available for template send.'];
        }

        // Deterministic action idempotency key (Section 23)
        $idempotencyKey = "automation:{$execution->id}:step:{$stepRecord->id}";

        // Variable substitution
        $variables = $step['variables'] ?? [];
        $parameters = [];
        foreach ($variables as $placeholder => $fieldRef) {
            $val = match ($fieldRef) {
                'contact.name' => $execution->contact?->name,
                'contact.organization' => $execution->contact?->organization,
                'contact.city' => $execution->contact?->city,
                default => $fieldRef,
            };
            $parameters[] = ['type' => 'text', 'text' => (string) ($val ?? '')];
        }

        $components = [];
        if (! empty($parameters)) {
            $components[] = [
                'type' => 'body',
                'parameters' => $parameters,
            ];
        }

        $result = $this->messageService->sendOutboundTemplateMessage(
            $account,
            $phone,
            $template->name,
            $template->language,
            $components,
            $idempotencyKey
        );

        if (! $result['success']) {
            return ['action' => 'failed', 'error' => $result['error'] ?? 'WhatsApp template dispatch failed.'];
        }

        return ['action' => 'continue', 'output' => ['message_id' => $result['message_id'] ?? null]];
    }

    /**
     * Auto-create a CRM Lead from the execution's WhatsApp conversation — no human form,
     * so an "acting user" is resolved to attribute the lead/activity to: the conversation's
     * assigned rep first, falling back to the automation's creator, then any Master Admin.
     */
    protected function executeCreateLeadAction(MarketingAutomationExecution $execution, array $step): array
    {
        $conversation = $execution->conversation;
        if (! $conversation) {
            return ['action' => 'failed', 'error' => 'No WhatsApp conversation available to create a lead from.'];
        }

        $actingUser = $conversation->assignedUser
            ?: ($execution->automation?->created_by_user_id ? User::find($execution->automation->created_by_user_id) : null)
            ?: User::where('organization_id', $execution->organization_id)->where('user_type', UserType::MasterAdmin)->first();

        if (! $actingUser) {
            return ['action' => 'failed', 'error' => 'No eligible user found to attribute the automated lead to.'];
        }

        $lead = $this->leadService->createLeadFromConversation($conversation, [
            'source' => 'whatsapp_automation',
            'priority' => $step['priority'] ?? 'high',
        ], $actingUser);

        return ['action' => 'continue', 'output' => ['lead_id' => $lead->id]];
    }

    /**
     * Hand the conversation to a person, and tell them.
     *
     * This is what "a representative will contact you" actually requires, and
     * until now the engine could not do it: it could tag, suppress, create a
     * lead or create a task, but nothing could put a conversation in front of a
     * human and make them aware of it. A task in a list nobody is watching is
     * not a handover.
     *
     * Assignment and notification are deliberately one step. Assigning without
     * telling anyone produces a conversation that is technically owned and
     * actually abandoned — the exact failure the automation was written to
     * prevent.
     */
    protected function executeHandoverAction(MarketingAutomationExecution $execution, array $step): array
    {
        $conversation = $execution->conversation;

        if (! $conversation) {
            return ['action' => 'failed', 'error' => 'No WhatsApp conversation to hand over.'];
        }

        $target = $this->resolveHandoverTarget($execution, $step);

        if (! $target) {
            return ['action' => 'failed', 'error' => 'No eligible user to hand this conversation to.'];
        }

        /*
         * An already-owned conversation is left with its owner. Automation
         * reassigning a thread out from under whoever is mid-reply would be
         * worse than not firing at all.
         */
        $alreadyOwned = $conversation->assigned_user_id !== null;

        if (! $alreadyOwned) {
            $conversation->forceFill(['assigned_user_id' => $target->id])->save();
        }

        $owner = $alreadyOwned
            ? User::find($conversation->assigned_user_id) ?? $target
            : $target;

        $contactName = $conversation->contact?->name ?? 'A contact';
        $reason = $step['reason'] ?? 'This conversation needs a person.';

        $this->notifications->send($owner, new SendNotificationDTO(
            title: $alreadyOwned ? 'Conversation needs you' : 'Conversation handed to you',
            message: "{$contactName}: {$reason}",
            category: NotificationCategory::CRM,
            priority: NotificationPriority::HIGH,
            metadata: [
                'type' => 'conversation_handover',
                'conversation_id' => $conversation->id,
                'automation_id' => $execution->automation_id,
                'was_already_assigned' => $alreadyOwned,
            ],
        ));

        return [
            'action' => 'continue',
            'output' => [
                'handed_to_user_id' => $owner->id,
                'was_already_assigned' => $alreadyOwned,
            ],
        ];
    }

    /**
     * Who the conversation goes to.
     *
     * In order: a person the rule names, then whoever already owns the contact's
     * lead (they know the history), then the automation's author, then a master
     * admin. The chain matters more than it looks — a handover that resolves to
     * nobody is the silent failure this action exists to remove, so it only
     * gives up when the organization genuinely has no one.
     */
    protected function resolveHandoverTarget(MarketingAutomationExecution $execution, array $step): ?User
    {
        if (! empty($step['assign_to_user_id'])) {
            $named = User::where('id', $step['assign_to_user_id'])
                ->where('organization_id', $execution->organization_id)
                ->first();

            if ($named) {
                return $named;
            }
        }

        $leadOwnerId = $execution->lead?->assigned_user_id
            ?? $execution->contact?->crmLeads()->latest()->value('assigned_user_id');

        if ($leadOwnerId && $owner = User::find($leadOwnerId)) {
            return $owner;
        }

        if ($execution->automation?->created_by_user_id) {
            if ($author = User::find($execution->automation->created_by_user_id)) {
                return $author;
            }
        }

        return User::where('organization_id', $execution->organization_id)
            ->where('user_type', UserType::MasterAdmin)
            ->first();
    }

    protected function executeCreateTaskAction(MarketingAutomationExecution $execution, array $step): array
    {
        $title = $step['title'] ?? 'Automated CRM Task';
        $dueDays = (int) ($step['due_in_days'] ?? 1);

        $taskable = $execution->lead ?: $execution->contact;

        Task::create([
            'title' => $title,
            'description' => $step['description'] ?? "Task created automatically by Automation #{$execution->automation_id}.",
            'status' => 'pending',
            'priority' => 'medium',
            'due_at' => now()->addDays($dueDays),
            'creator_id' => null,
            'taskable_type' => $taskable ? $taskable->getMorphClass() : null,
            'taskable_id' => $taskable ? $taskable->getKey() : null,
        ]);

        return ['action' => 'continue', 'output' => ['task_created' => true]];
    }

    protected function executeAddLeadActivityAction(MarketingAutomationExecution $execution, array $step): array
    {
        $lead = $execution->lead ?: ($execution->contact?->crmLeads()->latest()->first());
        if ($lead) {
            Activity::create([
                'subject_type' => $lead->getMorphClass(),
                'subject_id' => $lead->getKey(),
                'type' => ActivityType::SystemUpdate,
                'description' => $step['description'] ?? "Automated activity logged from Automation #{$execution->automation_id}.",
                'user_id' => null,
            ]);
        }

        return ['action' => 'continue', 'output' => ['activity_logged' => true]];
    }

    protected function executeTagAction(MarketingAutomationExecution $execution, array $step, string $action): array
    {
        $contact = $execution->contact;
        if (! $contact) {
            return ['action' => 'continue'];
        }

        $tagName = $step['tag_name'] ?? null;
        if ($tagName) {
            $tag = MarketingTag::firstOrCreate(
                ['organization_id' => $contact->organization_id, 'name' => trim($tagName)],
                ['slug' => Str::slug(trim($tagName))]
            );

            if ($action === 'add') {
                $contact->tags()->syncWithoutDetaching([$tag->id]);

                if ($contact->organization_id) {
                    app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                        organizationId: $contact->organization_id,
                        eventType: 'contact.tag_added',
                        eventKey: "contact:{$contact->id}:tag_added:{$tag->id}:".now()->timestamp,
                        payload: ['contact_id' => $contact->id, 'tag_id' => $tag->id, 'tag_name' => $tag->name],
                        contact: $contact
                    );
                }
            } else {
                $contact->tags()->detach([$tag->id]);

                if ($contact->organization_id) {
                    app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                        organizationId: $contact->organization_id,
                        eventType: 'contact.tag_removed',
                        eventKey: "contact:{$contact->id}:tag_removed:{$tag->id}:".now()->timestamp,
                        payload: ['contact_id' => $contact->id, 'tag_id' => $tag->id, 'tag_name' => $tag->name],
                        contact: $contact
                    );
                }
            }
        }

        return ['action' => 'continue', 'output' => ['tag_updated' => true]];
    }
}
