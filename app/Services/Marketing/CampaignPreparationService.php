<?php

namespace App\Services\Marketing;

use App\Jobs\SendCampaignMessageJob;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;

class CampaignPreparationService
{
    public function __construct(
        protected AudienceQueryService $audienceQueryService
    ) {}

    /**
     * Prepare campaign recipient snapshot and dispatch queued sending jobs.
     *
     * Callable directly (tests do, on a fresh draft) or via PrepareCampaignJob
     * — which runs after the controller has already flipped the campaign to
     * `preparing` as its own guarded, atomic step. Both are valid starting
     * points, so the check below accepts `preparing` alongside the states
     * MarketingCampaign::canLaunch() considers launchable; it is the queued
     * path's normal starting status, not evidence of a stuck campaign.
     */
    public function prepareAndLaunchCampaign(MarketingCampaign $campaign): array
    {
        // 1. Validation Checks
        if (! in_array($campaign->status, ['draft', 'scheduled', 'paused', 'preparing'])) {
            throw new \InvalidArgumentException("Campaign status '{$campaign->status}' cannot be launched.");
        }

        $account = $campaign->whatsappAccount;
        if (! $account || $account->status !== 'connected') {
            throw new \InvalidArgumentException('Connected WhatsApp Business account is required to launch campaign.');
        }

        $template = $campaign->template;
        if (! $template || ! $template->isApproved()) {
            throw new \InvalidArgumentException('Only approved WhatsApp templates can be used for marketing campaigns.');
        }

        if ($template->whatsapp_account_id !== $account->id) {
            throw new \InvalidArgumentException('Template must belong to the selected WhatsApp account.');
        }

        $audience = $campaign->audience;
        if (! $audience) {
            throw new \InvalidArgumentException('Audience is required to launch campaign.');
        }

        // 2. Update Campaign State to Preparing
        $campaign->update([
            'status' => 'preparing',
            'started_at' => now(),
        ]);

        // 3. Resolve Dynamic Eligible Contacts & Exclude Opted-out/Suppressed
        $eligibleContacts = $this->audienceQueryService->resolveEligibleContactsQuery($audience)->get();

        $variableMapping = $campaign->variable_mapping ?? [];
        $createdRecipientsCount = 0;
        $jobsDispatchedCount = 0;

        foreach ($eligibleContacts as $contact) {
            // Personalize template message variables — resolved once, here, and
            // frozen into the snapshot. Send time never re-reads contact data.
            $personalizedBody = $template->body;
            $bodyParameters = [];
            $missingVariable = false;

            if (! empty($template->variables)) {
                foreach ($template->variables as $varKey) {
                    $mappedField = $variableMapping[$varKey] ?? 'name';
                    $varVal = match ($mappedField) {
                        'name' => $contact->name,
                        'organization' => $contact->organization ?? 'Clinic',
                        'city' => $contact->city ?? '',
                        'phone' => $contact->phone,
                        default => $contact->{$mappedField} ?? null,
                    };

                    if ($varVal === null || $varVal === '') {
                        $missingVariable = true;
                        break;
                    }

                    $personalizedBody = str_replace('{{'.$varKey.'}}', $varVal, $personalizedBody);
                    $bodyParameters[] = ['type' => 'text', 'text' => (string) $varVal];
                }
            }

            $templateComponents = $bodyParameters
                ? [['type' => 'body', 'parameters' => $bodyParameters]]
                : [];

            // Create Recipient Snapshot (Unique campaign_id + contact_id)
            $recipient = MarketingCampaignRecipient::firstOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                ],
                [
                    'phone' => $contact->phone,
                    'normalized_phone' => $contact->normalized_phone,
                    'status' => $missingVariable ? 'failed' : 'pending',
                    'failure_reason' => $missingVariable ? 'Missing required template variable mapping' : null,
                    'personalized_payload' => [
                        'template_name' => $template->name,
                        'template_language' => $template->language,
                        'rendered_body' => $personalizedBody,
                        'variable_mapping' => $variableMapping,
                        'template_components' => $templateComponents,
                    ],
                ]
            );

            if ($recipient->wasRecentlyCreated) {
                $createdRecipientsCount++;
            }

            /*
             * Dispatch if this recipient still needs to go out. On a fresh
             * launch every non-failed recipient is 'pending' right up to
             * this line. On a resume, `firstOrCreate` found an *existing*
             * row instead — one that was already flipped to 'queued' before
             * the campaign was paused, and stayed 'queued' because the job
             * behind it saw the paused status and returned without ever
             * updating it. Redispatching those is what actually resumes
             * them; SendCampaignMessageJob's own status guard makes doing
             * so safe even if that original job is still sitting in the
             * queue rather than truly gone.
             */
            if (in_array($recipient->status, ['pending', 'queued'])) {
                $recipient->update([
                    'status' => 'queued',
                    'queued_at' => now(),
                ]);

                SendCampaignMessageJob::dispatch($recipient->id);
                $jobsDispatchedCount++;
            }
        }

        // 4. Update Campaign State to Sending
        $campaign->update([
            'status' => 'sending',
        ]);

        return [
            'recipients_created' => $createdRecipientsCount,
            'jobs_dispatched' => $jobsDispatchedCount,
        ];
    }
}
