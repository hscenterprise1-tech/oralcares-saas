<?php

namespace App\Http\Requests\Marketing;

use App\Models\MarketingContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Segment's rules are the same named fields Contacts filters by, so this is
 * plain declarative validation rather than the custom whitelist check the old
 * free-form rule engine needed — there is no field/operator combination left
 * to validate, only known keys with known shapes.
 */
class StoreAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:active,paused',
            'rules' => 'present|array',
            'rules.state' => 'nullable|string|max:255',
            'rules.city' => 'nullable|string|max:255',
            'rules.area' => 'nullable|string|max:255',
            'rules.organization' => 'nullable|string|max:255',
            'rules.tags' => 'nullable|array',
            'rules.tags.*' => 'string|max:100',
            'rules.import_batch' => 'nullable|string|max:50',
            'rules.imported_from' => 'nullable|date',
            'rules.imported_to' => 'nullable|date',
            'rules.status' => [
                'nullable',
                'string',
                Rule::in([...MarketingContact::CAMPAIGN_STATUSES, ...MarketingContact::COMPLIANCE_STATUSES]),
            ],
        ];
    }
}
