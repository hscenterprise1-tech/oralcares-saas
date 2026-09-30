<?php

namespace App\Jobs;

use App\Models\CompanyQuotation;
use App\QuotationStatus;
use App\Services\QuotationActivityService;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationPriority;
use App\Shared\Notification\Services\NotificationEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Expires quotations whose validity window has passed.
 *
 * `valid_until` was stored but never enforced, so sent quotations stayed open
 * indefinitely and pipeline reporting counted stale commercial offers as live.
 *
 * Only in-flight states expire. An approved, rejected or converted quotation is
 * a settled commercial fact and must never be rewritten by a background job.
 *
 * The owner is told. This ran silently for a long time: a live offer became
 * Expired at half past midnight and the person chasing it found out only if they
 * happened to reopen the record. A background job that changes a commercial
 * state owes someone an explanation.
 */
class ExpireOverdueQuotationsJob implements ShouldQueue
{
    use Queueable;

    /**
     * States that represent an offer still awaiting a client decision.
     */
    public const EXPIRABLE_STATUSES = [
        QuotationStatus::Sent,
        QuotationStatus::Negotiation,
    ];

    public function handle(QuotationActivityService $activityService, NotificationEngineService $engine): void
    {
        CompanyQuotation::query()
            ->with('assignedTo.user')
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->whereIn('status', array_map(fn ($s) => $s->value, self::EXPIRABLE_STATUSES))
            ->chunkById(100, function ($quotations) use ($activityService, $engine) {
                foreach ($quotations as $quotation) {
                    $previous = $quotation->status instanceof QuotationStatus
                        ? $quotation->status->value
                        : (string) $quotation->status;

                    $quotation->update(['status' => QuotationStatus::Expired]);

                    $activityService->logActivity(
                        $quotation,
                        'expired',
                        "Quotation {$quotation->quotation_number} expired automatically on ".
                        now()->format('d M Y').
                        " (valid until {$quotation->valid_until?->format('d M Y')}, previous status: {$previous})."
                    );

                    $this->notifyOwner($engine, $quotation, $previous);
                }
            });
    }

    /**
     * Tell whoever owns the quotation that it has just expired.
     *
     * `assignedTo` is a CompanyEmployee, not a User, and the notification
     * engine notifies users — so the hop through `user` is required, not
     * incidental. An employee record with no linked user account, or a
     * quotation with no owner at all, has nobody to tell; the activity log
     * still records the change, so nothing is lost when this returns early.
     */
    protected function notifyOwner(NotificationEngineService $engine, CompanyQuotation $quotation, string $previous): void
    {
        $owner = $quotation->assignedTo?->user;

        if (! $owner) {
            return;
        }

        $engine->send($owner, new SendNotificationDTO(
            title: 'Quotation expired',
            message: "{$quotation->quotation_number} passed its validity date and moved from {$previous} to expired.",
            category: NotificationCategory::CRM,
            priority: NotificationPriority::MEDIUM,
            metadata: [
                'type' => 'quotation_expired',
                'quotation_id' => $quotation->id,
                'lead_id' => $quotation->lead_id,
                'previous_status' => $previous,
            ],
        ));
    }
}
