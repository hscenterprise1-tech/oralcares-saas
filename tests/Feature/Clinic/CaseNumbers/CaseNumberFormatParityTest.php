<?php

use App\Services\Patient\CaseNumberFormatter;
use Carbon\CarbonImmutable;

/**
 * The server formatter is held to the shared case-number cases
 * (`tests/Fixtures/caseNumberFormatCases.json`). The old Settings live
 * preview that mirrored it in the browser was removed with the old Clinic
 * frontend (2026-10-11 reset); a rebuilt preview should be held to the same
 * cases so it never promises a number the server would not issue.
 */
function caseNumberFormatCases(): array
{
    // Resolved from this file rather than `base_path()`: Pest builds
    // datasets before the application boots.
    return json_decode(file_get_contents(dirname(__DIR__, 3).'/Fixtures/caseNumberFormatCases.json'), true, flags: JSON_THROW_ON_ERROR);
}

test('the server formatter matches every shared case', function (array $case) {
    $date = CarbonImmutable::parse($case['date']);
    $startYear = CaseNumberFormatter::periodStartYear($case['config']['year_style'], $date);
    $number = CaseNumberFormatter::format($case['config'], $startYear, $case['sequence']);

    expect([
        'number' => $number,
        'period_key' => CaseNumberFormatter::periodKey($case['config']['year_style'], $date),
        'search_key' => CaseNumberFormatter::searchKey($number),
        'short_label' => CaseNumberFormatter::shortLabel($case['sequence']),
    ])->toBe($case['expected']);
})->with(fn () => collect(caseNumberFormatCases())->mapWithKeys(fn (array $case) => [$case['name'] => [$case]])->all());
