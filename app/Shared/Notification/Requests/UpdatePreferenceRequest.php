<?php

namespace App\Shared\Notification\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Any authenticated user can update their own preferences
    }

    public function rules(): array
    {
        return [
            'clinic_id' => ['nullable', 'exists:clinics,id'],
            'disabled_channels' => ['nullable', 'array'],
            'disabled_channels.*' => ['string'],
            'disabled_categories' => ['nullable', 'array'],
            'disabled_categories.*' => ['string'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i:s'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i:s'],
            'timezone' => ['nullable', 'timezone'],
        ];
    }
}
