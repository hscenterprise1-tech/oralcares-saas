<?php

namespace App\Observers;

use App\Models\CommercialDocument;
use App\Services\CommercialLedgerService;

class CommercialDocumentObserver
{
    /**
     * Set while a caller is writing its own, better, history entry.
     *
     * `DealCommercialService` records "Agreement accepted — AGR-2026-0001"
     * against the deal; this observer would add "Agreement AGR-2026-0001
     * marked as Accepted." beside it, and a rep reading the timeline would see
     * every commercial step twice. The ledger projection still runs — that is
     * the part of this observer nothing else does.
     */
    public static bool $suppressActivity = false;

    /**
     * Handle the CommercialDocument "created" event.
     */
    public function created(CommercialDocument $document): void
    {
        if (! self::$suppressActivity) {
            $document->lead->activities()->create([
                'user_id' => $document->created_by ?? auth()->id(),
                'type' => 'document',
                'description' => ucfirst($document->type).' '.$document->document_number.' Created (Version '.$document->version.').',
            ]);
        }

        $this->projectToLedger($document);
    }

    /**
     * Handle the CommercialDocument "updated" event.
     */
    public function updated(CommercialDocument $document): void
    {
        if ($document->isDirty('status')) {
            if (! self::$suppressActivity) {
                $status = str_replace('_', ' ', $document->status);
                $document->lead->activities()->create([
                    'user_id' => auth()->id() ?? $document->created_by,
                    'type' => 'document',
                    'description' => ucfirst($document->type).' '.$document->document_number.' marked as '.ucwords($status).'.',
                ]);
            }

            $this->projectToLedger($document);
        }
    }

    /**
     * Mirror an issued invoice into the Finance ledger.
     *
     * Hooked on the model rather than on a controller, so every writer lands in
     * the ledger through one path. The controller that used to do this is gone
     * — CC3 retired the documents surface — and the observer is what keeps the
     * remaining historical rows projecting correctly. The service is idempotent,
     * so repeated saves update the same row instead of duplicating revenue.
     */
    protected function projectToLedger(CommercialDocument $document): void
    {
        /*
         * Nothing. Invoices follow settled quotations now, not document
         * statuses — see CommercialLedgerService::generateInvoice(). The hook
         * is kept as a no-op rather than removed so the observer's other
         * responsibilities stay where they are, and so this comment sits where
         * somebody would look for the old behaviour.
         */
    }
}
