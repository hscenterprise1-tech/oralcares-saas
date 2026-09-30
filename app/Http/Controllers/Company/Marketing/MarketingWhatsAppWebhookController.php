<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Scopes\OrganizationScope;
use App\Services\Marketing\WebhookSignatureVerifier;
use App\Services\Marketing\WhatsAppWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MarketingWhatsAppWebhookController extends Controller
{
    public function __construct(
        protected WhatsAppWebhookService $webhookService,
        protected WebhookSignatureVerifier $signatures,
    ) {}

    /**
     * Public GET endpoint for Meta Webhook Hub Verification Challenge.
     */
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $result = $this->webhookService->handleChallenge($mode, $token, $challenge, 'meta');

        if ($result !== null) {
            return response($result, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Public POST endpoint for receiving Meta WhatsApp webhooks.
     */
    public function handle(Request $request): JsonResponse
    {
        /*
         * Prove it came from Meta before treating it as a customer message.
         *
         * Nothing checked this: the endpoint is public and unauthenticated, and
         * processing a payload creates conversations, messages and contacts, so
         * anything able to reach the server could post one.
         *
         * A bad or missing signature is refused outright. An unconfigured
         * secret still processes — see `WebhookSignatureVerifier` — but the
         * event is recorded as unverified rather than quietly indistinguishable
         * from a signed one.
         */
        $signature = $this->signatures->check($request);

        if ($this->signatures->rejects($signature)) {
            Log::warning('WhatsApp webhook refused: signature check failed', [
                'result' => $signature,
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'invalid_signature'], 403);
        }

        $payload = $request->all();
        if (empty($payload)) {
            return response()->json(['status' => 'empty_payload'], 400);
        }

        /*
         * The one place the organisation boundary is lifted on an HTTP request.
         *
         * This endpoint is public and unauthenticated by design — Meta posts to
         * it — and `handlePayload()` reads and writes `MarketingContact` and
         * `Lead` to turn an inbound message into a lead. `OrganizationScope`
         * fails closed for a request with no resolvable tenant, so without this
         * the handler would match no account, find no contact, create no lead
         * and return `processed: 0` with a 200. Inbound WhatsApp lead capture
         * would have stopped, and nothing would have said so.
         *
         * The bypass is as narrow as the risk allows: it opens only after the
         * Meta signature has been checked above, and the handler still resolves
         * a tenant per event from `phone_number_id` rather than treating "no
         * boundary" as "any account will do".
         */
        $result = OrganizationScope::asSystem(
            fn () => $this->webhookService->handlePayload($payload, 'meta', $signature)
        );

        return response()->json([
            'status' => 'success',
            'processed' => $result['processed'],
            'skipped' => $result['skipped'],
        ], 200);
    }
}
