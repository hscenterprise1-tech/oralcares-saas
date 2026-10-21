<?php

namespace App\Http\Requests\Clinic\Consultation;

use App\Models\ClinicalNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A note on a draft consultation. Authorization is the controller's.
 */
class StoreEncounterNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note_type' => ['required', Rule::in(array_keys(ClinicalNote::TYPES))],
            'content' => ['required', 'string', 'max:10000'],
        ];
    }
}
