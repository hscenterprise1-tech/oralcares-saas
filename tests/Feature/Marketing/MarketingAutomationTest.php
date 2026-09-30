<?php

namespace Tests\Feature\Marketing;

use App\Jobs\ProcessMarketingAutomationExecution;
use App\LeadPriority;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\MarketingAudience;
use App\Models\MarketingAutomation;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingAutomationExecution;
use App\Models\MarketingAutomationExecutionStep;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\MarketingAutomationEventService;
use App\Services\Marketing\MarketingAutomationExecutionService;
use App\Services\Marketing\WhatsAppMessageService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $userA;

    protected User $userB;

    protected MarketingWhatsAppAccount $accountA;

    protected MarketingContact $contactA;

    protected MarketingWhatsAppConversation $conversationA;

    protected MarketingWhatsAppTemplate $templateA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->provider()->create(['name' => 'Tenant A']);
        $this->orgB = Organization::factory()->provider()->create(['name' => 'Tenant B']);

        $this->userA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'user_type' => 'company_user',
        ]);
        grantMarketingPermissions($this->userA);

        $this->userB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'user_type' => 'company_user',
        ]);
        grantMarketingPermissions($this->userB);

        $this->accountA = MarketingWhatsAppAccount::create([
            'organization_id' => $this->orgA->id,
            'phone_number_id' => '100000000000001',
            'waba_id' => '200000000000001',
            'display_name' => 'Tenant A WhatsApp',
            'display_phone_number' => '+919876543210',
            'access_token_encrypted' => encrypt('fake-token'),
            'webhook_verify_token_encrypted' => encrypt('fake-verify'),
            'status' => 'connected',
        ]);

        $this->contactA = MarketingContact::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Dr. Rahul Patel',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'organization' => 'Vadodara Dental Clinic',
            'city' => 'Vadodara',
            'marketing_status' => 'active',
        ]);

        $this->conversationA = MarketingWhatsAppConversation::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'contact_id' => $this->contactA->id,
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'status' => 'active',
            'unread_count' => 0,
        ]);

        $this->templateA = MarketingWhatsAppTemplate::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'name' => 'welcome_template',
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'approved',
            'body' => 'Hello {{1}}',
            'components' => [],
        ]);
    }

    public function test_user_can_create_automation_draft()
    {
        $payload = [
            'name' => 'WhatsApp Welcome Flow',
            'description' => 'Send template on reply',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    [
                        'id' => 'step_1',
                        'type' => 'send_template',
                        'template_id' => $this->templateA->id,
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.automations.store'), $payload);

        $response->assertRedirect();

        $this->assertDatabaseHas('marketing_automations', [
            'organization_id' => $this->orgA->id,
            'name' => 'WhatsApp Welcome Flow',
            'status' => 'draft',
        ]);
    }

    public function test_invalid_definition_rejected_by_validator()
    {
        $payload = [
            'name' => 'Bad Automation',
            'trigger_type' => 'invalid.trigger',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'invalid.trigger'],
                'steps' => [],
            ],
        ];

        $response = $this->actingAs($this->userA)
            ->post(route('company.marketing.automations.store'), $payload);

        $response->assertSessionHasErrors(['definition']);
    }

    public function test_same_event_twice_creates_only_one_execution_via_idempotency()
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Reply Flow',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    [
                        'id' => 'step_1',
                        'type' => 'create_task',
                        'title' => 'Follow up',
                    ],
                ],
            ],
        ]);

        $eventService = app(MarketingAutomationEventService::class);

        $event1 = $eventService->dispatchTriggerEvent(
            $this->orgA->id,
            'whatsapp.reply_received',
            'whatsapp:msg_101:reply',
            ['body' => 'Hello'],
            $this->contactA,
            $this->conversationA
        );

        $event2 = $eventService->dispatchTriggerEvent(
            $this->orgA->id,
            'whatsapp.reply_received',
            'whatsapp:msg_101:reply',
            ['body' => 'Hello'],
            $this->contactA,
            $this->conversationA
        );

        $this->assertEquals($event1->id, $event2->id);
        $this->assertEquals(1, MarketingAutomationExecution::where('automation_id', $automation->id)->count());
    }

    public function test_concurrent_duplicate_event_insert_is_absorbed_not_thrown()
    {
        // Simulates the real race: two requests both pass the "does this
        // event_key exist yet" check before either has committed (a
        // provider redelivering the same webhook, handled by two overlapping
        // PHP-FPM workers). The service's early SELECT can't see this by
        // construction, so this exercises the layer that actually has to:
        // the DB's own unique constraint plus the catch around it.
        MarketingAutomationEvent::create([
            'organization_id' => $this->orgA->id,
            'event_key' => 'whatsapp:msg_raced:reply',
            'event_type' => 'whatsapp.reply_received',
            'contact_id' => $this->contactA->id,
            'occurred_at' => now(),
        ]);

        $eventService = app(MarketingAutomationEventService::class);
        $reflection = new \ReflectionMethod($eventService, 'createEventAndExecutions');
        $reflection->setAccessible(true);

        expect(fn () => $reflection->invoke(
            $eventService,
            $this->orgA->id,
            'whatsapp.reply_received',
            'whatsapp:msg_raced:reply',
            ['body' => 'racing'],
            $this->contactA,
            null,
            null,
            null
        ))->toThrow(UniqueConstraintViolationException::class);

        // The public entry point catches exactly that and returns the row
        // the winner of the race committed, rather than a 500.
        $result = $eventService->dispatchTriggerEvent(
            $this->orgA->id,
            'whatsapp.reply_received',
            'whatsapp:msg_raced:reply',
            ['body' => 'racing'],
            $this->contactA
        );

        expect($result)->not->toBeNull();
        expect(MarketingAutomationEvent::where('organization_id', $this->orgA->id)
            ->where('event_key', 'whatsapp:msg_raced:reply')
            ->count())->toBe(1);
    }

    public function test_contact_created_trigger_fires_automation()
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'New Contact Welcome Flow',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'contact.created'],
                'steps' => [
                    ['id' => 's1', 'type' => 'create_task', 'title' => 'Welcome call'],
                ],
            ],
        ]);

        $newContact = MarketingContact::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Dr. Ananya Roy',
            'phone' => '+919988776655',
            'normalized_phone' => '919988776655',
            'marketing_status' => 'active',
        ]);

        $this->assertDatabaseHas('marketing_automation_executions', [
            'automation_id' => $automation->id,
            'contact_id' => $newContact->id,
        ]);
    }

    public function test_lead_created_and_status_changed_triggers()
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Lead Created Flow',
            'status' => 'active',
            'trigger_type' => 'lead.created',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'lead.created'],
                'steps' => [
                    ['id' => 's1', 'type' => 'create_task', 'title' => 'Lead Created Task'],
                ],
            ],
        ]);

        $lead = Lead::create([
            'company_name' => 'Roy Clinic',
            'slug' => 'roy-clinic',
            'contact_name' => 'Dr. Ananya Roy',
            'phone' => '+919988776655',
            'normalized_phone' => '919988776655',
            'organization_id' => $this->orgA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::High,
        ]);

        $this->assertDatabaseHas('marketing_automation_executions', [
            'automation_id' => $automation->id,
            'lead_id' => $lead->id,
        ]);
    }

    public function test_campaign_recipient_status_events_trigger_automation()
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Campaign Replied Flow',
            'status' => 'active',
            'trigger_type' => 'campaign.replied',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'campaign.replied'],
                'steps' => [
                    ['id' => 's1', 'type' => 'create_task', 'title' => 'Hot Campaign Lead'],
                ],
            ],
        ]);

        $audience = MarketingAudience::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Test Audience',
            'rules' => ['match' => 'all', 'rules' => []],
        ]);

        $campaign = MarketingCampaign::create([
            'organization_id' => $this->orgA->id,
            'whatsapp_account_id' => $this->accountA->id,
            'audience_id' => $audience->id,
            'template_id' => $this->templateA->id,
            'name' => 'Promo Campaign',
            'status' => 'sending',
        ]);

        $recipient = MarketingCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $this->contactA->id,
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'status' => 'sent',
        ]);

        $recipient->markReplied();

        $this->assertDatabaseHas('marketing_automation_executions', [
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
        ]);
    }

    public function test_contact_opted_out_while_waiting_stops_execution()
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Opted out flow',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    [
                        'id' => 'step_1',
                        'type' => 'send_template',
                        'template_id' => $this->templateA->id,
                    ],
                ],
            ],
        ]);

        $execution = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'status' => 'running',
        ]);

        $this->contactA->update(['marketing_status' => 'opted_out']);

        $executionService = app(MarketingAutomationExecutionService::class);
        $executionService->runExecution($execution);

        $execution->refresh();
        $this->assertEquals('stopped', $execution->status);
        $this->assertEquals('opted_out', $execution->stop_reason);
    }

    public function test_contact_suppressed_while_waiting_stops_execution()
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Suppressed flow',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    [
                        'id' => 'step_1',
                        'type' => 'send_template',
                        'template_id' => $this->templateA->id,
                    ],
                ],
            ],
        ]);

        $execution = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'status' => 'running',
        ]);

        $this->contactA->update(['marketing_status' => 'suppressed']);

        $executionService = app(MarketingAutomationExecutionService::class);
        $executionService->runExecution($execution);

        $execution->refresh();
        $this->assertEquals('stopped', $execution->status);
        $this->assertEquals('suppressed', $execution->stop_reason);
    }

    public function test_lead_becomes_won_while_waiting_stops_execution()
    {
        $lead = Lead::create([
            'company_name' => 'Vadodara Clinic',
            'slug' => 'vadodara-clinic',
            'contact_name' => 'Dr. Rahul Patel',
            'phone' => '+919876543210',
            'normalized_phone' => '919876543210',
            'organization_id' => $this->orgA->id,
            'marketing_contact_id' => $this->contactA->id,
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'source' => 'whatsapp',
        ]);

        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Lead Won Flow',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    [
                        'id' => 'step_1',
                        'type' => 'send_template',
                        'template_id' => $this->templateA->id,
                    ],
                ],
            ],
        ]);

        $execution = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'lead_id' => $lead->id,
            'status' => 'running',
        ]);

        $lead->update(['status' => LeadStatus::Won]);

        $executionService = app(MarketingAutomationExecutionService::class);
        $executionService->runExecution($execution);

        $execution->refresh();
        $this->assertEquals('stopped', $execution->status);
    }

    public function test_paused_automation_prevents_execution_send()
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Paused Flow',
            'status' => 'paused',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    [
                        'id' => 'step_1',
                        'type' => 'send_template',
                        'template_id' => $this->templateA->id,
                    ],
                ],
            ],
        ]);

        $execution = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'status' => 'running',
        ]);

        $executionService = app(MarketingAutomationExecutionService::class);
        $executionService->runExecution($execution);

        $execution->refresh();
        $this->assertEquals('stopped', $execution->status);
        $this->assertEquals('automation_not_active', $execution->stop_reason);
    }

    public function test_unapproved_template_blocks_automated_send()
    {
        $this->templateA->update(['status' => 'REJECTED']);

        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Rejected Template Flow',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    [
                        'id' => 'step_1',
                        'type' => 'send_template',
                        'template_id' => $this->templateA->id,
                    ],
                ],
            ],
        ]);

        $execution = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'status' => 'running',
        ]);

        $executionService = app(MarketingAutomationExecutionService::class);
        $executionService->runExecution($execution);

        $execution->refresh();
        $this->assertEquals('stopped', $execution->status);
    }

    public function test_cross_tenant_automation_access_is_blocked()
    {
        $automationB = MarketingAutomation::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Tenant B Flow',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [['id' => 's1', 'type' => 'stop_automation']],
            ],
        ]);

        $response = $this->actingAs($this->userA)
            ->get(route('company.marketing.automations.show', $automationB->id));

        $response->assertStatus(404);
    }

    public function test_delay_step_parks_execution_waiting_without_running_later_steps()
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Delayed follow-up',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    ['id' => 's1', 'type' => 'delay', 'duration' => 30, 'unit' => 'minutes'],
                    ['id' => 's2', 'type' => 'create_task', 'title' => 'Delayed follow-up'],
                ],
            ],
        ]);

        $execution = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'status' => 'queued',
        ]);

        app(MarketingAutomationExecutionService::class)->runExecution($execution);

        $execution->refresh();
        $this->assertEquals('waiting', $execution->status);
        $this->assertNotNull($execution->next_run_at);
        $this->assertTrue($execution->next_run_at->between(now()->addMinutes(29), now()->addMinutes(31)));
        // The task step after the delay must not have run yet.
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_due_delayed_execution_is_resumed_and_completes_remaining_steps()
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Delayed follow-up',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    ['id' => 's1', 'type' => 'delay', 'duration' => 5, 'unit' => 'minutes'],
                    ['id' => 's2', 'type' => 'create_task', 'title' => 'Delayed follow-up'],
                ],
            ],
        ]);

        $execution = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'status' => 'waiting',
            'current_step_id' => 's1',
            'next_run_at' => now()->subMinute(), // already due
        ]);
        MarketingAutomationExecutionStep::create([
            'execution_id' => $execution->id,
            'automation_step_id' => 's1',
            'step_type' => 'delay',
            'status' => 'completed',
            'completed_at' => now()->subMinute(),
        ]);

        // A future, not-yet-due waiting execution must not be touched.
        $notYetDue = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'status' => 'waiting',
            'next_run_at' => now()->addHour(),
        ]);

        $dispatched = app(MarketingAutomationExecutionService::class)->dispatchDueExecutions();

        $this->assertEquals(1, $dispatched);
        $execution->refresh();
        $this->assertEquals('completed', $execution->status);
        $this->assertDatabaseHas('tasks', ['title' => 'Delayed follow-up']);

        $notYetDue->refresh();
        $this->assertEquals('waiting', $notYetDue->status);
    }

    public function test_automation_paused_before_delayed_execution_comes_due_stops_safely()
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Delayed send',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    ['id' => 's1', 'type' => 'delay', 'duration' => 5, 'unit' => 'minutes'],
                    ['id' => 's2', 'type' => 'send_template', 'template_id' => $this->templateA->id],
                ],
            ],
        ]);

        $execution = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'status' => 'waiting',
            'current_step_id' => 's1',
            'next_run_at' => now()->subMinute(),
        ]);
        MarketingAutomationExecutionStep::create([
            'execution_id' => $execution->id,
            'automation_step_id' => 's1',
            'step_type' => 'delay',
            'status' => 'completed',
            'completed_at' => now()->subMinute(),
        ]);

        // Disabled before the delay came due.
        $automation->update(['status' => 'paused', 'paused_at' => now()]);

        app(MarketingAutomationExecutionService::class)->dispatchDueExecutions();

        $execution->refresh();
        $this->assertEquals('stopped', $execution->status);
        $this->assertEquals('automation_not_active', $execution->stop_reason);
        // No message sent — nothing to prove the template was ever dispatched.
        $this->assertDatabaseCount('marketing_whatsapp_messages', 0);
    }

    public function test_due_executions_from_different_organizations_resume_independently()
    {
        $automationA = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Org A delayed task',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    ['id' => 's1', 'type' => 'delay', 'duration' => 5, 'unit' => 'minutes'],
                    ['id' => 's2', 'type' => 'create_task', 'title' => 'Org A task'],
                ],
            ],
        ]);
        $automationB = MarketingAutomation::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Org B delayed task',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    ['id' => 's1', 'type' => 'delay', 'duration' => 5, 'unit' => 'minutes'],
                    ['id' => 's2', 'type' => 'create_task', 'title' => 'Org B task'],
                ],
            ],
        ]);

        $contactB = MarketingContact::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Dr. Contact B',
            'phone' => '+919000000001',
            'normalized_phone' => '919000000001',
            'marketing_status' => 'active',
        ]);

        foreach ([[$automationA, $this->contactA], [$automationB, $contactB]] as [$automation, $contact]) {
            $execution = MarketingAutomationExecution::create([
                'organization_id' => $automation->organization_id,
                'automation_id' => $automation->id,
                'contact_id' => $contact->id,
                'status' => 'waiting',
                'current_step_id' => 's1',
                'next_run_at' => now()->subMinute(),
            ]);
            MarketingAutomationExecutionStep::create([
                'execution_id' => $execution->id,
                'automation_step_id' => 's1',
                'step_type' => 'delay',
                'status' => 'completed',
                'completed_at' => now()->subMinute(),
            ]);
        }

        $dispatched = app(MarketingAutomationExecutionService::class)->dispatchDueExecutions();

        $this->assertEquals(2, $dispatched);
        $this->assertDatabaseHas('tasks', ['title' => 'Org A task']);
        $this->assertDatabaseHas('tasks', ['title' => 'Org B task']);
    }

    /**
     * Shared fixture for the retry-policy tests below: one active automation
     * with a single send_template step, and its not-yet-started execution.
     */
    protected function makeSendTemplateExecution(): array
    {
        $automation = MarketingAutomation::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Retry Policy Flow',
            'status' => 'active',
            'trigger_type' => 'whatsapp.reply_received',
            'definition' => [
                'version' => 1,
                'trigger' => ['type' => 'whatsapp.reply_received'],
                'steps' => [
                    ['id' => 'step_1', 'type' => 'send_template', 'template_id' => $this->templateA->id],
                ],
            ],
        ]);

        $execution = MarketingAutomationExecution::create([
            'organization_id' => $this->orgA->id,
            'automation_id' => $automation->id,
            'contact_id' => $this->contactA->id,
            'status' => 'queued',
        ]);

        return [$automation, $execution];
    }

    public function test_explicit_business_failure_is_terminal_immediately_no_retry_involved()
    {
        // "Missing phone" is a business-rule failure returned by
        // executeSendTemplateAction() as an ordinary ['action' => 'failed']
        // value, not a thrown exception — it must remain terminal on the
        // very first attempt, with no queue retry ever entering the
        // picture.
        [, $execution] = $this->makeSendTemplateExecution();
        $execution->update(['contact_id' => null]); // no contact_id, no conversation_id -> no phone anywhere

        $job = new ProcessMarketingAutomationExecution($execution->id);
        $job->handle(app(MarketingAutomationExecutionService::class));

        $execution->refresh();
        $this->assertEquals('failed', $execution->status);
        $this->assertSame('No target phone number available for template send.', $execution->last_error);
    }

    public function test_unexpected_exception_on_first_attempt_does_not_immediately_fail_execution()
    {
        [, $execution] = $this->makeSendTemplateExecution();

        $this->mock(WhatsAppMessageService::class, function ($mock) {
            $mock->shouldReceive('sendOutboundTemplateMessage')
                ->andThrow(new \RuntimeException('Simulated transient WhatsApp API failure'));
        });

        $job = new ProcessMarketingAutomationExecution($execution->id);

        $this->expectException(\RuntimeException::class);

        try {
            $job->handle(app(MarketingAutomationExecutionService::class));
        } finally {
            // The exception must reach the job (asserted by expectException
            // above) rather than being swallowed inside runExecution() — and
            // the execution must not have been made terminal in the process.
            $execution->refresh();
            $this->assertNotEquals('failed', $execution->status);
            $this->assertNull($execution->last_error);
        }
    }

    public function test_transient_failure_then_success_completes_the_execution()
    {
        [, $execution] = $this->makeSendTemplateExecution();

        // Attempt 1: the queue's first try, which fails transiently.
        $this->mock(WhatsAppMessageService::class, function ($mock) {
            $mock->shouldReceive('sendOutboundTemplateMessage')
                ->once()
                ->andThrow(new \RuntimeException('Simulated transient WhatsApp API failure'));
        });

        try {
            (new ProcessMarketingAutomationExecution($execution->id))
                ->handle(app(MarketingAutomationExecutionService::class));
            $this->fail('Expected the first attempt to throw.');
        } catch (\RuntimeException) {
            // expected
        }

        $execution->refresh();
        $this->assertNotEquals('failed', $execution->status);

        // Attempt 2: the queue's retry — same execution row, and this time
        // the send succeeds.
        $this->app->forgetInstance(WhatsAppMessageService::class);
        $this->mock(WhatsAppMessageService::class, function ($mock) {
            $mock->shouldReceive('sendOutboundTemplateMessage')
                ->once()
                ->andReturn(['success' => true, 'message_id' => 999]);
        });

        (new ProcessMarketingAutomationExecution($execution->id))
            ->handle(app(MarketingAutomationExecutionService::class));

        $execution->refresh();
        $this->assertEquals('completed', $execution->status);
    }

    public function test_failed_handler_marks_execution_failed_only_after_retries_exhausted()
    {
        [, $execution] = $this->makeSendTemplateExecution();

        $this->mock(WhatsAppMessageService::class, function ($mock) {
            $mock->shouldReceive('sendOutboundTemplateMessage')
                ->andThrow(new \RuntimeException('Persistent WhatsApp API failure'));
        });

        $job = new ProcessMarketingAutomationExecution($execution->id);

        // Three attempts, matching the job's declared tries=3 — none of
        // them may leave the execution terminal; that is what makes a
        // queue retry meaningful in the first place.
        foreach (range(1, 3) as $attempt) {
            try {
                $job->handle(app(MarketingAutomationExecutionService::class));
                $this->fail("Expected attempt {$attempt} to throw.");
            } catch (\RuntimeException) {
                // expected
            }

            $execution->refresh();
            $this->assertNotEquals('failed', $execution->status, "Execution became terminal after attempt {$attempt}, before retries were exhausted.");
        }

        // Only once the queue gives up after the last try does it call
        // failed() — this is the one place that may terminalize the run.
        $job->failed(new \RuntimeException('Persistent WhatsApp API failure'));

        $execution->refresh();
        $this->assertEquals('failed', $execution->status);
        $this->assertEquals('Persistent WhatsApp API failure', $execution->last_error);

        $step = MarketingAutomationExecutionStep::where('execution_id', $execution->id)->first();
        $this->assertEquals('failed', $step->status);
        $this->assertEquals('Persistent WhatsApp API failure', $step->error_message);
    }

    public function test_step_idempotency_is_preserved_across_repeated_retry_attempts()
    {
        [, $execution] = $this->makeSendTemplateExecution();

        $this->mock(WhatsAppMessageService::class, function ($mock) {
            $mock->shouldReceive('sendOutboundTemplateMessage')
                ->andThrow(new \RuntimeException('Simulated transient WhatsApp API failure'));
        });

        $job = new ProcessMarketingAutomationExecution($execution->id);
        $stepIds = [];

        foreach (range(1, 3) as $attempt) {
            try {
                $job->handle(app(MarketingAutomationExecutionService::class));
            } catch (\RuntimeException) {
                // expected on every attempt here
            }

            $steps = MarketingAutomationExecutionStep::where('execution_id', $execution->id)
                ->where('automation_step_id', 'step_1')
                ->get();

            // firstOrCreate() must keep finding the same row, never adding
            // a second one for the same step across repeated attempts —
            // the send layer's own dedup depends on the idempotency key
            // (derived from this row's id) staying constant.
            $this->assertCount(1, $steps, "Attempt {$attempt} produced more than one step row.");
            $stepIds[] = $steps->first()->id;
        }

        $this->assertCount(1, array_unique($stepIds), 'The step row id changed across retry attempts.');
    }
}
