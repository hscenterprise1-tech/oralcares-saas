<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Policies\PatientPolicy;
use App\Services\ClinicalNoteService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClinicalNoteController extends Controller
{
    public function index(ClinicalEncounter $encounter)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $notes = $encounter->clinicalNotes()->with('author')->latest()->get();

        return Inertia::render('Clinical/Encounters/Notes/Index', [
            'encounter' => $encounter,
            'notes' => $notes,
        ]);
    }

    public function store(Request $request, ClinicalEncounter $encounter, ClinicalNoteService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);
        abort_unless(app(PatientPolicy::class)->writeClinicalData(auth()->user(), $encounter->patient), 403, 'You are not authorized to write clinical data for this patient.');

        $validated = $request->validate([
            'note_type' => 'required|string',
            'content' => 'required|array',
        ]);

        $service->createDraft($encounter, $validated);

        return redirect()->back()->with('success', 'Note draft created.');
    }

    public function update(Request $request, ClinicalEncounter $encounter, ClinicalNote $note, ClinicalNoteService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);
        abort_if($note->encounter_id !== $encounter->id, 403);
        abort_unless(app(PatientPolicy::class)->writeClinicalData(auth()->user(), $encounter->patient), 403, 'You are not authorized to write clinical data for this patient.');

        $validated = $request->validate([
            'note_type' => 'nullable|string',
            'content' => 'nullable|array',
        ]);

        $service->updateDraft($note, $validated);

        return redirect()->back()->with('success', 'Note updated.');
    }

    public function sign(ClinicalEncounter $encounter, ClinicalNote $note, ClinicalNoteService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);
        abort_if($note->encounter_id !== $encounter->id, 403);
        abort_unless(app(PatientPolicy::class)->writeClinicalData(auth()->user(), $encounter->patient), 403, 'You are not authorized to write clinical data for this patient.');

        $service->signNote($note);

        return redirect()->back()->with('success', 'Note signed successfully.');
    }
}
