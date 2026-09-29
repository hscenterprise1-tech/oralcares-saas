<?php

namespace App\Services\Patient;

use App\Models\CaseNumber;
use App\Models\CaseNumberHistory;
use App\Models\CaseNumberSequence;
use App\Models\Clinic;
use App\Models\ClinicSettingAudit;
use App\Models\User;
use App\Support\ClinicClock;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Each clinic's case-number format — the one place the generator, the
 * Settings page and its validation read it from (same pattern as
 * `PatientFieldConfigService`).
 *
 * Stored on `clinics.case_number_config` as `{active, pending}`:
 * - prefix, separator, digits and start_number apply to the next number
 *   issued;
 * - show_year, year_style and reset_yearly change what a *period* is, so
 *   they wait in `pending` until the start of the next period
 *   (`effective_from`). A number already issued never changes either way.
 *
 * @phpstan-type CaseNumberFormat array{prefix: string, show_year: bool, year_style: string, separator: string, digits: int, start_number: int, reset_yearly: bool}
 * @phpstan-type PendingChange array{show_year: bool, year_style: string, reset_yearly: bool, effective_from: string}
 */
class CaseNumberConfigService
{
    public const AUDIT_SECTION = 'case_number';

    /**
     * @var CaseNumberFormat
     */
    public const DEFAULTS = [
        'prefix' => 'OPD',
        'show_year' => true,
        'year_style' => 'YYYY',
        'separator' => '-',
        'digits' => 6,
        'start_number' => 1,
        'reset_yearly' => true,
    ];

    public const PERIOD_FIELDS = ['show_year', 'year_style', 'reset_yearly'];

    /**
     * The format in force on `$date`: the active format with any pending
     * period change folded in once its date has arrived.
     *
     * @return CaseNumberFormat
     */
    public function resolve(Clinic $clinic, ?CarbonInterface $date = null): array
    {
        return $this->settled($clinic, $date ?? ClinicClock::now())['active'];
    }

    /**
     * Everything the Settings section shows.
     *
     * @return array{active: CaseNumberFormat, pending: PendingChange|null, today: string, next_period_start: string, highest_issued: int}
     */
    public function forSettings(Clinic $clinic): array
    {
        $today = ClinicClock::today();
        $settled = $this->settled($clinic, $today);

        return [
            'active' => $settled['active'],
            'pending' => $settled['pending'],
            'today' => $today->toDateString(),
            'next_period_start' => CaseNumberFormatter::nextPeriodStart($settled['active']['year_style'], $today)->toDateString(),
            'highest_issued' => $this->highestIssuedSequence($clinic),
        ];
    }

    /**
     * The highest sequence already issued on the counter the next number
     * will be drawn from — `start_number` may never go at or below it.
     */
    public function highestIssuedSequence(Clinic $clinic): int
    {
        $today = ClinicClock::today();
        $format = $this->resolve($clinic, $today);
        $counterKey = CaseNumberFormatter::counterKey($format, CaseNumberFormatter::periodKey($format['year_style'], $today));

        return (int) max(
            CaseNumberSequence::where('clinic_id', $clinic->id)->where('counter_key', $counterKey)->value('last_sequence') ?? 0,
            CaseNumber::where('clinic_id', $clinic->id)->where('counter_key', $counterKey)->max('sequence') ?? 0,
        );
    }

    /**
     * The highest sequence the clinic has ever issued on any counter. A new
     * lifetime counter starts above it so no number is ever repeated.
     */
    public function highestSequenceEver(Clinic $clinic): int
    {
        return (int) max(
            CaseNumber::where('clinic_id', $clinic->id)->max('sequence') ?? 0,
            CaseNumberHistory::where('clinic_id', $clinic->id)->max('sequence') ?? 0,
        );
    }

