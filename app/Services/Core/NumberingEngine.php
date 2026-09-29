<?php

namespace App\Services\Core;

use App\Models\Branch;
use App\Models\NumberingSequence;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NumberingEngine
{
    /**
     * Generate the next sequential ID for a given entity type within an organization.
     *
     * @param  string  $entityType  e.g., 'patient', 'invoice'
     * @param  Branch|null  $branch  Optional branch to isolate sequences per branch
     * @param  array  $defaults  Fallback settings: ['prefix' => '', 'padding' => 6]
     * @return string e.g., 'OC-00000001'
     */
    public function generate(string $entityType, Organization $organization, ?Branch $branch = null, array $defaults = []): string
    {
        return DB::transaction(function () use ($entityType, $organization, $branch, $defaults) {
            // Get or create the sequence tracker
            $sequence = NumberingSequence::firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'branch_id' => $branch?->id,
                    'entity_type' => $entityType,
                ],
                [
                    'prefix' => $defaults['prefix'] ?? null,
                    'padding' => $defaults['padding'] ?? 0,
                    'reset_frequency' => $defaults['reset_frequency'] ?? null,
                    'current_number' => 0,
                    'last_reset_date' => Carbon::now()->startOfDay(),
                ]
            );

            // Lock the row for update to prevent race conditions
            $sequence = NumberingSequence::where('id', $sequence->id)->lockForUpdate()->first();

            // Handle Reset if applicable
            if ($this->shouldReset($sequence)) {
                $sequence->current_number = 0;
                $sequence->last_reset_date = Carbon::now()->startOfDay();
            }

            // Increment
            $sequence->current_number += 1;
            $sequence->save();

            return $this->format($sequence);
        });
    }

    /**
     * Format the sequence into the final string.
     */
    protected function format(NumberingSequence $sequence): string
    {
        $prefix = $sequence->prefix ?? '';
        $suffix = $sequence->suffix ?? '';
        $number = (string) $sequence->current_number;

        if ($sequence->padding > 0) {
            $number = str_pad($number, $sequence->padding, '0', STR_PAD_LEFT);
        }

        return $prefix.$number.$suffix;
    }

    /**
     * Check if the sequence should be reset based on its frequency.
     */
    protected function shouldReset(NumberingSequence $sequence): bool
    {
        if (! $sequence->reset_frequency || ! $sequence->last_reset_date) {
            return false;
        }

        $now = Carbon::now();

        if ($sequence->reset_frequency === 'calendar_yearly') {
            $currentYearStart = Carbon::create($now->year, 1, 1)->startOfDay();

            return $sequence->last_reset_date->lt($currentYearStart);
        }

        if ($sequence->reset_frequency === 'financial_yearly') {
            // Assuming Financial Year starts April 1st
            $currentFinancialYearStart = $now->month >= 4
                ? Carbon::create($now->year, 4, 1)->startOfDay()
                : Carbon::create($now->year - 1, 4, 1)->startOfDay();

            return $sequence->last_reset_date->lt($currentFinancialYearStart);
        }

        if ($sequence->reset_frequency === 'monthly') {
            $currentMonthStart = Carbon::create($now->year, $now->month, 1)->startOfDay();

            return $sequence->last_reset_date->lt($currentMonthStart);
        }

        return false;
    }
}
