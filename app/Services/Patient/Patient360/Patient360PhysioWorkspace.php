<?php

namespace App\Services\Patient\Patient360;

use App\Models\Patient;
use App\Models\PatientFollowUp;
use App\Models\PatientSessionPackage;
use App\Models\PhysioAssessment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioSession;
use App\Models\User;
use App\Services\Physio\SessionPackages;
use Illuminate\Support\Collection;

/**
 * Patient 360's physiotherapy workspace: the therapist's episode of care.
 *
 * Assessment (initial, then reassessments) → plan of care → sessions (SOAP,
 * treatment, exercises, response, home programme) → progress (pain, outcome
 * measures and range of motion over time) → discharge, with packages and
 * follow-ups alongside. Everything comes from recorded rows; trends are the
 * recorded values in date order, nothing interpolated. A session in a signed
 * consultation is marked signed and can no longer be changed.
 */
class Patient360PhysioWorkspace
{
    public function __construct(
        protected Patient360Access $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Patient $patient, User $user): array
    {
        $open = $this->access->openConsultation($user, $patient);
        $measures = config('clinic_physio.functional_measures');
        $assessments = PhysioAssessment::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->with(['assessor:id,name', 'encounter:id,encounter_number'])->latest('id')->limit(12)->get();
        $plans = PhysioCarePlan::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->with(['exercises' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])->withCount('sessions')->latest('id')->limit(10)->get();
        $active = $plans->firstWhere('status', PhysioCarePlan::ACTIVE);
        $sessions = PhysioSession::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->with(['therapist:id,name', 'room:id,name', 'package:id,name', 'encounter:id,encounter_number,finalized_at'])->latest('id')->limit(30)->get();

        return [
            'assessments' => $assessments->map(fn (PhysioAssessment $a) => [
                'id' => $a->id,
                'kind' => $a->kind ?? PhysioAssessment::INITIAL,
                'date' => $a->created_at?->toIso8601String(),
                'by' => $a->assessor?->name,
                'consultation' => $a->encounter?->encounter_number,
                ...$a->only(['presenting_problem', 'pain_location', 'onset', 'aggravating_factors', 'relieving_factors', 'objective_findings', 'gait',
                    'clinical_impression', 'diagnosis', 'pain_at_rest', 'pain_on_activity', 'posture', 'functional_notes', 'goals']),
                'body_chart' => $a->body_chart ?? [],
                'special_tests' => $a->special_tests ?? [],
                'range_of_motion' => $a->range_of_motion ?? [],
                'strength' => $a->strength ?? [],
                'functional_measures' => collect($a->functional_measures ?? [])->map(fn (array $row) => [
                    'measure' => $row['measure'] ?? null, 'label' => $measures[$row['measure'] ?? '']['label'] ?? ($row['measure'] ?? ''),
                    'max' => $measures[$row['measure'] ?? '']['max'] ?? null, 'score' => isset($row['score']) ? (float) $row['score'] : null,
                ])->values(),
            ])->values(),
            'plan' => $active ? [
                'id' => $active->id, 'title' => $active->title, 'goals' => $active->goals, 'frequency' => $active->frequency,
                'planned' => $active->planned_sessions, 'done' => $active->sessions_count, 'starts_on' => $active->starts_on?->toDateString(),
                'interventions' => $this->modalities($active->interventions ?? []),
                'exercises' => $active->exercises->map(fn ($e) => $e->only(['id', 'name', 'sets', 'reps', 'hold_seconds', 'frequency', 'instructions', 'is_home']))->values(),
            ] : null,
            'discharges' => $plans->whereIn('status', [PhysioCarePlan::COMPLETED, PhysioCarePlan::DISCONTINUED])->map(fn (PhysioCarePlan $plan) => [
                'id' => $plan->id, 'title' => $plan->title, 'status' => $plan->status, 'closed_at' => $plan->closed_at?->toIso8601String(),
                'note' => $plan->closing_note, 'done' => $plan->sessions_count, 'planned' => $plan->planned_sessions, 'goals' => $plan->goals,
            ])->values(),
            'sessions' => $sessions->map(fn (PhysioSession $s) => [
                'id' => $s->id, 'number' => $s->session_number, 'date' => $s->created_at?->toIso8601String(), 'therapist' => $s->therapist?->name,
                'subjective' => $s->subjective, 'objective' => $s->objective, 'assessment' => $s->assessment, 'plan' => $s->plan,
                'treatment_performed' => $s->treatment_performed, 'exercises_performed' => $s->exercises_performed ?? [],
                'patient_response' => $s->patient_response, 'home_program' => $s->home_program, 'next_step' => $s->next_step,
                'pain_before' => $s->pain_before, 'pain_after' => $s->pain_after,
                'modalities' => $this->modalities($s->modalities ?? []),
                'room' => $s->room?->name, 'package' => $s->package?->name,
                'consultation' => $s->encounter?->encounter_number,
                'signed' => $s->encounter?->finalized_at !== null,
                'consultation_url' => route('clinic.consultations.show', $s->encounter_id),
            ])->values(),
            'packages' => PatientSessionPackage::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->latest('id')->limit(10)->get()->map(fn (PatientSessionPackage $p) => [
                    'id' => $p->id, 'name' => $p->name, 'status' => $p->status, 'total' => $p->sessions_total, 'used' => $p->sessions_used, 'remaining' => $p->remaining(), 'patient_treatment_id' => $p->patient_treatment_id,
                    'valid_until' => $p->valid_until?->toDateString(), 'expired' => $p->isExpired(),
                ])->values(),
            'progress' => $this->progress($sessions, $assessments),
            'follow_ups' => PatientFollowUp::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->whereIn('status', [PatientFollowUp::DUE, PatientFollowUp::BOOKED])->with('practitioner:id,name')->orderBy('due_on')->limit(5)->get()
                ->map(fn (PatientFollowUp $f) => ['id' => $f->id, 'due_on' => $f->due_on->toDateString(), 'reason' => $f->reason, 'practitioner' => $f->practitioner?->name, 'status' => $f->status])->values(),
            'open_consultation' => $open ? ['id' => $open->id, 'number' => $open->encounter_number, 'url' => route('clinic.consultations.show', $open)] : null,
            'body_regions' => config('clinic_physio.body_regions'),
            'links' => [
                // A package is sold for one treatment: here, the active plan's (none, no sale).
                'sell_package' => SessionPackages::maySell($user) && $active?->patient_treatment_id ? route('clinic.patients.session-packages.store', $patient->id) : null,
                'sell_treatment_id' => $active?->patient_treatment_id,
                'follow_up' => $this->access->canManageFollowUps($user) ? route('clinic.patients.follow-ups.store', $patient->id) : null,
            ],
        ];
    }

