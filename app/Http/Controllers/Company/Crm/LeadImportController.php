<?php

namespace App\Http\Controllers\Company\Crm;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLeadImportJob;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Services\CRM\LeadImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Bulk lead creation from a CSV file — see `LeadImportService` for what it
 * does and doesn't do. No index/show pages: this is reached from a modal on
 * Deals, not a destination of its own, which is why status() is the only GET.
 */
class LeadImportController extends Controller
{
    /**
     * Row counts above this threshold are processed in the background
     * instead of inline in the request — the same threshold and reasoning
     * as Marketing's contact import.
     */
    protected const QUEUE_THRESHOLD = 500;

    public function __construct(protected LeadImportService $importer) {}

    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $orgId = $request->user()?->resolveOrganizationId();

        return response()->json($this->importer->previewImport($request->file('file'), $orgId));
    }

    public function process(Request $request): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.company_name' => 'required|string',
            'rows.*.contact_name' => 'required|string',
            'rows.*.phone' => 'required|string',
            'rows.*.city' => 'required|string',
            'rows.*.healthcare_specialty' => 'required|string',
            'rows.*.email' => 'nullable|string',
            'rows.*.status' => 'nullable|string',
            'rows.*.row_number' => 'nullable|integer',
            'original_filename' => 'nullable|string',
        ]);

        $user = $request->user();
        $orgId = $user?->resolveOrganizationId();
        $rows = $request->input('rows');

        $import = LeadImport::create([
            'organization_id' => $orgId,
            'created_by_user_id' => $user?->id,
            'original_filename' => $request->input('original_filename'),
            'status' => LeadImport::STATUS_PENDING,
            'total_rows' => count($rows),
        ]);

        if (count($rows) > self::QUEUE_THRESHOLD) {
            ProcessLeadImportJob::dispatch($import->id, $rows, $orgId, $user?->id);

            return response()->json([
                'queued' => true,
                'import_id' => $import->id,
            ]);
        }

        $import->markProcessing();

        try {
            $result = $this->importer->processImport($import, $rows, $orgId, $user);
            $import->markCompleted($result);
        } catch (\Throwable $e) {
            $import->markFailed($e->getMessage());
        }

        return response()->json([
            'queued' => false,
            'import_id' => $import->id,
            'status' => $import->fresh()->status,
            'result' => [
                'imported_count' => $import->imported_count,
                'duplicate_count' => $import->duplicate_count,
                'invalid_count' => $import->invalid_count,
            ],
        ]);
    }

    public function status(Request $request, LeadImport $import): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        $orgId = $request->user()?->resolveOrganizationId();

        /*
         * Fail closed, matching `CrmScope::constrainToTenant()`'s convention:
         * an unresolvable tenant refuses rather than skipping the check.
         */
        if (! $orgId || (int) $import->organization_id !== (int) $orgId) {
            abort(404);
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
