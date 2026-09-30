<?php

namespace App\Services\Contracts;

use App\Models\CommercialDocument;

interface PaymentGatewayInterface
{
    /**
     * Generate a payment link for the commercial document (e.g. Invoice).
     */
    public function generatePaymentLink(CommercialDocument $document): string;

    /**
     * Verify payment status.
     */
    public function verifyPayment(CommercialDocument $document, string $transactionId): bool;
}
