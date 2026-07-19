<?php

namespace App\Services;

use App\Events\XrayCompleted;
use App\Events\XrayReviewed;
use App\Models\XrayRecord;
use App\XrayStatus;
use Illuminate\Support\Facades\DB;

class XrayReviewService
{
    /**
     * Record clinical findings and impression, marking it reviewed.
     */
    public function recordReview(XrayRecord $xray, string $findings, string $impression): void
    {
        DB::transaction(function () use ($xray, $findings, $impression) {
            $xray->update([
                'status' => XrayStatus::Reviewed,
                'findings' => $findings,
                'impression' => $impression,
                'reviewed_at' => now(),
            ]);

            event(new XrayReviewed($xray));
        });
    }

    /**
     * Complete the X-Ray order.
     */
    public function completeOrder(XrayRecord $xray): void
    {
        DB::transaction(function () use ($xray) {
            $xray->update(['status' => XrayStatus::Completed]);

            event(new XrayCompleted($xray));
        });
    }
}
