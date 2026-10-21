<?php

namespace App\Http\Requests\Clinic\Consultation;

use App\Models\ClinicalOrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Investigations ordered from a consultation. A test chosen from the
 * clinic's list is checked to be this clinic's in `ClinicalOrderService`.
 * Authorization is the controller's (`ClinicalOrderPolicy::place`).
 */
class PlaceOrderRequest extends FormRequest
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
            'priority' => ['required', Rule::in(['routine', 'urgent', 'stat'])],
            'clinical_instructions' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.investigation_id' => ['nullable', 'integer'],
            'items.*.item_name' => ['required_without:items.*.investigation_id', 'nullable', 'string', 'max:150'],
            'items.*.category' => ['required', Rule::in(array_keys(ClinicalOrderItem::CATEGORIES))],
            'items.*.body_site' => ['nullable', 'string', 'max:100'],
            'items.*.external_provider' => ['nullable', 'string', 'max:150'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one investigation.',
            'items.*.item_name.required_without' => 'Name each investigation.',
        ];
    }
}
