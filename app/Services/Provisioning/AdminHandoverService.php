<?php

namespace App\Services\Provisioning;

use App\Models\Clinic;
use App\Models\Lead;
use App\Models\User;
use App\Services\CRM\DealConversationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * Hand the tenant over to the customer.
 *
 * The boundary this class exists to hold: **CRM creates exactly one identity —
 * the initial admin — and no others.** Every subsequent user of that clinic is
 * created by the customer, from inside their own administration, under their
 * own governance. A vendor sales tool that can mint logins into a live clinic
 * is a standing privilege nobody asked for and an audit problem later, and it
 * would quietly make us the operator of their staff directory.
 *
 * Handover carries no password. A one-time setup token is issued through
 * Laravel's own reset broker and delivered over WhatsApp; the customer chooses
 * their own credential and we never hold it. That is why nothing here logs,
 * stores or returns a secret — the link is sent and forgotten.
 *
 * WhatsApp is the only channel for this build, by decision. Email exists in the
 * codebase for the won-deal welcome and is untouched; it is simply not the
 * route a trial handover takes.
 */
class AdminHandoverService
{
    /** How long the setup link is good for, in the message copy. */
    public const LINK_HOURS = 24;

    public function __construct(protected DealConversationService $conversation) {}

    /**
     * Send the initial admin their setup link over WhatsApp.
     *
     * Never throws. A handover that fails is a thing the salesperson needs to
     * see and retry, not a reason to roll back a tenant that was built
     * correctly — the environment is real either way, and the link can be
     * re-sent without touching it.
     *
     * @return array{delivered: bool, channel: string, reason: ?string}
     */
    public function send(Lead $lead, User $admin, Clinic $clinic): array
    {
        if (! $lead->phone) {
            return $this->failure('No WhatsApp number on the deal.');
        }

        try {
            $link = $this->setupLink($admin);
        } catch (\Throwable $e) {
            Log::warning('Could not issue a handover token', [
                'lead_id' => $lead->id,
                'user_id' => $admin->id,
                'reason' => $e->getMessage(),
            ]);

            return $this->failure('Could not issue a setup link.');
        }

        $delivered = $this->conversation->sendAutomated(
            $lead,
            $this->message($admin, $clinic, $link),
            'trial_handover',
        );

        return [
            'delivered' => $delivered,
            'channel' => 'whatsapp',
            /*
             * `sendAutomated` already records the specific failure on the deal's
             * timeline, so repeating the provider's wording here would put the
             * same fact in two places and let them disagree.
             */
            'reason' => $delivered ? null : 'WhatsApp not delivered — see the deal timeline.',
        ];
    }

    /**
     * A single-use password-setup URL.
     *
     * Uses the framework's reset broker rather than a bespoke invitation token:
     * expiry, single use and hashing are already solved there, and a second
     * token table would be a second thing to get wrong.
     */
    public function setupLink(User $admin): string
    {
        $token = Password::broker()->createToken($admin);

        return route('password.reset', ['token' => $token, 'email' => $admin->email]);
    }

    /**
     * What the customer reads.
     *
     * Their clinic name and their own workspace, not ours — the first thing
     * they see should look like their practice, not a vendor's dispatch.
     */
    protected function message(User $admin, Clinic $clinic, string $link): string
    {
        $name = trim(explode(' ', (string) $admin->name)[0] ?: 'there');

        return implode("\n\n", [
            "Hi {$name}, your {$clinic->name} workspace is ready.",
            'Set your password and sign in here: '.$link,
            'The link is valid for '.self::LINK_HOURS.' hours. You can add your team once you are in.',
        ]);
    }

    /**
     * @return array{delivered: bool, channel: string, reason: ?string}
     */
    protected function failure(string $reason): array
    {
        return ['delivered' => false, 'channel' => 'whatsapp', 'reason' => $reason];
    }
}
