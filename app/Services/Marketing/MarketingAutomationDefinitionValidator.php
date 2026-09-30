<?php

namespace App\Services\Marketing;

use App\Models\MarketingWhatsAppTemplate;

class MarketingAutomationDefinitionValidator
{
    public const ALLOWED_TRIGGERS = [
        'contact.created',
        'contact.tag_added',
        'contact.tag_removed',
        'contact.status_changed',
        'whatsapp.conversation_started',
        'whatsapp.message_received',
        'whatsapp.reply_received',
        'whatsapp.button_reply',
        'campaign.sent',
        'campaign.delivered',
        'campaign.read',
        'campaign.replied',
        'campaign.completed',
        'lead.created',
        'lead.status_changed',
        'lead.won',
        'lead.lost',
    ];

    public const ALLOWED_STEP_TYPES = [
        'condition',
        'delay',
        'send_template',
        'create_task',
        'create_lead',
        'handover_to_human',
        'add_lead_activity',
        'add_tag',
        'remove_tag',
        'suppress_contact',
        'stop_automation',
    ];

    public const ALLOWED_CONDITION_FIELDS = [
        'contact.name',
        'contact.phone',
        'contact.organization',
        'contact.city',
        'contact.marketing_status',
        'contact.tag',
        'lead.status',
        'lead.source',
        'lead.pipeline_stage',
        'conversation.status',
        'conversation.unread_count',
        'campaign.name',
        'campaign.status',
        'event.button_id',
        'event.button_title',
    ];

    public const ALLOWED_OPERATORS = [
        'is',
        'is_not',
        'contains',
        'does_not_contain',
        'starts_with',
        'exists',
        'does_not_exist',
        'equals',
        'greater_than',
        'less_than',
    ];

    public const ALLOWED_DELAY_UNITS = [
        'minutes',
        'hours',
        'days',
    ];

    /**
     * Validate an automation workflow definition payload.
     *
     * @return array Array of validation error strings (empty if valid).
     */
    public function validate(array $definition, ?int $organizationId = null): array
    {
        $errors = [];

        if (empty($definition) || ! is_array($definition)) {
            return ['Automation definition must be a valid non-empty array/JSON object.'];
        }

        // Trigger Validation
        $trigger = $definition['trigger'] ?? null;
        if (! $trigger || empty($trigger['type'])) {
            $errors[] = 'Automation trigger type is required.';
        } elseif (! in_array($trigger['type'], self::ALLOWED_TRIGGERS, true)) {
            $errors[] = "Unsupported trigger type '{$trigger['type']}'.";
        }

        // Steps Validation
        $steps = $definition['steps'] ?? [];
        if (! is_array($steps)) {
            $errors[] = 'Steps must be an array.';

            return $errors;
        }

        if (count($steps) === 0) {
            $errors[] = 'Automation must contain at least one step.';

            return $errors;
        }

        $stepIds = [];
        $hasAction = false;

        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;

            if (empty($step['id'])) {
                $errors[] = "Step #{$stepNum} is missing an ID.";

                continue;
            }

            if (in_array($step['id'], $stepIds, true)) {
                $errors[] = "Duplicate step ID '{$step['id']}' found.";
            }
            $stepIds[] = $step['id'];

            $stepType = $step['type'] ?? null;
            if (! $stepType || ! in_array($stepType, self::ALLOWED_STEP_TYPES, true)) {
                $errors[] = "Step #{$stepNum} ('{$step['id']}') has an invalid or unwhitelisted step type.";

                continue;
            }

            if (in_array($stepType, ['send_template', 'create_task', 'create_lead', 'handover_to_human', 'add_lead_activity', 'add_tag', 'remove_tag', 'suppress_contact'], true)) {
                $hasAction = true;
            }

            // Type-specific step validation
            switch ($stepType) {
                case 'condition':
                    $conditions = $step['conditions'] ?? null;
                    if (! $conditions || ! is_array($conditions)) {
                        $errors[] = "Step '{$step['id']}' requires a 'conditions' block.";
                        break;
                    }
                    $match = $conditions['match'] ?? 'all';
                    if (! in_array($match, ['all', 'any'], true)) {
                        $errors[] = "Step '{$step['id']}' condition match type must be 'all' or 'any'.";
                    }
                    $rules = $conditions['rules'] ?? [];
                    if (empty($rules) || ! is_array($rules)) {
                        $errors[] = "Step '{$step['id']}' must contain at least one condition rule.";
                    } else {
                        foreach ($rules as $rIdx => $rule) {
                            $field = $rule['field'] ?? null;
                            $operator = $rule['operator'] ?? null;
                            if (! $field || ! in_array($field, self::ALLOWED_CONDITION_FIELDS, true)) {
                                $errors[] = "Step '{$step['id']}' rule #".($rIdx + 1)." has invalid field '{$field}'.";
                            }
                            if (! $operator || ! in_array($operator, self::ALLOWED_OPERATORS, true)) {
                                $errors[] = "Step '{$step['id']}' rule #".($rIdx + 1)." has invalid operator '{$operator}'.";
                            }
                            if (! in_array($operator, ['exists', 'does_not_exist'], true) && ! isset($rule['value'])) {
                                $errors[] = "Step '{$step['id']}' rule #".($rIdx + 1)." requires a 'value'.";
                            }
                        }
                    }
                    break;

                case 'delay':
                    $duration = $step['duration'] ?? null;
                    $unit = $step['unit'] ?? null;
                    if (! is_numeric($duration) || (int) $duration <= 0) {
                        $errors[] = "Step '{$step['id']}' delay duration must be a positive number.";
                    }
                    if (! $unit || ! in_array($unit, self::ALLOWED_DELAY_UNITS, true)) {
                        $errors[] = "Step '{$step['id']}' delay unit must be minutes, hours, or days.";
                    }
                    break;

                case 'send_template':
                    $templateId = $step['template_id'] ?? null;
                    if (! $templateId) {
                        $errors[] = "Step '{$step['id']}' requires a 'template_id'.";
                    } elseif ($organizationId) {
                        $tmpl = MarketingWhatsAppTemplate::where('id', $templateId)
                            ->where('organization_id', $organizationId)
                            ->first();
                        if (! $tmpl) {
                            $errors[] = "Step '{$step['id']}' references non-existent template #{$templateId}.";
                        } elseif (! $tmpl->isApproved()) {
                            $errors[] = "Step '{$step['id']}' references template #{$templateId} which is not approved (status: {$tmpl->status}).";
                        }
                    }
                    break;

                case 'create_task':
                    if (empty($step['title'])) {
                        $errors[] = "Step '{$step['id']}' create_task requires a 'title'.";
                    }
                    break;

                case 'add_tag':
                case 'remove_tag':
                    if (empty($step['tag_name']) && empty($step['tag_id'])) {
                        $errors[] = "Step '{$step['id']}' requires a 'tag_name' or 'tag_id'.";
                    }
                    break;
            }
        }

        if (! $hasAction) {
            $errors[] = 'Automation must contain at least one executable action step.';
        }

        return $errors;
    }
}
