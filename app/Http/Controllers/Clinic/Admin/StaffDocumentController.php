<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClinicEmployee;
use App\Models\StaffDocument;
use App\Models\StaffDocumentRequirement;
use App\Models\StaffDocumentType;
use App\Services\ClinicContext;
use App\Services\Security\Malware\UploadRejectedException;
use App\Services\Security\SecurityAudit;
use App\Services\Staff\StaffDirectory;
use App\Services\Staff\StaffDocumentChecklist;
use App\Services\Staff\StaffDocumentService;
use App\Services\Staff\StaffProfileView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff documents: upload, authorised download, review, and the clinic's
 * document checklist. Files live on the private disk and are only ever
 * streamed through `download()`, after the same checks as viewing the
 * staff member.
 */
class StaffDocumentController extends Controller
{
    public function __construct(
        protected ClinicContext $context,
        protected StaffDirectory $directory,
        protected StaffDocumentService $documents,
        protected StaffDocumentChecklist $checklist,
        protected SecurityAudit $audit,
    ) {}

    public function store(Request $request, ClinicEmployee $employee): RedirectResponse
    {
        $this->authorizeStaff($employee);
        $clinic = $this->context->clinic();
        $this->checklist->ensureCatalogue($clinic);

        $validated = $request->validate([
            'document_type_id' => ['required', 'integer', Rule::exists('staff_document_types', 'id')->where('clinic_id', $clinic->id)->where('is_active', true)],
            'file' => ['required', 'file', 'mimes:'.implode(',', StaffDocumentService::MIMES), 'max:'.StaffDocumentService::MAX_KB],
            'title' => ['nullable', 'string', 'max:160'],
            'issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $type = StaffDocumentType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($validated['document_type_id']);

        try {
            $this->documents->upload($clinic, $employee, $type, $request->file('file'), $validated, $request->user());
        } catch (UploadRejectedException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('success', "{$type->name} uploaded. It is waiting for verification.");
    }

    public function download(ClinicEmployee $employee, int $document): StreamedResponse
    {
        $this->authorizeStaff($employee);
        $record = $this->documentOf($employee, $document);
        abort_unless(Storage::disk('private')->exists($record->file_path), 404);

        $this->audit->record('staff_document.downloaded', $record, ['employee_id' => $employee->id]);

        return Storage::disk('private')->download($record->file_path, $this->safeName($record));
    }

    public function verify(Request $request, ClinicEmployee $employee, int $document): RedirectResponse
    {
        $this->authorizeStaff($employee);
        $this->documents->verify($this->documentOf($employee, $document), $request->user());

        return back()->with('success', 'Document verified.');
    }

    /**
     * Reject a document, optionally asking for a replacement. The document
     * stays on file either way.
     */
    public function reject(Request $request, ClinicEmployee $employee, int $document): RedirectResponse
    {
        $this->authorizeStaff($employee);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'request_replacement' => ['nullable', 'boolean'],
        ]);

        $this->documents->reject($this->documentOf($employee, $document), $validated['reason'], (bool) ($validated['request_replacement'] ?? false), $request->user());

        return back()->with('success', ($validated['request_replacement'] ?? false) ? 'Replacement requested.' : 'Document rejected.');
    }

    public function settings(StaffProfileView $profiles): Response
    {
        return Inertia::render('ClinicPortal/Staff/DocumentSettings', [
            'types' => $this->checklist->matrix($this->context->clinic()),
            'staffTypes' => $profiles->staffTypeOptions(),
            'levels' => StaffDocumentRequirement::LEVELS,
            'audiences' => StaffDocumentType::AUDIENCES,
            'links' => [
                'back' => route('admin.employees.index'),
                'update' => route('admin.staff-documents.update'),
                'types' => route('admin.staff-documents.types.store'),
            ],
        ]);
    }

    /**
     * Save the checklist: each type's requirement per staff type, and
     * whether the type is in use.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $clinic = $this->context->clinic();
        $this->checklist->ensureCatalogue($clinic);
        $staffTypes = array_keys(StaffDirectory::staffTypes());

        $validated = $request->validate([
            'types' => ['required', 'array', 'max:100'],
            'types.*.id' => ['required', 'integer', Rule::exists('staff_document_types', 'id')->where('clinic_id', $clinic->id)],
            'types.*.is_active' => ['required', 'boolean'],
            'types.*.has_expiry' => ['required', 'boolean'],
            'types.*.requirements' => ['required', 'array'],
            'types.*.requirements.*' => ['required', Rule::in(StaffDocumentRequirement::LEVELS)],
        ]);

        DB::transaction(function () use ($validated, $clinic, $staffTypes) {
            foreach ($validated['types'] as $row) {
                $type = StaffDocumentType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($row['id']);
                $type->update(['is_active' => $row['is_active'], 'has_expiry' => $row['has_expiry']]);

                foreach ($row['requirements'] as $staffType => $level) {
                    if (! in_array($staffType, $staffTypes, true)) {
                        continue;
                    }
                    StaffDocumentRequirement::withoutGlobalScopes()->updateOrCreate(
                        ['staff_document_type_id' => $type->id, 'staff_type' => $staffType],
                        ['clinic_id' => $clinic->id, 'requirement' => $level],
                    );
                }
            }
        });

        $this->audit->record('staff_document.checklist_updated', null, ['types' => count($validated['types'])]);

        return back()->with('success', 'Document checklist saved.');
    }

    public function storeType(Request $request): RedirectResponse
    {
        $clinic = $this->context->clinic();
        $this->checklist->ensureCatalogue($clinic);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'audience' => ['required', Rule::in(StaffDocumentType::AUDIENCES)],
            'has_expiry' => ['required', 'boolean'],
        ]);

        $key = Str::slug($validated['name'], '_');
        if (StaffDocumentType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('key', $key)->exists()) {
            return back()->withErrors(['name' => 'A document type with this name already exists.']);
        }

        StaffDocumentType::create([
            'clinic_id' => $clinic->id,
            'key' => $key,
            'name' => $validated['name'],
            'audience' => $validated['audience'],
            'has_expiry' => $validated['has_expiry'],
            'is_active' => true,
            'position' => (int) StaffDocumentType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->max('position') + 1,
        ]);

        return back()->with('success', "{$validated['name']} added to the document types.");
    }

    private function authorizeStaff(ClinicEmployee $employee): void
    {
        abort_unless($this->directory->canSee($employee, $this->context->clinic(), $this->context->membership()), 403);
    }

    private function documentOf(ClinicEmployee $employee, int $document): StaffDocument
    {
        return StaffDocument::withoutGlobalScopes()
            ->where('clinic_id', $this->context->clinic()->id)
            ->where('clinic_employee_id', $employee->id)
            ->findOrFail($document);
    }

    private function safeName(StaffDocument $document): string
    {
        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $base = Str::of(pathinfo($document->file_name, PATHINFO_FILENAME))->ascii()->replaceMatches('/[^A-Za-z0-9 ._-]+/', '')->trim()->limit(120, '');

        return ($base->isEmpty() ? 'document' : (string) $base).($extension ? ".{$extension}" : '');
    }
}
