<?php

namespace App\Http\Controllers\Company\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmDocument;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Lead-scoped document management. Authorization derives from the parent Lead;
 * downloads additionally require only view rights.
 */
class CrmDocumentController extends Controller
{
    public function store(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            /*
             * Presence and size were the only checks here — any file type,
             * including an executable or an HTML/SVG file carrying a script,
             * could be attached to a lead and later downloaded by any
             * teammate sharing that org's CRM access. Restricted to the
             * document types a sales attachment is actually for.
             */
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png'], // Max 10MB
            /*
             * The upload form has always offered a title and the endpoint has
             * always ignored it, so every document was filed under whatever the
             * file happened to be called on somebody's laptop.
             */
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('document');
        $path = $file->store('crm_documents', 'local'); // You might want to use a more secure disk in prod

        $document = $lead->documents()->create([
            'file_name' => ($validated['title'] ?? null) ?: $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size_bytes' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Document uploaded successfully.');
    }

    public function destroy(Lead $lead, CrmDocument $document)
    {
        Gate::authorize('update', $lead);

        if ($document->documentable_id !== $lead->id || $document->documentable_type !== $lead->getMorphClass()) {
            abort(403);
        }

        $document->delete();

        return redirect()->back()->with('success', 'Document deleted successfully.');
    }

    public function download(Lead $lead, CrmDocument $document)
    {
        Gate::authorize('view', $lead);

        if ($document->documentable_id !== $lead->id || $document->documentable_type !== $lead->getMorphClass()) {
            abort(403);
        }

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }
}
