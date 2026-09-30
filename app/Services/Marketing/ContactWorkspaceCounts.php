<?php

namespace App\Services\Marketing;

use App\Models\MarketingContact;
use App\Models\MarketingContactImport;

/**
 * The three figures the contact workspace's tab bar shows.
 *
 * All contacts, Import batches and Compliance are three views of one workspace
 * behind a single tab bar, but each has its own controller. While each of them
 * passed only the counts it happened to have already computed, the bar showed
 * a number beside a tab on one view and nothing beside the same tab on
 * another — so the figures appeared to come and go as you moved between views
 * that had not changed any data.
 *
 * Reading all three from one place is what makes the tab bar describe the
 * whole workspace rather than whichever corner of it you are standing in.
 */
class ContactWorkspaceCounts
{
    /**
     * @return array{contacts: int, batches: int, compliance: int}
     */
    public static function for(?int $organizationId): array
    {
        $contacts = MarketingContact::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId));

        return [
            'contacts' => (clone $contacts)->count(),
            'batches' => MarketingContactImport::query()
                ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
                ->count(),
            /*
             * Both states mean the same thing to someone reading the tab: a
             * person this organisation may no longer message.
             */
            'compliance' => (clone $contacts)
                ->whereIn('marketing_status', ['opted_out', 'suppressed'])
                ->count(),
        ];
    }
}
