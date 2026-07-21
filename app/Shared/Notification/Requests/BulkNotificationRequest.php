<?php

namespace App\Shared\Notification\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Handled by controller policy
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['uuid'],
            'action' => ['required', 'in:read,archive,delete'],
        ];
    }
}
