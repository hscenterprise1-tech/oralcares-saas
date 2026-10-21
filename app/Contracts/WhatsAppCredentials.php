<?php

namespace App\Contracts;

/**
 * What the WhatsApp transport needs to send on behalf of one business
 * number. The platform's one transport (`MetaWhatsAppProvider`) works for
 * any context that holds a number: OralCares' own marketing account
 * (`MarketingWhatsAppAccount`) and the clinic connections
 * (`WhatsAppConnection`). Each context keeps its own records; only the
 * transport is shared.
 */
interface WhatsAppCredentials
{
    public function whatsAppAccessToken(): ?string;

    public function whatsAppPhoneNumberId(): ?string;

    public function whatsAppBusinessAccountId(): ?string;

    /** A stable label for logs (never the token). */
    public function whatsAppLogKey(): string;
}
