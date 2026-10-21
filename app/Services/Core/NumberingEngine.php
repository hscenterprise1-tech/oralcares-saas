<?php

namespace App\Services\Core;

use App\Models\Branch;
use App\Models\NumberingSequence;
use App\Models\Organization;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
    public function generate(string $entityType, Organization $organization, ?Branch $branch = null, array $defaults = [], ?CarbonInterface $at = null): string
    {
        return $this->format($this->issue($entityType, $organization, $branch, $defaults, $at));
    }

    /**
     * Take the next value of a sequence and return the locked row holding it.
     *
     * The row stays locked until the caller's transaction commits, so a
     * document saved in the same transaction can never be numbered out of
     * order with a concurrent one. `$at` is the moment the number is issued
     * for (a clinic-local "now" for clinic documents) and decides resets.
     *
     * @param  array{prefix?: ?string, padding?: int, reset_frequency?: ?string}  $defaults
     */
    public function issue(string $entityType, Organization $organization, ?Branch $branch = null, array $defaults = [], ?CarbonInterface $at = null): NumberingSequence
    {
        $at = CarbonImmutable::instance($at ?? Carbon::now());

        return DB::transaction(function () use ($entityType, $organization, $branch, $defaults, $at) {
            /*
             * Create the sequence if it does not exist yet, then lock the one
             * row. `firstOrCreate` raced: two first requests both saw no row
             * and both inserted one. The insert is now ignored when the scope
             * already has a sequence (unique `num_seq_scope_unique`), so every
             * caller ends up locking the same row.
             */
            $now = Carbon::now();
            NumberingSequence::query()->insertOrIgnore([
                'organization_id' => $organization->id,
                'branch_id' => $branch?->id,
                'entity_type' => $entityType,
                'prefix' => $defaults['prefix'] ?? null,
                'padding' => $defaults['padding'] ?? 0,
                'reset_frequency' => $defaults['reset_frequency'] ?? null,
                'current_number' => 0,
                'last_reset_date' => $at->startOfDay(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sequence = NumberingSequence::query()
                ->where('organization_id', $organization->id)
                ->where('branch_scope', $branch?->id ?? 0)
                ->where('entity_type', $entityType)
                ->lockForUpdate()
                ->firstOrFail();

            // A sequence created elsewhere (onboarding seeds a branch's
            // invoice prefix) adopts the caller's reset rule and padding.
            if (! $sequence->reset_frequency && ! empty($defaults['reset_frequency'])) {
                $sequence->reset_frequency = $defaults['reset_frequency'];
                $sequence->last_reset_date ??= $at->startOfDay();
            }
            if (! $sequence->padding && ! empty($defaults['padding'])) {
                $sequence->padding = $defaults['padding'];
            }

            if ($this->shouldReset($sequence, $at)) {
                $sequence->current_number = 0;
                $sequence->last_reset_date = $at->startOfDay();
            }

            $sequence->current_number += 1;
            $sequence->save();

            return $sequence;
        });
    }

    /**
     * The first day of the financial year that `$at` falls in.
     */
    public static function financialYearStart(CarbonInterface $at): CarbonImmutable
    {
        $month = (int) config('clinic_numbering.financial_year_start_month', 4);
        $at = CarbonImmutable::instance($at);
        $year = $at->month >= $month ? $at->year : $at->year - 1;

        return CarbonImmutable::create($year, $month, 1, 0, 0, 0, $at->getTimezone());
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
    protected function shouldReset(NumberingSequence $sequence, CarbonImmutable $at): bool
    {
        if (! $sequence->reset_frequency || ! $sequence->last_reset_date) {
            return false;
        }

        $periodStart = match ($sequence->reset_frequency) {
            'calendar_yearly' => $at->startOfYear(),
            'financial_yearly' => self::financialYearStart($at),
            'monthly' => $at->startOfMonth(),
            default => null,
        };

        return $periodStart !== null && $sequence->last_reset_date->lt($periodStart->startOfDay());
    }
}
