<?php

namespace App\Http\Requests\Master;

use App\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\UserType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['required', 'string', Rule::in(array_keys(Lead::sources()))],
            'status' => ['required', Rule::enum(LeadStatus::class)],
            'assigned_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->whereIn('user_type', [
                    UserType::MasterAdmin->value,
                    UserType::CompanyUser->value,
                ]),
            ],
            'assigned_team_id' => [
                'nullable',
                Rule::exists('teams', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $userId = $this->input('assigned_user_id');
            $teamId = $this->input('assigned_team_id');

            // 1. Exclusive assignment semantics (user OR team, not both)
            if ($userId && $teamId) {
                $validator->errors()->add('assigned_user_id', 'A lead cannot be assigned to both a user and a team simultaneously.');
            }

            // 2. Platform-only context assignment validation (no clinic_user allowed)
            if ($userId) {
                $user = User::find($userId);
                if ($user && $user->user_type === UserType::ClinicUser) {
                    $validator->errors()->add('assigned_user_id', 'CRM records cannot be assigned to clinic users.');
                }
            }
        });
    }
}
