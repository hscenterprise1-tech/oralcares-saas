<?php

namespace App\Jobs;

use App\Models\Clinic;
use App\Models\ClinicReportExport;
use App\Services\Reports\ClinicReports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Write one clinic report to a private CSV, on the reports queue, so a big
 * export never holds a web request. The file is only ever served back to
 * the clinic that asked.
 */
class ExportClinicReport implements ShouldQueue
{
    use Queueable;

    /** The most rows one export writes. */
    public const MAX_ROWS = 100000;

    public int $timeout = 600;

    public function __construct(public int $exportId)
    {
        $this->onQueue(config('queue.queues.reports'));
    }

    public function handle(ClinicReports $reports): void
    {
        $export = ClinicReportExport::query()->withoutGlobalScopes()->find($this->exportId);
        if (! $export || $export->status !== 'queued') {
            return;
        }

        try {
            $clinic = Clinic::query()->findOrFail($export->clinic_id);
            $result = $reports->build($clinic, $export->report, $export->filters, self::MAX_ROWS);
            $path = "clinic_{$clinic->id}/reports/{$export->report}-{$export->id}.csv";
            $handle = fopen('php://temp', 'w+');
            fputcsv($handle, array_column($result['columns'], 'label'));
            foreach ($result['rows'] as $row) {
                fputcsv($handle, array_map(fn (array $column) => $this->cell($row[$column['key']] ?? null), $result['columns']));
            }
            rewind($handle);
            Storage::disk('private')->put($path, stream_get_contents($handle));
            fclose($handle);

            $export->update(['status' => 'ready', 'path' => $path, 'rows' => count($result['rows']), 'finished_at' => now()]);
        } catch (Throwable $e) {
            $export->update(['status' => 'failed', 'failure' => mb_substr($e->getMessage(), 0, 255), 'finished_at' => now()]);

            throw $e;
        }
    }

    /** A value as a CSV cell; one that would start a spreadsheet formula is quoted as text. */
    private function cell(mixed $value): string
    {
        $text = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@'], true) && ! is_numeric($text) ? "'".$text : $text;
    }
}
