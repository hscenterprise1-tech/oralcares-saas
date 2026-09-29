<?php

namespace App\Http\Requests;

use App\ContactRole;
use App\FacilityType;
use App\HealthcareSpecialty;
use App\LeadPriority;
use App\LeadStatus;
use App\LostReason;
use App\Models\Lead;
use App\UserType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLeadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->user_type === UserType::MasterAdmin || $this->user()->user_type === UserType::CompanyUser;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_name' => 'sometimes|required|string|max:255',
            'contact_name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:255',
            'status' => ['sometimes', 'required', Rule::enum(LeadStatus::class)],
            'priority' => ['sometimes', 'required', Rule::enum(LeadPriority::class)],
            'source' => ['sometimes', 'required', 'string', Rule::in(array_keys(Lead::sources()))],
            'organization_type' => 'nullable|string|max:255',
            'branch_count' => 'nullable|integer|min:1',
            'assigned_user_id' => 'nullable|integer|exists:users,id',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'expected_mrr' => 'nullable|numeric|min:0',
            'expected_arr' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'next_followup_at' => 'nullable|date',
            'marketing_contact_id' => 'nullable|integer',
            'marketing_campaign_id' => 'nullable|integer',
            'competitors' => 'nullable|string',
            'product_interest' => 'nullable|array',
            'product_interest.*' => 'string',

            /*
             * Two questions the schema has always had room for and no form ever
             * asked. `organization_size` feeds the lead score; whether their
             * data has to be migrated is the first thing implementation wants
             * to know and it belongs beside the software being replaced.
             */
            'organization_size' => 'nullable|string|max:40',
            'migration_required' => 'nullable|boolean',
            'lost_reason' => ['nullable', Rule::enum(LostReason::class)],
            'lead_score' => 'nullable|integer|min:0|max:100',

            /*
             * Everything Create Deal captures is editable here.
             *
             * A field the create form asks for and the deal page cannot change
             * is a field nobody will ever correct — and a CRM whose records
             * can only be right on the day they were made is a filing cabinet.
             * The rules below mirror StoreLeadRequest exactly, so the two
             * cannot drift into accepting different things.
             */
            /*
             * What kind of place this is.
             *
             * `leads.facility_type` is cast to `FacilityType`, rendered on the
             * Deals list and the Deal 360 masthead, and was accepted by no
             * request in the application — so the archetype badge could only
             * ever show what a seeder had put there. Validated against the enum
             * rather than as a loose string, because the cast throws a
             * `ValueError` on anything outside it and that is a 500 for a
             * dropdown.
             */
            'facility_type' => ['nullable', Rule::enum(FacilityType::class)],
            'healthcare_specialty' => 'nullable|string|max:255',
            'healthcare_specialties' => 'nullable|array|max:12',
            'healthcare_specialties.*' => [Rule::enum(HealthcareSpecialty::class)],

            'current_software' => 'nullable|string|max:255',
            'deployment_preference' => 'nullable|string|max:255',
            'expected_go_live_date' => 'nullable|date',
            'required_modules' => 'nullable|array|max:40',
            'required_modules.*' => 'string|max:120',
            'required_integrations' => 'nullable|array|max:40',
            'required_integrations.*' => 'string|max:120',

            'assigned_team_id' => 'nullable|integer|exists:teams,id',
            'selected_plan_id' => 'nullable|integer|exists:company_plans,id',
            'probability_to_close' => 'nullable|integer|min:0|max:100',
            'expected_users' => 'nullable|integer|min:0',

            'contact.job_title' => 'nullable|string|max:120',
            'contact.whatsapp' => 'nullable|string|max:32',
            'contact.decision_role' => ['nullable', Rule::enum(ContactRole::class)],

            'branches' => 'nullable|array|max:50',
            'branches.*.name' => 'required_with:branches|string|max:255',
            'branches.*.is_main' => 'nullable|boolean',
            'branches.*.address' => 'nullable|string|max:255',
            'branches.*.area' => 'nullable|string|max:255',
            'branches.*.city' => 'nullable|string|max:255',
            'branches.*.state' => 'nullable|string|max:255',
            'branches.*.country' => 'nullable|string|max:255',
            'branches.*.postal_code' => 'nullable|string|max:20',
            'branches.*.contact_name' => 'nullable|string|max:255',
            'branches.*.phone' => 'nullable|string|max:32',
            'branches.*.email' => 'nullable|email|max:255',

            /*
             * What the site can actually handle.
             *
             * These four columns have existed on `lead_branches` since the
             * healthcare architecture work, are summed into the facility
             * footprint by `DealDomainPresenter::facility()` and rendered as the
             * branch matrix on Deal 360 — and nothing but the preview seeder
             * has ever written them. Every capacity figure a rep has seen in
             * this product was seed data.
             *
             * Nullable rather than defaulted to zero: a physiotherapy chain has
             * no beds and a solo clinic has no theatres. "Nobody asked" and
             * "the answer was none" are different facts, and the presenter
             * already renders them differently.
             */
            'branches.*.doctor_count' => 'nullable|integer|min:0|max:10000',
            'branches.*.bed_count' => 'nullable|integer|min:0|max:10000',
            'branches.*.treatment_station_count' => 'nullable|integer|min:0|max:10000',
            'branches.*.operation_theatre_count' => 'nullable|integer|min:0|max:10000',
        ];
    }

    /**
     * The cross-field rules, identical to the ones creation enforces.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            /*
             * A deal belongs to a person or to a team, never both — the model
             * throws on the combination, and an uncaught throw is a 500 for
             * somebody who simply filled in two dropdowns.
             */
            if (filled($this->input('assigned_user_id')) && filled($this->input('assigned_team_id'))) {
                $validator->errors()->add(
                    'assigned_team_id',
                    'A deal goes to a person or to a team, not both. Clear one of them.'
                );
            }

            $specialties = $this->input('healthcare_specialties');

            if (is_array($specialties)
                && in_array(HealthcareSpecialty::MultiSpecialty->value, $specialties, true)
                && count($specialties) < 2) {
                $validator->errors()->add(
                    'healthcare_specialties',
                    'Pick the specialties this multi-specialty organisation covers.'
                );
            }

            $branches = $this->input('branches');

            if (is_array($branches)) {
                $mains = collect($branches)
                    ->filter(fn ($b) => filter_var($b['is_main'] ?? false, FILTER_VALIDATE_BOOL))
                    ->count();

                if ($mains > 1) {
                    $validator->errors()->add('branches', 'Only one branch can be the main site.');
                }
            }
        });
    }
}
