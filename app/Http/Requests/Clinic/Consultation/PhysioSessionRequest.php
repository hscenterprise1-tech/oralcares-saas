<?php

namespace App\Http\Requests\Clinic\Consultation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A physiotherapy session's SOAP note. The room and package are checked to
 * be this branch's and this patient's in `Physio\PhysioRecord`.
 * Authorization is the controller's.
 */
class PhysioSessionRequest extends FormRequest
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
            // The care context is explicit: the session is for this treatment.
            'patient_treatment_id' => ['required', 'integer'],
            'complete' => ['nullable', 'boolean'],
            'exercises' => ['sometimes', 'array', 'max:60'],
            'exercises.*.physio_exercise_id' => ['nullable', 'integer'],
            'exercises.*.name' => ['nullable', 'string', 'max:150'],
            'exercises.*.status' => ['nullable', Rule::in(['performed', 'skipped'])],
            'exercises.*.sets' => ['nullable', 'integer', 'between:0,50'],
            'exercises.*.reps' => ['nullable', 'integer', 'between:0,500'],
            'exercises.*.duration_seconds' => ['nullable', 'integer', 'between:0,14400'],
            'exercises.*.intensity' => ['nullable', 'string', 'max:80'],
            'exercises.*.notes' => ['nullable', 'string', 'max:1000'],
            'subjective' => ['nullable', 'string', 'max:10000'],
            'objective' => ['nullable', 'string', 'max:10000'],
            'assessment' => ['nullable', 'string', 'max:10000'],
            'plan' => ['nullable', 'string', 'max:10000'],
            'pain_before' => ['nullable', 'integer', 'between:0,10'],
            'pain_after' => ['nullable', 'integer', 'between:0,10'],
            'modalities' => ['array'],
            'modalities.*' => [Rule::in(array_keys(config('clinic_physio.modalities')))],
            'room_id' => ['nullable', 'integer'],
            'package_id' => ['nullable', 'integer'],
            'treatment_performed' => ['nullable', 'string', 'max:10000'],
            'exercises_performed' => ['array', 'max:30'],
            'exercises_performed.*.name' => ['required', 'string', 'max:150'],
            'exercises_performed.*.sets' => ['nullable', 'integer', 'between:0,50'],
            'exercises_performed.*.reps' => ['nullable', 'integer', 'between:0,500'],
            'exercises_performed.*.duration' => ['nullable', 'string', 'max:40'],
            'exercises_performed.*.intensity' => ['nullable', 'string', 'max:40'],
            'patient_response' => ['nullable', 'string', 'max:5000'],
            'home_program' => ['nullable', 'string', 'max:5000'],
            'next_step' => ['nullable', 'string', 'max:500'],
        ];
    }
}
