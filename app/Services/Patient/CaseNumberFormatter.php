<?php

namespace App\Services\Patient;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure case-number formatting: periods, labels and search keys. No database.
 *
 * The browser preview in `Admin/Settings/caseNumberFormat.ts` mirrors this
 * class; both are checked against the shared cases in
 * `caseNumberFormatCases.json`, so the preview can never promise a number the
 * server would not issue.
 *
 * A "period" is the span a patient keeps one case number for. `FY` and
 * `FY_SHORT` run April–March, `YYYY` and `YY` run January–December. A period
 * is identified by the calendar year it starts in: FY 2026-27 and calendar
 * 2026 both carry the year label 2026 (`FY2026` / `CY2026` keys).
 */
class CaseNumberFormatter
{
    public const YEAR_STYLES = ['YYYY', 'YY', 'FY', 'FY_SHORT'];

    public const SEPARATORS = ['-', '/', '.', ''];

    public const LIFETIME_COUNTER = 'LIFETIME';

    public static function isFinancialYear(string $yearStyle): bool
    {
        return in_array($yearStyle, ['FY', 'FY_SHORT'], true);
    }

    public static function periodStartYear(string $yearStyle, CarbonInterface $date): int
    {
        if (self::isFinancialYear($yearStyle)) {
            return $date->month >= 4 ? $date->year : $date->year - 1;
        }

        return $date->year;
    }

    public static function periodKey(string $yearStyle, CarbonInterface $date): string
    {
        return (self::isFinancialYear($yearStyle) ? 'FY' : 'CY').self::periodStartYear($yearStyle, $date);
    }

    /**
     * The first day of the period after the one `$date` falls in.
     */
    public static function nextPeriodStart(string $yearStyle, CarbonInterface $date): CarbonImmutable
    {
        $startYear = self::periodStartYear($yearStyle, $date);
        $month = self::isFinancialYear($yearStyle) ? 4 : 1;

        return CarbonImmutable::create($startYear + 1, $month, 1)->startOfDay();
    }

    /**
     * @param  array{reset_yearly: bool}  $config
     */
    public static function counterKey(array $config, string $periodKey): string
    {
        return $config['reset_yearly'] ? $periodKey : self::LIFETIME_COUNTER;
    }

    public static function yearLabel(string $yearStyle, int $startYear): string
    {
        $nextShort = str_pad((string) (($startYear + 1) % 100), 2, '0', STR_PAD_LEFT);
        $short = str_pad((string) ($startYear % 100), 2, '0', STR_PAD_LEFT);

        return match ($yearStyle) {
            'YY' => $short,
            'FY' => "{$startYear}-{$nextShort}",
            'FY_SHORT' => "{$short}-{$nextShort}",
            default => (string) $startYear,
        };
    }

    /**
     * @param  array{prefix: string, show_year: bool, year_style: string, separator: string, digits: int}  $config
     */
    public static function format(array $config, int $startYear, int $sequence): string
    {
        $parts = [$config['prefix']];

        if ($config['show_year']) {
            $parts[] = self::yearLabel($config['year_style'], $startYear);
        }

        $parts[] = str_pad((string) $sequence, $config['digits'], '0', STR_PAD_LEFT);

        return implode($config['separator'], $parts);
    }

    /**
     * Upper-cased with everything but letters and digits removed, so
     * "DC/26-27/000124", "dc 26-27 000124" and "DC2627000124" all match.
     */
    public static function searchKey(string $caseNumber): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $caseNumber));
    }

    /**
     * The last four digits of the sequence — what the queue, Now/Next card
     * and TV show next to the patient's name. Display only, never searched.
     */
    public static function shortLabel(int $sequence): string
    {
        return str_pad((string) ($sequence % 10000), 4, '0', STR_PAD_LEFT);
    }
}
