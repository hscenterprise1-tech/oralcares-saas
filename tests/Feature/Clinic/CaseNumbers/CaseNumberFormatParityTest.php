<?php

use App\Services\Patient\CaseNumberFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

/**
 * The server formatter and the Settings live preview
 * (`caseNumberFormat.ts`) are both held to the same cases, so the preview
 * never promises a number the server would not issue.
 */
function caseNumberPreviewDirectory(): string
{
    // Resolved from this file rather than `resource_path()`: Pest builds
    // datasets before the application boots.
    return dirname(__DIR__, 4).'/resources/js/pages/Clinic/Admin/Settings';
}

function caseNumberFormatCases(): array
{
    return json_decode(file_get_contents(caseNumberPreviewDirectory().'/caseNumberFormatCases.json'), true, flags: JSON_THROW_ON_ERROR);
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

test('the browser preview matches every shared case', function () {
    if (! Process::run(['node', '--version'])->successful()) {
        $this->markTestSkipped('Node is not available to run the preview formatter.');
    }

    $module = 'file:///'.ltrim(str_replace('\\', '/', caseNumberPreviewDirectory().'/caseNumberFormat.ts'), '/');
    $cases = json_encode(caseNumberFormatCases());
    $script = <<<JS
        const f = await import('{$module}');
        const cases = JSON.parse(process.argv[1]);
        console.log(JSON.stringify(cases.map((c) => {
            const number = f.formatCaseNumber(c.config, c.date, c.sequence);
            return { number, period_key: f.periodKey(c.config.year_style, c.date), search_key: f.searchKey(number), short_label: f.shortLabel(c.sequence) };
        })));
        JS;

    $result = Process::run(['node', '--input-type=module', '--no-warnings', '-e', $script, $cases]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(json_decode($result->output(), true))->toBe(array_column(caseNumberFormatCases(), 'expected'));
});
