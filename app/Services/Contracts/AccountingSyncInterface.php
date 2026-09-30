<?php

namespace App\Services\Contracts;

use App\Models\CommercialDocument;

interface AccountingSyncInterface
{
    /**
     * Sync the commercial document to the accounting software.
     */
    public function syncDocument(CommercialDocument $document): string;

    /**
     * Check the sync status of the document.
     */
    public function checkSyncStatus(CommercialDocument $document): string;
}
