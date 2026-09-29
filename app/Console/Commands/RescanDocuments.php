<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsPerClinic;
use App\Contracts\MalwareScannerInterface;
use App\Models\AttachedDocument;
use App\Models\Clinic;
use App\Services\Security\SecurityAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Scans patient documents that have not passed a scan (`scan_status` other
 * than `clean`), such as files stored before scanning existed or files whose
 * scan failed. A clean verdict makes a document downloadable. An infected one
 * is marked `infected`, stays unservable, and is audited. It is not deleted
 * automatically, so the clinic can review it. A failed scan leaves the status
 * unchanged, to be retried.
 */
class RescanDocuments extends Command
{
    use RunsPerClinic;

    protected $signature = 'documents:rescan
        {--clinic=* : Clinic id(s) to rescan}
        {--all-clinics : Rescan every active clinic}';

    protected $description = 'Malware-scan patient documents that have not passed a scan';

    public function handle(MalwareScannerInterface $scanner, SecurityAudit $audit): int
    {
        $totals = ['clean' => 0, 'infected' => 0, 'error' => 0];

        try {
            $this->forEachSelectedClinic(function (Clinic $clinic) use ($scanner, $audit, &$totals) {
                // Scoped to $clinic by the tenant context, not by this query alone.
                AttachedDocument::query()->where('scan_status', '!=', AttachedDocument::SCAN_CLEAN)->each(
                    function (AttachedDocument $document) use ($scanner, $audit, &$totals) {
                        $disk = Storage::disk('private');

                        if (! $disk->exists($document->file_path)) {
                            $totals['error']++;

                            return;
                        }

                        $local = tempnam(sys_get_temp_dir(), 'rescan');
                        file_put_contents($local, $disk->get($document->file_path));

                        try {
                            $result = $scanner->scan($local);
                        } finally {
                            @unlink($local);
                        }

                        $totals[$result->verdict]++;

                        if ($result->verdict === 'error') {
                            return;
                        }

                        $document->forceFill([
                            'scan_status' => $result->verdict,
                            'scanned_at' => now(),
                            'scanner' => $scanner->name(),
                        ])->save();

                        if ($result->verdict === 'infected') {
                            $audit->record('document.malware_blocked', $document, ['signature' => $result->signature, 'via' => 'rescan']);
                        }
                    }
                );
            });
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Clean: {$totals['clean']}  Infected: {$totals['infected']}  Not scanned (error/missing): {$totals['error']}");

        return $totals['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
