<?php

namespace App\Shared\Search\Requests;

use App\Shared\Search\Enums\SearchModule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SearchQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // We handle fine-grained auth in the controller/policy
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'module' => ['nullable', new Enum(SearchModule::class)],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
