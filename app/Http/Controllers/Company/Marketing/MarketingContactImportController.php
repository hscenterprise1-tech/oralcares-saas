<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMarketingContactImportJob;
use App\Models\MarketingContactImport;
use App\Services\Marketing\ContactImportService;
use App\Services\Marketing\ContactWorkspaceCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketingContactImportController extends Controller
{
    /**
     * Row counts above this threshold are processed in the background instead of
     * inline in the request, so a large file does not hold the connection open.
     */
    protected const QUEUE_THRESHOLD = 500;

    public function __construct(
        protected ContactImportService $importService
    ) {}

    /**
     * List all import batches for this organisation.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $batches = MarketingContactImport::query()
            ->forOrganization($orgId)
            ->with(['creator'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Company/Marketing/Contacts/ImportBatches/Index', [
            'batches' => $batches,
            'workspaceCounts' => ContactWorkspaceCounts::for($orgId),
        ]);
    }

    /**
     * Show a single import batch with its marketing status summary.
     */
    public function show(Request $request, MarketingContactImport $import): Response
    {
        $user = $request->user();

        if ($user?->organization_id && $import->organization_id !== $user->organization_id) {
            abort(403);
        }

        $statusSummary = $import->marketingStatusSummary();

        /*
         * `withLastCampaignStatus` is what lets each row show where that
         * contact stands. Without it the list renders every one of them as
         * "never contacted" while the summary immediately above says otherwise
         * — the same derivation has to feed both.
         */
        /*
         * `members()`, not `contacts()`. The question this page answers is
         * "who did this file contain", which includes the people the importer
         * recognised as already on the list — they were in the file, and
         * dropping them would make the list disagree with the summary above it.
         */
        $contacts = $import->members()
            ->withLastCampaignStatus()
            ->with(['tags'])
            ->orderByDesc('marketing_contacts.created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Company/Marketing/Contacts/ImportBatches/Show', [
            'import' => $import->load('creator'),
            'statusSummary' => $statusSummary,
            'contacts' => $contacts,
        ]);
    }

    /**
     * Download official CSV import template.
     */
    public function template(): StreamedResponse
    {
        $csvContent = $this->importService->getCsvTemplate();

        return response()->streamDownload(function () use ($csvContent) {
            echo $csvContent;
        }, 'oralcares_contacts_template.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="oralcares_contacts_template.csv"',
        ]);
    }

    /**
     * Preview CSV/XLSX file upload, header mapping, and duplicate/validation summary.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx|max:10240',
            'column_mapping' => 'nullable|array',
        ]);

        $user = $request->user();
        $orgId = $user?->organization_id;
        $file = $request->file('file');
        $customMapping = $request->input('column_mapping', []);

        $previewData = $this->importService->previewImport($file, $customMapping, $orgId);

        return response()->json($previewData);
    }

    /**
     * Process confirmed CSV/XLSX import execution.
     *
     * Small batches are processed immediately so the user gets a result right away.
     * Large batches are handed to a queued job and the client polls status().
     */
    public function process(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'required|string',
            'rows.*.phone' => 'required|string',
            'rows.*.organization' => 'nullable|string',
            'rows.*.city' => 'nullable|string',
            'rows.*.state' => 'nullable|string',
            'rows.*.area' => 'nullable|string',
            'rows.*.tags' => 'nullable|string',
            'rows.*.status' => 'nullable|string',
            'original_filename' => 'nullable|string',
            'batch_name' => 'required|string|max:120',
        ]);

        $user = $request->user();
        $orgId = $user?->organization_id;
        $userId = $user?->id;
        $rows = $request->input('rows');

        $import = MarketingContactImport::create([
            'organization_id' => $orgId,
            'created_by_user_id' => $userId,
            'batch_name' => trim($request->input('batch_name')),
            'original_filename' => $request->input('original_filename'),
            'status' => 'pending',
            'total_rows' => count($rows),
        ]);

        if (count($rows) > self::QUEUE_THRESHOLD) {
            ProcessMarketingContactImportJob::dispatch($import->id, $rows, $orgId, $userId);

            return response()->json([
                'success' => true,
                'queued' => true,
                'import_id' => $import->id,
                'message' => 'Large import queued for background processing.',
            ]);
        }

        $import->markProcessing();

        try {
            $result = $this->importService->processImport($rows, $orgId, $userId, $import->id);
            $import->markCompleted($result);
        } catch (\Throwable $e) {
            $import->markFailed($e->getMessage());

            return response()->json([
                'success' => false,
                'queued' => false,
                'message' => 'Import failed: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'queued' => false,
            'import_id' => $import->id,
            'message' => "Import completed: {$result['imported_count']} new contacts added.",
            'result' => $result,
        ]);
    }

    /**
     * Poll the status of a queued import.
     */
    public function status(Request $request, MarketingContactImport $import): JsonResponse
    {
        $user = $request->user();

        if ($user?->organization_id && $import->organization_id !== $user->organization_id) {
            abort(403);
        }

        return response()->json([
            'import_id' => $import->id,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'result' => [
                'imported_count' => $import->imported_count,
                'duplicate_count' => $import->duplicate_count,
                'invalid_count' => $import->invalid_count,
            ],
            'error_message' => $import->error_message,
        ]);
    }
}
