<?php

namespace App\Http\Requests\Clinic;

use App\Services\ClinicContext;
use App\Services\Patient\CaseNumberConfigService;
use App\Services\Patient\CaseNumberFormatter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A clinic's case-number format. Authorization is the route's
 * `clinic_permission:settings.manage`.
 */
class UpdateCaseNumberConfigRequest extends FormRequest
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
            'prefix' => ['required', 'string', 'regex:/^[A-Za-z0-9]{1,10}$/'],
            'show_year' => ['required', 'boolean'],
            'year_style' => ['required', Rule::in(CaseNumberFormatter::YEAR_STYLES)],
            'separator' => ['nullable', Rule::in(array_filter(CaseNumberFormatter::SEPARATORS))],
            'digits' => ['required', 'integer', 'between:4,8'],
            'start_number' => ['required', 'integer', 'min:1', 'max:99999999'],
            'reset_yearly' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prefix.regex' => 'The prefix must be 1 to 10 letters or numbers, with no spaces or symbols.',
            'digits.between' => 'The number part must be between 4 and 8 digits long.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('show_year') && $this->boolean('reset_yearly')) {
                    $validator->errors()->add('reset_yearly', 'Turn off the yearly restart when the year is hidden, otherwise the same number would repeat every year.');
                }

                $clinic = app(ClinicContext::class)->clinic();
                $service = app(CaseNumberConfigService::class);
                $startNumber = $this->integer('start_number');

                if ($startNumber === $service->resolve($clinic)['start_number']) {
                    return;
                }

                $highest = $service->highestIssuedSequence($clinic);
                if ($startNumber <= $highest) {
                    $validator->errors()->add('start_number', "The start number must be higher than {$highest}, the highest number already issued.");
                }
            },
        ];
    }
}
