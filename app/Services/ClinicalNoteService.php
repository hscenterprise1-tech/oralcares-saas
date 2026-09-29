<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\PatientTimelineEvent;
use Illuminate\Validation\ValidationException;

class ClinicalNoteService
{
    public function createDraft(ClinicalEncounter $encounter, array $data): ClinicalNote
    {
        // A completed encounter cannot have new notes unless formally reopened
        if ($encounter->status === 'completed') {
            throw ValidationException::withMessages([
                'encounter' => 'Cannot add notes to a completed encounter.',
            ]);
        }

        return ClinicalNote::create([
            'organization_id' => $encounter->organization_id,
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'author_id' => auth()->id(),
            'note_type' => $data['note_type'] ?? 'general',
            'content' => $data['content'] ?? [],
            'status' => 'draft',
        ]);
    }

    public function updateDraft(ClinicalNote $note, array $data): ClinicalNote
    {
        if ($note->status === 'signed') {
            throw ValidationException::withMessages([
                'note' => 'Cannot edit a signed clinical note.',
            ]);
        }

        $note->update([
            'content' => array_merge((array) $note->content, $data['content'] ?? []),
            'note_type' => $data['note_type'] ?? $note->note_type,
        ]);

        return $note;
    }

    public function signNote(ClinicalNote $note): ClinicalNote
    {
        if ($note->status === 'signed') {
            return $note;
        }

        $note->update([
            'status' => 'signed',
            'signed_at' => now(),
        ]);

        // Log timeline event
        PatientTimelineEvent::create([
            'organization_id' => $note->organization_id,
            'patient_id' => $note->patient_id,
            'event_type' => 'note_signed',
            'context' => [
                'note_id' => $note->id,
                'encounter_id' => $note->encounter_id,
            ],
            'actor_id' => auth()->id() ?? null,
        ]);

        return $note;
    }
}
