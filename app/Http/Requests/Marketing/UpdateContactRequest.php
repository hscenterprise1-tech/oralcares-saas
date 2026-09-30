<?php

namespace App\Http\Requests\Marketing;

use App\Models\MarketingContact;
use App\Services\Marketing\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'organization' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'marketing_status' => 'required|string|in:active,opted_out,suppressed',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $phone = $this->input('phone');
            if (empty($phone)) {
                return;
            }

            if (! PhoneNormalizer::isValid($phone)) {
                $v->errors()->add('phone', 'Invalid phone number format.');

                return;
            }

            $normalized = PhoneNormalizer::normalize($phone);
            $orgId = auth()->user()?->organization_id;
            $contactId = $this->route('contact');

            if ($contactId instanceof MarketingContact) {
                $contactId = $contactId->id;
            }

            $exists = MarketingContact::query()
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->where('normalized_phone', $normalized)
                ->where('id', '!=', $contactId)
                ->exists();

            if ($exists) {
                $v->errors()->add('phone', 'A contact with this phone number already exists.');
            }
        });
    }
}