    /**
     * Recorded values in date order: pain per session, each outcome
     * measure across assessments, and each joint movement's range.
     *
     * @param  Collection<int, PhysioSession>  $sessions
     * @param  Collection<int, PhysioAssessment>  $assessments
     * @return array<string, mixed>
     */
    private function progress(Collection $sessions, Collection $assessments): array
    {
        $measures = config('clinic_physio.functional_measures');
        $chronological = $assessments->sortBy('id')->values();

        $outcomes = $chronological->flatMap(fn (PhysioAssessment $a) => collect($a->functional_measures ?? [])
            ->map(fn (array $row) => ['measure' => $row['measure'] ?? '', 'score' => isset($row['score']) ? (float) $row['score'] : null, 'date' => $a->created_at?->toDateString()]))
            ->filter(fn (array $row) => $row['score'] !== null && isset($measures[$row['measure']]))
            ->groupBy('measure')
            ->map(fn (Collection $rows, string $measure) => [
                'measure' => $measure, 'label' => $measures[$measure]['label'], 'max' => $measures[$measure]['max'],
                'points' => $rows->map(fn (array $row) => ['date' => $row['date'], 'score' => $row['score']])->values(),
            ])->values();

        $rom = $chronological->flatMap(fn (PhysioAssessment $a) => collect($a->range_of_motion ?? [])
            ->map(fn (array $row) => [
                'key' => trim(implode(' ', array_filter([$row['side'] ?? null, $row['joint'] ?? null, $row['movement'] ?? null]))),
                'normal' => isset($row['normal']) ? (int) $row['normal'] : null,
                'active' => isset($row['active']) ? (int) $row['active'] : null,
                'passive' => isset($row['passive']) ? (int) $row['passive'] : null,
                'date' => $a->created_at?->toDateString(),
            ]))
            ->filter(fn (array $row) => $row['key'] !== '' && ($row['active'] !== null || $row['passive'] !== null))
            ->groupBy('key')
            ->map(fn (Collection $rows, string $key) => [
                'key' => $key, 'normal' => $rows->pluck('normal')->filter()->last(),
                'points' => $rows->map(fn (array $row) => ['date' => $row['date'], 'active' => $row['active'], 'passive' => $row['passive']])->values(),
            ])->values();

        return [
            'pain' => $sessions->sortBy('id')->filter(fn (PhysioSession $s) => $s->pain_before !== null || $s->pain_after !== null)
                ->map(fn (PhysioSession $s) => ['session_number' => $s->session_number, 'pain_before' => $s->pain_before, 'pain_after' => $s->pain_after, 'date' => $s->created_at?->toDateString()])->values(),
            'outcomes' => $outcomes,
            'rom' => $rom,
        ];
    }

    /**
     * @param  array<int, string>  $keys
     * @return list<string>
     */
    private function modalities(array $keys): array
    {
        return collect($keys)->map(fn ($key) => config("clinic_physio.modalities.{$key}") ?? $key)->values()->all();
    }
}
