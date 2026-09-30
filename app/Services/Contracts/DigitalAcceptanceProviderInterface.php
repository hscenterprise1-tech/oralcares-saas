<?php

namespace App\Services\Contracts;

use App\Models\CommercialDocument;

interface DigitalAcceptanceProviderInterface
{
    /**
     * Request a digital signature or OTP acceptance for the document.
     */
    public function requestSignature(CommercialDocument $document, array $signers): string;

    /**
     * Verify the digital signature or OTP.
     */
    public function verifySignature(CommercialDocument $document, string $token): bool;
}
