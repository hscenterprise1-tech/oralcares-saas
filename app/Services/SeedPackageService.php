<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\CommunicationPreference;
use App\Models\NotificationTemplate;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;

class SeedPackageService
{
    /**
     * Seed procedures catalog.
     */
    public function seedProcedures(Clinic $clinic): array
    {
        $entities = [];
        $procedures = [
            ['code' => 'D1110', 'name' => 'Prophylaxis - Adult', 'category' => 'preventive', 'price' => 120.00],
            ['code' => 'D0120', 'name' => 'Periodic Oral Evaluation', 'category' => 'diagnostic', 'price' => 80.00],
        ];

        foreach ($procedures as $proc) {
            $p = ClinicalProcedure::create(array_merge($proc, [
                'clinic_id' => $clinic->id,
                'is_active' => true,
                'default_duration_minutes' => 30,
                'clinical_complexity' => 'low',
            ]));
            $entities['procedures'][] = $p->id;
        }

        return $entities;
    }

    /**
     * Seed default notification templates.
     */
    public function seedNotificationTemplates(Clinic $clinic): array
    {
        $entities = [];
        $templates = [
            [
                'name' => 'Welcome Alert',
                'code' => 'welcome',
                'subject' => 'Welcome to {{clinic_name}}',
                'content' => 'Hello {{patient_name}}, welcome to our clinic.',
            ],
            [
                'name' => 'Payment Due Reminder',
                'code' => 'payment_reminder',
                'subject' => 'Invoice Pending',
                'content' => 'Hello {{patient_name}}, invoice of amount {{bill_amount}} is due.',
            ],
        ];

        foreach ($templates as $tpl) {
            $t = NotificationTemplate::create(array_merge($tpl, [
                'clinic_id' => $clinic->id,
            ]));
            $entities['notification_templates'][] = $t->id;
        }

        return $entities;
    }

    /**
     * Seed default communication preferences.
     */
    public function seedCommunicationPreferences(Clinic $clinic): array
    {
        $entities = [];
        $channels = ['email', 'sms', 'whatsapp', 'in_app'];

        foreach ($channels as $channel) {
            $p = CommunicationPreference::create([
                'clinic_id' => $clinic->id,
                'channel' => $channel,
                'is_enabled' => true,
                'quiet_hours_start' => '22:00:00',
                'quiet_hours_end' => '08:00:00',
            ]);
            $entities['communication_preferences'][] = $p->id;
        }

        return $entities;
    }

    /**
     * Seed default workflow definition, stages, and transitions.
     */
    public function seedDefaultWorkflow(Clinic $clinic): array
    {
        $entities = [];

        $def = WorkflowDefinition::create([
            'clinic_id' => $clinic->id,
            'name' => 'Standard Clinic Workflow',
            'is_active' => true,
        ]);
        $entities['workflow_definitions'][] = $def->id;

        // Add Stages
        $reception = WorkflowStage::create([
            'workflow_definition_id' => $def->id,
            'name' => 'Reception',
            'slug' => 'reception',
            'position' => 10,
        ]);
        $entities['workflow_stages'][] = $reception->id;

        $doctor = WorkflowStage::create([
            'workflow_definition_id' => $def->id,
            'name' => 'Doctor consultation',
            'slug' => 'doctor',
            'position' => 20,
        ]);
        $entities['workflow_stages'][] = $doctor->id;

        $billing = WorkflowStage::create([
            'workflow_definition_id' => $def->id,
            'name' => 'Billing & Checkout',
            'slug' => 'billing',
            'position' => 30,
        ]);
        $entities['workflow_stages'][] = $billing->id;

        // Add Transitions
        $t1 = WorkflowTransition::create([
            'workflow_definition_id' => $def->id,
            'from_stage_id' => $reception->id,
            'to_stage_id' => $doctor->id,
        ]);
        $entities['workflow_transitions'][] = $t1->id;

        $t2 = WorkflowTransition::create([
            'workflow_definition_id' => $def->id,
            'from_stage_id' => $doctor->id,
            'to_stage_id' => $billing->id,
        ]);
        $entities['workflow_transitions'][] = $t2->id;

        return $entities;
    }
}
