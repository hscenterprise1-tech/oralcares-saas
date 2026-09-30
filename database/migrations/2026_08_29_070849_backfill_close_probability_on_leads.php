<?php

use App\Services\CRM\DealStageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `probability_to_close` from each lead's stage.
 *
 * The column shipped fillable but no code path ever wrote it, so every row
 * carried 0. That made the Overview's weighted forecast structurally ₹0 —
 * ₹53L of open pipeline discounted to nothing — and left the probability
 * branch of LeadScoringService permanently dead.
 *
 * From here LeadObserver maintains it on every stage change; this only
 * catches the rows that existed before that writer did.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DealStageService::PROBABILITY as $status => $probability) {
            DB::table('leads')
                ->where('status', $status)
                ->update(['probability_to_close' => $probability]);
        }
    }

    public function down(): void
    {
        // The pre-migration state was a uniform 0, which carried no information.
        DB::table('leads')->update(['probability_to_close' => 0]);
    }
};
