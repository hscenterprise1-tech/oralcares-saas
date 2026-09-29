<?php

namespace App\Services\Marketing;

use Illuminate\Http\Request;

/**
 * Proof that a webhook came from Meta.
 *
 * `POST /webhooks/whatsapp` is public and unauthenticated, and processing it
 * creates conversations, messages and contacts. Nothing checked that the
 * payload came from Meta at all, so anything able to reach the server could
 * post one and have it treated as a real customer message.
 *
 * Meta signs the raw request body with the app secret and sends the digest in
 * `X-Hub-Signature-256`. Verification is a constant-time comparison against the
 * body exactly as received — re-encoding the parsed array would produce a
 * different string and never match.
 *
 * When no secret is configured this reports `unconfigured` rather than pass or
 * fail. The caller keeps processing in that state, because turning verification
 * on by default would take inbound WhatsApp down for a running system the moment
 * it shipped; every such event is recorded as unverified and the Integrations
 * page says so.
 */
class WebhookSignatureVerifier
{
    public const VERIFIED = 'verified';

    public const INVALID = 'invalid';

    public const UNCONFIGURED = 'unconfigured';

    public const MISSING = 'missing_signature';

    /** Whether an app secret has been configured at all. */
    public function isConfigured(): bool
    {
        return ! empty($this->secret());
    }

    /**
     * Check the request's signature.
     *
     * @return self::VERIFIED|self::INVALID|self::UNCONFIGURED|self::MISSING
     */
    public function check(Request $request): string
    {
        $secret = $this->secret();

        if (empty($secret)) {
            return self::UNCONFIGURED;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if ($header === '') {
            return self::MISSING;
        }

        /* Meta sends `sha256=<hex digest>`. */
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header) ? self::VERIFIED : self::INVALID;
    }

    /** Whether a result means the payload must not be processed. */
    public function rejects(string $result): bool
    {
        return $result === self::INVALID || $result === self::MISSING;
    }

    protected function secret(): ?string
    {
        $secret = config('services.meta_whatsapp.app_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
