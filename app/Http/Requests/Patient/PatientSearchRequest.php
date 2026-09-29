<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;

class PatientSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'search_by' => ['nullable', 'string', 'in:mobile,name,uhid,case_number'],
        ];
    }

    public function searchQuery(): string
    {
        return trim($this->string('q'));
    }

    public function searchBy(): string
    {
        return $this->string('search_by', 'name');
    }
}