    /**
     * @param  array{prefix: string, show_year: bool, year_style: string, separator: string|null, digits: int, start_number: int, reset_yearly: bool}  $input
     */
    public function update(Clinic $clinic, array $input, ?User $actor): void
    {
        DB::transaction(function () use ($clinic, $input, $actor): void {
            $clinic = Clinic::withoutGlobalScopes()->lockForUpdate()->findOrFail($clinic->id);
            $before = $this->stored($clinic);
            $today = ClinicClock::today();
            $settled = $this->settled($clinic, $today);

            $active = array_merge($settled['active'], [
                'prefix' => strtoupper($input['prefix']),
                'separator' => $input['separator'] ?? '',
                'digits' => (int) $input['digits'],
                'start_number' => (int) $input['start_number'],
            ]);

            $requestedPeriod = [
                'show_year' => (bool) $input['show_year'],
                'year_style' => $input['year_style'],
                'reset_yearly' => (bool) $input['reset_yearly'],
            ];

            $pending = null;
            if ($requestedPeriod !== array_intersect_key($active, $requestedPeriod)) {
                $pending = $requestedPeriod + [
                    'effective_from' => CaseNumberFormatter::nextPeriodStart($active['year_style'], $today)->toDateString(),
                ];
            }

            $after = ['active' => $active, 'pending' => $pending];

            if ($after === $before) {
                return;
            }

            $clinic->update(['case_number_config' => $after]);
            $this->applyStartNumber($clinic, $active, $today);

            ClinicSettingAudit::create([
                'clinic_id' => $clinic->id,
                'user_id' => $actor?->id,
                'section' => self::AUDIT_SECTION,
                'old_values' => $before,
                'new_values' => $after,
            ]);
        });
    }

    /**
     * Moves the live counter up to `start_number - 1` when the clinic asks
     * numbering to jump ahead. Never moves a counter backwards.
     *
     * @param  CaseNumberFormat  $active
     */
    private function applyStartNumber(Clinic $clinic, array $active, CarbonInterface $today): void
    {
        $counterKey = CaseNumberFormatter::counterKey($active, CaseNumberFormatter::periodKey($active['year_style'], $today));

        $counter = CaseNumberSequence::where('clinic_id', $clinic->id)->where('counter_key', $counterKey)->lockForUpdate()->first();

        if ($counter && $counter->last_sequence < $active['start_number'] - 1) {
            $counter->update(['last_sequence' => $active['start_number'] - 1]);
        }
    }

    /**
     * @return array{active: CaseNumberFormat, pending: PendingChange|null}
     */
    private function settled(Clinic $clinic, CarbonInterface $date): array
    {
        $stored = $this->stored($clinic);

        if ($stored['pending'] && $date->toDateString() >= $stored['pending']['effective_from']) {
            return [
                'active' => array_merge($stored['active'], array_intersect_key($stored['pending'], array_flip(self::PERIOD_FIELDS))),
                'pending' => null,
            ];
        }

        return $stored;
    }

    /**
     * The stored config with anything missing or invalid replaced by its
     * default, so a malformed value can never break numbering.
     *
     * @return array{active: CaseNumberFormat, pending: PendingChange|null}
     */
    private function stored(Clinic $clinic): array
    {
        $raw = $clinic->case_number_config ?? [];
        $active = $this->sanitize($raw['active'] ?? []);

        $pending = null;
        if (is_array($raw['pending'] ?? null) && isset($raw['pending']['effective_from'])) {
            $period = $this->sanitize(array_merge($active, $raw['pending']));
            $pending = array_intersect_key($period, array_flip(self::PERIOD_FIELDS)) + [
                'effective_from' => (string) $raw['pending']['effective_from'],
            ];
        }

        return ['active' => $active, 'pending' => $pending];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return CaseNumberFormat
     */
    private function sanitize(array $values): array
    {
        $prefix = (string) ($values['prefix'] ?? '');
        $digits = (int) ($values['digits'] ?? 0);
        $start = (int) ($values['start_number'] ?? 0);
        $yearStyle = $values['year_style'] ?? null;
        $separator = $values['separator'] ?? null;

        return [
            'prefix' => preg_match('/^[A-Za-z0-9]{1,10}$/', $prefix) ? strtoupper($prefix) : self::DEFAULTS['prefix'],
            'show_year' => (bool) ($values['show_year'] ?? self::DEFAULTS['show_year']),
            'year_style' => in_array($yearStyle, CaseNumberFormatter::YEAR_STYLES, true) ? $yearStyle : self::DEFAULTS['year_style'],
            'separator' => in_array($separator, CaseNumberFormatter::SEPARATORS, true) ? $separator : self::DEFAULTS['separator'],
            'digits' => $digits >= 4 && $digits <= 8 ? $digits : self::DEFAULTS['digits'],
            'start_number' => $start >= 1 ? $start : self::DEFAULTS['start_number'],
            'reset_yearly' => (bool) ($values['reset_yearly'] ?? self::DEFAULTS['reset_yearly']),
        ];
    }
}
