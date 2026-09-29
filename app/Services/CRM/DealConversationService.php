<?php

namespace App\Services\CRM;

use App\ActivityType;
use App\Models\Lead;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\User;
use App\Services\Marketing\WhatsAppMessageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One WhatsApp thread per deal, in one place.
 *
 * There were two WhatsApp systems. `lead_whatsapp_messages` was written by the
 * CRM's own send route and rendered by nothing; the Marketing conversation
 * held the real inbound and outbound traffic and was what the deal page
 * actually displayed. So the CRM could "send" a message that reached no
 * customer and appeared in no thread — it wrote a row and an activity line
 * saying it had gone out.
 *
 * The Marketing conversation wins, because it is the one connected to a
 * provider and the one carrying replies. This service is how the CRM reaches
 * it: same account, same conversation, same message table, so a reply sent
 * from a deal and a reply sent from the inbox are the same event.
 *
 * `lead_whatsapp_messages` is left in place with its data intact and is no
 * longer written or read. Dropping it is a separate, deliberate migration.
 */
class DealConversationService
{
    public function __construct(
        protected WhatsAppMessageService $messages,
        protected DealActivityRecorder $activity,
    ) {}

    /**
     * Send a WhatsApp message on this deal.
     *
     * Fails loudly rather than quietly: without a connected account there is
     * no way to deliver anything, and the old behaviour — record it locally
     * and report success — is worse than an error, because the rep believes
     * the customer has been contacted.
     */
    public function sendWhatsApp(Lead $lead, string $body, ?User $user = null): ?MarketingWhatsAppConversation
    {
        $phone = trim((string) $lead->phone);

        if ($phone === '') {
            throw ValidationException::withMessages([
                'message' => 'This deal has no phone number to message.',
            ]);
        }

        $account = $this->connectedAccount($lead);

        if (! $account) {
            throw ValidationException::withMessages([
                'message' => 'No connected WhatsApp account. Connect one in Marketing before sending from a deal.',
            ]);
        }

        $result = $this->messages->sendOutboundMessage($account, $phone, $body);

        if (! ($result['success'] ?? false)) {
            throw ValidationException::withMessages([
                'message' => $result['error'] ?? 'The message could not be sent.',
            ]);
        }

        $conversation = $this->conversationFor($lead, $account, $phone);

        /*
         * Bind the thread to the deal the first time one is sent from here, so
         * the next open of this page finds the same conversation rather than
         * starting another.
         */
        if ($conversation && $conversation->crm_lead_id === null) {
            $conversation->update(['crm_lead_id' => $lead->id]);
        }

        $this->activity->record(
            $lead,
            ActivityType::WhatsApp,
            'WhatsApp sent — '.Str::limit($body, 120),
            ['conversation_id' => $conversation?->id],
            $user,
        );

        return $conversation;
    }

    /**
     * Send on the deal's behalf without a person watching.
     *
     * Demo reminders, feedback requests and the welcome message go out from
     * jobs and observers, where a thrown exception would roll back the demo or
     * the lead that triggered it. So this never throws: it delivers through the
     * Marketing account like any other message, and when it cannot — no
     * connected account, provider refused — it records the attempt on the deal
     * and reports false.
     *
     * That is deliberately louder than the old behaviour, which wrote a row to
     * a table nothing rendered and reported success, so nobody ever learned
     * that a reminder had not reached anybody.
     */
    public function sendAutomated(Lead $lead, string $body, ?string $templateName = null): bool
    {
        try {
            $this->sendWhatsApp($lead, $body, null);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Automated WhatsApp not delivered', [
                'lead_id' => $lead->id,
                'template' => $templateName,
                'reason' => $e->getMessage(),
            ]);

            $this->activity->record(
                $lead,
                ActivityType::WhatsApp,
                'WhatsApp not sent — '.$e->getMessage(),
                ['template' => $templateName, 'undelivered' => true],
            );

            return false;
        }
    }

    /** The thread for this deal, if one exists yet. */
    public function conversation(Lead $lead): ?MarketingWhatsAppConversation
    {
        $conversation = $lead->whatsAppConversation()->first();

        if ($conversation) {
            return $conversation;
        }

        $normalised = preg_replace('/\D+/', '', (string) $lead->phone);

        if (! $normalised) {
            return null;
        }

        return MarketingWhatsAppConversation::query()
            ->where('normalized_phone', 'like', '%'.substr($normalised, -10))
            ->latest('last_message_at')
            ->first();
    }

    protected function conversationFor(Lead $lead, MarketingWhatsAppAccount $account, string $phone): ?MarketingWhatsAppConversation
    {
        $normalised = preg_replace('/\D+/', '', $phone);

        return MarketingWhatsAppConversation::query()
            ->where('whatsapp_account_id', $account->id)
            ->where(fn ($q) => $q->where('phone', $phone)
                ->orWhere('normalized_phone', 'like', '%'.substr((string) $normalised, -10)))
            ->latest('id')
            ->first();
    }

    protected function connectedAccount(Lead $lead): ?MarketingWhatsAppAccount
    {
        return MarketingWhatsAppAccount::query()
            ->where('status', 'connected')
            ->when($lead->organization_id, fn ($q) => $q->where('organization_id', $lead->organization_id))
            ->latest('id')
            ->first();
    }
}
