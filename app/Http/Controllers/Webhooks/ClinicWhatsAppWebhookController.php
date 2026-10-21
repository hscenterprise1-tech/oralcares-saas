<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Marketing\WebhookSignatureVerifier;
use App\Services\WhatsApp\ClinicWhatsAppWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Meta's callbacks for clinic WhatsApp numbers. Separate from the HQ
 * marketing webhook (`/webhooks/whatsapp`): the clinic numbers' Meta app
 * points here, and nothing here reads or writes HQ marketing records.
 */
class ClinicWhatsAppWebhookController extends Controller
{
    public function __construct(
        protected ClinicWhatsAppWebhooks $webhooks,
        protected WebhookSignatureVerifier $signatures,
    ) {}

    public function verify(Request $request): Response
    {
        $answer = $this->webhooks->challenge(
            (string) $request->query('hub_mode', $request->query('hub.mode', '')),
            (string) $request->query('hub_verify_token', $request->query('hub.verify_token', '')),
            (string) $request->query('hub_challenge', $request->query('hub.challenge', '')),
        );

        return $answer === null ? response('Forbidden', 403) : response($answer, 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): JsonResponse
    {
        $signature = $this->signatures->check($request);
        if ($this->signatures->rejects($signature)) {
            Log::warning('Clinic WhatsApp webhook refused: signature check failed', ['result' => $signature, 'ip' => $request->ip()]);

            return response()->json(['status' => 'invalid_signature'], 403);
        }

        $result = $this->webhooks->handle($request->all(), $signature === WebhookSignatureVerifier::VERIFIED);

        return response()->json(['status' => 'success', ...$result]);
    }
}
