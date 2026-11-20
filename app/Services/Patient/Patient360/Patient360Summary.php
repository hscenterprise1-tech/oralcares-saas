<?php

namespace App\Services\Patient\Patient360;

use App\AppointmentStatus;
use App\BillingStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrderItem;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientBilling;
use App\Models\PatientBranchActivity;
use App\Models\PatientClinicalEntry;
use App\Models\PatientFollowUp;
use App\Models\PatientProcedure;
use App\Models\PatientVisit;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\Patient\PatientDirectoryAudience;
use App\Services\Treatment\TreatmentReadModel;
use App\Support\Clinical\DefaultClinicalCatalogue;
use App\Support\ClinicClock;
use App\Support\ClinicFrontend;
use Illuminate\Database\Eloquent\Builder;

/**
 * The first screen of Patient 360: who the patient is, the state they are
 * in (last visit, next appointment, balance, what is under way), the quick
 * clinical context (allergies, conditions, medicines, alerts), and the
 * overview of now / next / recent.
 *
 * Everything here is read from records as written. Nothing is inferred into
 * a value the record does not hold: a medicine shows its dose only if the
 * prescription had one, a condition only if it was recorded.
 */
class Patient360Summary
{
    public function __construct(
        protected Patient360Access $access,
        protected TreatmentReadModel $treatments,
    ) {}

    /**
     * Who the patient is. The full mobile number and email go only to
     * members whose work is contacting patients (the directory's rule,
     * `PatientDirectoryAudience::canSeeContact`); everyone else gets the last
     * four digits.
     *
     * @return array<string, mixed>
     */
    public function identity(Patient $patient, User $user): array
    {
        $patient->loadMissing('currentPractitioner:id,name');
        $lastVisit = PatientVisit::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->tap(fn (Builder $query) => $this->inBranches($query))
            ->with(['branch' => $this->clinicBranch($patient)])->latest('visit_date')->latest('id')->first();
        $contact = app(PatientDirectoryAudience::class)->canSeeContact($user);
        $digits = preg_replace('/\D/', '', (string) $patient->phone) ?? '';

        return [
            'id' => $patient->id,
            'name' => $patient->full_name,
            'first_name' => $patient->first_name,
            'uhid' => $patient->uhid,
            'age' => $patient->age,
            'gender' => $patient->gender,
            'blood_group' => $patient->blood_group,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'phone' => $contact ? $patient->phone : null,
            'phone_last_digits' => strlen($digits) >= 4 ? substr($digits, -4) : null,
            'email' => $contact ? $patient->email : null,
            'status' => $patient->status?->value ?? 'active',
            'registered_on' => $patient->created_at?->toIso8601String(),
            // The branch of the last visit among the member's own branches, and when it was.
            'branch' => $lastVisit?->branch?->name,
            'last_visit_on' => $lastVisit?->visit_date?->toDateString(),
            'registered_branch' => $this->registeredBranch($patient),
            'practitioner' => $patient->currentPractitioner?->name,
        ];
    }

    /**
     * The patient's details beyond identity: address, preferred channel and
     * emergency contact (`patient_profiles`). Addresses and the full
     * emergency number follow the same contact rule as the mobile number;
     * everyone else gets the emergency contact's name and last four digits.
     *
     * @return array<string, mixed>
     */
    public function profile(Patient $patient, User $user): array
    {
        $patient->loadMissing('profile');
        $profile = $patient->profile;
        $contact = app(PatientDirectoryAudience::class)->canSeeContact($user);
        $emergencyDigits = preg_replace('/\D/', '', (string) $profile?->emergency_contact_phone) ?? '';
        $address = $contact ? array_values(array_filter([
            $profile?->address, $profile?->locality, $profile?->city, $profile?->state, $profile?->pincode ?: $profile?->postal_code, $profile?->country,
        ], fn ($part) => filled($part))) : [];

        return [
            'address' => $address === [] ? null : implode(', ', $address),
            'city' => $contact ? $profile?->city : null,
            'state' => $contact ? $profile?->state : null,
            'pincode' => $contact ? ($profile?->pincode ?: $profile?->postal_code) : null,
            'preferred_channel' => $profile?->preferred_channel,
            'alternate_phone' => $contact ? $patient->alternate_phone : null,
            'emergency_contact' => $profile?->emergency_contact_name || $profile?->emergency_contact_phone ? [
                'name' => $profile->emergency_contact_name,
                'phone' => $contact ? $profile->emergency_contact_phone : null,
                'phone_last_digits' => strlen($emergencyDigits) >= 4 ? substr($emergencyDigits, -4) : null,
            ] : null,
            // Whether the full contact details were sent (so the page says why they are not shown).
            'contact_visible' => $contact,
        ];
    }

    /**
     * The branch the patient was registered at, when it is one of the
     * member's branches (`patient_branch_activity`, first source `registration`,
     * else the earliest activity).
     */
    private function registeredBranch(Patient $patient): ?string
    {
        $ids = $this->access->branchIds();
        $branchId = PatientBranchActivity::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->when($ids !== null, fn ($query) => $query->whereIn('branch_id', $ids ?: [0]))
            ->orderByRaw("CASE WHEN first_source = 'registration' THEN 0 ELSE 1 END")->orderBy('first_seen_at')->value('branch_id');

        return $branchId ? Branch::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->whereKey($branchId)->value('name') : null;
    }

    /**
     * Last visit, next appointment, balance and what is under way.
     *
     * @return array<string, mixed>
     */
    public function state(Patient $patient, User $user): array
    {
        $now = ClinicClock::now();

        $lastEncounter = $this->access->canViewClinical($user)
            ? $this->encounters($patient)->latest('started_at')->first(['id', 'started_at', 'encounter_number'])
            : null;
        $lastVisit = PatientVisit::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->tap(fn (Builder $query) => $this->inBranches($query))->latest('visit_date')->first(['visit_date']);

        $next = $this->access->canViewAppointments($user) ? $this->nextAppointment($patient, $now) : null;

        return [
            'last_visit' => $lastEncounter?->started_at?->toIso8601String() ?? ($lastVisit?->visit_date ? $lastVisit->visit_date->toDateString() : null),
            'next_appointment' => $next,
            'outstanding' => $this->access->canViewBilling($user) ? $this->outstanding($patient) : null,
            'episode' => $this->access->canViewClinical($user) ? ($this->treatmentInProgress($patient)[0] ?? null) : null,
        ];
    }

    /**
     * The strip under the header. Only what is recorded.
     *
     * @return array<string, mixed>
     */
    public function context(Patient $patient, User $user): array
    {
        $patient->loadMissing(['medicalHistory', 'alerts.creator:id,name']);
        $entries = app(PatientClinicalEntries::class);

        // Conditions, allergies and medications come from the clinical entries.
        $allergies = collect($entries->labelsFor($patient, DefaultClinicalCatalogue::KIND_ALLERGY));

        // `patient_alerts` is the one alert system; the history's old
        // `medical_alerts` column is folded into it by the foundation backfill.
        // Safety alerts are the precautions staff recorded (`flag` from the
        // clinic's alert catalogue, `manual`). The copies `syncDerivedAlerts`
        // keeps of allergies and high-severity conditions are not repeated
        // here: those are shown (or, for allergies, deliberately not shown)
        // as what they are. Nothing is reclassified; most severe first.
        $alerts = $patient->alerts->reject(fn (PatientAlert $alert) => in_array($alert->type, PatientClinicalEntries::DERIVED_ALERT_TYPES, true))
            // Who recorded it and when, where that was stored (older alerts have no author).
            ->map(fn (PatientAlert $alert) => ['id' => $alert->id, 'text' => $alert->description, 'severity' => $alert->severity, 'type' => $alert->type, 'recorded_on' => $alert->created_at ? ClinicClock::fromUtc($alert->created_at)->toDateString() : null, 'recorded_by' => $alert->creator?->name])
            ->filter(fn (array $alert) => trim((string) $alert['text']) !== '')
            ->sortBy(fn (array $alert) => [self::SEVERITY_RANK[$alert['severity']] ?? 9, $alert['text']])->values();

        $clinical = $this->access->canViewClinical($user);

        return [
            'allergies' => $allergies,
            'alerts' => $alerts,
            // Allergies and alerts are safety information every role sees.
            // Conditions and medicines — diagnosed or self-reported — are
            // clinical history and are sent to clinical roles only.
            'conditions' => [
                'diagnosed' => $clinical ? $this->activeConditions($patient) : [],
                'reported' => $clinical ? $entries->labelsFor($patient, DefaultClinicalCatalogue::KIND_CONDITION) : [],
                // The old yes/no condition columns are part of `reported` now.
                'flags' => [],
            ],
            'medications' => [
                'prescribed' => $clinical ? $this->recentMedicines($patient) : [],
                'reported' => $clinical ? $entries->labelsFor($patient, DefaultClinicalCatalogue::KIND_MEDICATION) : [],
            ],
            // The patient's medical history conditions (diabetes, hypertension…), as recorded: who, when, how serious.
            'medical_conditions' => $clinical ? $this->medicalConditions($patient) : [],
            // The medical history's free-text notes are clinical history too.
            'medical_notes' => $clinical && filled($patient->medicalHistory?->notes) ? (string) $patient->medicalHistory->notes : null,
            'medical_notes_updated_on' => $clinical && filled($patient->medicalHistory?->notes) ? ($patient->medicalHistory->notes_updated_at ?? $patient->medicalHistory->updated_at)?->toDateString() : null,
            'medical_notes_updated_by' => $clinical && $patient->medicalHistory?->notes_updated_by ? User::query()->whereKey($patient->medicalHistory->notes_updated_by)->value('name') : null,
            'clinical' => $clinical,
        ];
    }

    /** Alerts and conditions, most severe first; unknown severities last. */
    private const SEVERITY_RANK = ['critical' => 0, 'high' => 1, 'moderate' => 2, 'medium' => 2, 'low' => 3];

    /**
     * Active medical-history conditions (clinical entries of kind `condition`),
     * most severe first. `source` says how it came to be on record: reported
     * at registration, or recorded by clinical staff.
     *
     * @return list<array{key: ?string, label: string, severity: ?string, source: string, recorded_on: ?string, recorded_by: ?string}>
     */
    private function medicalConditions(Patient $patient): array
    {
        $entries = PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('kind', DefaultClinicalCatalogue::KIND_CONDITION)->where('status', PatientClinicalEntry::ACTIVE)
            ->with(['recorder' => fn ($query) => $query->select('id', 'name')])
            ->get(['catalogue_key', 'label', 'severity', 'source', 'recorded_at', 'recorded_by']);

        if ($entries->isEmpty()) {
            // Records from before clinical entries: the names only.
            return array_map(fn (string $label) => ['key' => null, 'label' => $label, 'severity' => null, 'source' => 'backfill', 'recorded_on' => null, 'recorded_by' => null],
                app(PatientClinicalEntries::class)->legacyLabels($patient, DefaultClinicalCatalogue::KIND_CONDITION));
        }

        return $entries->sortBy(fn (PatientClinicalEntry $entry) => [self::SEVERITY_RANK[$entry->severity] ?? 9, mb_strtolower($entry->label)])
            ->map(fn (PatientClinicalEntry $entry) => [
                'key' => $entry->catalogue_key,
                'label' => $entry->label,
                'severity' => $entry->severity,
                'source' => $entry->source,
                'recorded_on' => $entry->recorded_at ? ClinicClock::fromUtc($entry->recorded_at)->toDateString() : null,
                'recorded_by' => $entry->recorder?->name,
            ])->values()->all();
    }

    /**
     * Now, next, recent.
     *
     * @return array<string, mixed>
     */
    public function overview(Patient $patient, User $user): array
    {
        $clinical = $this->access->canViewClinical($user);
        $now = ClinicClock::now();

        $openItems = $clinical ? $this->orderItems($patient)->whereIn('status', ClinicalOrderItem::OPEN)
            ->with('order:id,order_number')->latest('id')->limit(4)->get() : collect();

        $nextFollowUp = PatientFollowUp::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereIn('status', [PatientFollowUp::DUE, PatientFollowUp::BOOKED])->with('practitioner:id,name')->orderBy('due_on')->first();

        $latestEncounter = $clinical ? $this->encounters($patient)->with(['doctor:id,name', 'diagnoses' => fn ($query) => $query->orderByDesc('is_primary')])->whereNull('voided_at')->latest('started_at')->first() : null;
        $latestRx = $clinical ? $this->prescriptions($patient)->where('status', '!=', Prescription::DRAFT)->with('items', 'prescribingDoctor:id,name')->latest('id')->first() : null;
        $latestResult = $clinical ? $this->orderItems($patient)->whereIn('status', [ClinicalOrderItem::RESULTED, ClinicalOrderItem::REVIEWED])
            ->with('result:id,clinical_order_item_id,summary,is_abnormal,status')->latest('resulted_at')->first() : null;
        $latestProcedure = $clinical ? PatientProcedure::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereHas('encounter', fn (Builder $query) => $this->inBranches($query))->latest('performed_at')->first() : null;

        $unpaid = $this->access->canViewBilling($user)
            ? $this->bills($patient)->whereIn('status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])->where('outstanding_balance', '>', 0)->latest('issued_at')->limit(3)->get()
            : collect();

        return [
            'summary' => [
                'appointments' => $this->appointmentSummary($patient, $user, $now),
                'finance' => $this->access->canViewBilling($user) ? $this->financeSummary($patient) : null,
            ],
            'current' => [
                // Open treatments as separate care streams (Dental · Orthodontics, Physiotherapy · Sports Rehabilitation).
                'care_streams' => $clinical ? $this->treatments->careStreams($patient->clinic_id, $patient->id, $this->access->branchIds()) : [],
                // Active Care: every open treatment, from the treatment engine. Summary-level
                // for every role that can open the patient; amounts only for billing roles.
                'active_care' => $this->treatments->forPatient($patient->clinic_id, $patient->id, $this->access->branchIds(), true, $this->access->canViewBilling($user), 10),
                'open_consultation' => $clinical ? $this->encounters($patient)->whereNull('finalized_at')->where('status', 'in_progress')->with('doctor:id,name')->latest('started_at')->first()?->only(['id', 'encounter_number']) : null,
            ],
            'next' => [
                'appointment' => $this->access->canViewAppointments($user) ? $this->nextAppointment($patient, $now) : null,
                'follow_up' => $nextFollowUp ? [
                    'id' => $nextFollowUp->id,
                    'due_on' => $nextFollowUp->due_on->toDateString(),
                    'reason' => $nextFollowUp->reason,
                    'practitioner' => $nextFollowUp->practitioner?->name,
                    'status' => $nextFollowUp->status,
                    'overdue' => $nextFollowUp->due_on->toDateString() < $now->toDateString(),
                ] : null,
                'investigations' => $openItems->map(fn (ClinicalOrderItem $item) => [
                    'name' => $item->item_name, 'status' => $item->status, 'category' => ClinicalOrderItem::CATEGORIES[$item->category]['label'] ?? $item->category,
                    'url' => ClinicFrontend::available('clinic.orders.show') ? route('clinic.orders.show', $item->clinical_order_id) : null,
                ])->values(),
                'payments' => $unpaid->map(fn (PatientBilling $bill) => ['id' => $bill->id, 'number' => $bill->billing_number, 'outstanding' => (float) $bill->outstanding_balance])->values(),
            ],
            'recent' => [
                'consultation' => $latestEncounter ? [
                    'id' => $latestEncounter->id,
                    'number' => $latestEncounter->encounter_number,
                    'date' => $latestEncounter->started_at?->toIso8601String(),
                    'practitioner' => $latestEncounter->doctor?->name,
                    'complaint' => $latestEncounter->chief_complaint,
                    'diagnosis' => $latestEncounter->diagnoses->first()?->description,
                    'signed' => $latestEncounter->isFinalized(),
                    // Linked once the consultation screen is built (`ClinicFrontend`).
                    'url' => ClinicFrontend::available('clinic.consultations.show') && $user->can('view', $latestEncounter) ? route('clinic.consultations.show', $latestEncounter) : null,
                ] : null,
                'prescription' => $latestRx ? [
                    'number' => $latestRx->prescription_number,
                    'date' => ($latestRx->finalized_at ?? $latestRx->created_at)?->toIso8601String(),
                    'practitioner' => $latestRx->prescribingDoctor?->name,
                    'status' => $latestRx->status,
                    'medicines' => $latestRx->items->map(fn (PrescriptionItem $line) => trim($line->drug_name.' '.($line->strength ?? '')))->values(),
                    'print_url' => $user->can('print', $latestRx) ? route('clinic.prescriptions.print', $latestRx) : null,
                ] : null,
                'investigation' => $latestResult ? [
                    'name' => $latestResult->item_name,
                    'status' => $latestResult->status,
                    'date' => $latestResult->resulted_at?->toIso8601String(),
                    'summary' => $latestResult->result?->summary,
                    'is_abnormal' => (bool) $latestResult->result?->is_abnormal,
                    'url' => ClinicFrontend::available('clinic.orders.show') ? route('clinic.orders.show', $latestResult->clinical_order_id) : null,
                ] : null,
                'procedure' => $latestProcedure ? [
                    'name' => $latestProcedure->name,
                    'tooth' => $latestProcedure->details['tooth_number'] ?? null,
                    'date' => ($latestProcedure->performed_at ?? $latestProcedure->created_at)?->toIso8601String(),
                ] : null,
            ],
        ];
    }

    /** @return Builder<ClinicalEncounter> */
    public function encounters(Patient $patient): Builder
    {
        return ClinicalEncounter::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->tap(fn (Builder $query) => $this->inBranches($query));
    }

    /** @return Builder<Prescription> */
    public function prescriptions(Patient $patient): Builder
    {
        return Prescription::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereHas('encounter', fn (Builder $query) => $this->inBranches($query));
    }

    /** @return Builder<ClinicalOrderItem> */
    public function orderItems(Patient $patient): Builder
    {
        return ClinicalOrderItem::query()->where('clinical_order_items.clinic_id', $patient->clinic_id)
            ->whereHas('order', fn (Builder $order) => $order->where('patient_id', $patient->id)->tap(fn (Builder $query) => $this->inBranches($query)));
    }

    /** @return Builder<PatientBilling> */
    public function bills(Patient $patient): Builder
    {
        return PatientBilling::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->tap(fn (Builder $query) => $this->inBranches($query));
    }

    /** Limit a branch-owned query to the member's branches. */
    public function inBranches(Builder $query, string $column = 'branch_id'): Builder
    {
        $ids = $this->access->branchIds();

        return $ids === null ? $query : $query->whereIn($query->getModel()->qualifyColumn($column), $ids);
    }

    /**
     * A record's branch, read inside the patient's clinic. The organization
     * scope is not applied: it hides a branch with no organization set,
     * which leaves the visit or booking with no branch name at all.
     */
    public function clinicBranch(Patient $patient): \Closure
    {
        return fn ($query) => $query->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->select(['id', 'name']);
    }

    /**
     * Upcoming bookings, visits attended and bookings missed, in the member's
     * branches. A visit is a check-in (`Patient360Sections::ATTENDED_VISIT`),
     * never a booking. Null for members who may not see appointments.
     *
     * @return array{upcoming: int, attended: int, missed: int}|null
     */
    private function appointmentSummary(Patient $patient, User $user, $now): ?array
    {
        if (! $this->access->canViewAppointments($user)) {
            return null;
        }
        $bookings = fn () => Appointment::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->tap(fn (Builder $query) => $this->inBranches($query));

        return [
            'upcoming' => $bookings()->where('scheduled_at', '>=', $now->startOfDay())
                ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])->count(),
            'attended' => PatientVisit::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->tap(fn (Builder $query) => $this->inBranches($query))->whereIn('visit_status', Patient360Sections::ATTENDED_VISIT)->count(),
            'missed' => $bookings()->where('status', AppointmentStatus::NoShow->value)->count(),
        ];
    }

    /**
     * What the patient was billed, has paid and still owes, across live bills
     * in the member's branches (cancelled bills are not counted).
     *
     * @return array{billed: float, paid: float, outstanding: float, bills: int}
     */
    private function financeSummary(Patient $patient): array
    {
        $totals = $this->bills($patient)->where('status', '!=', BillingStatus::Cancelled->value)
            ->selectRaw('COALESCE(SUM(total_amount), 0) as billed, COALESCE(SUM(paid_amount), 0) as paid, COALESCE(SUM(outstanding_balance), 0) as outstanding, COUNT(*) as bills')
            ->toBase()->first();

        return ['billed' => (float) $totals->billed, 'paid' => (float) $totals->paid, 'outstanding' => (float) $totals->outstanding, 'bills' => (int) $totals->bills];
    }

    private function outstanding(Patient $patient): float
    {
        return (float) $this->bills($patient)->where('status', '!=', BillingStatus::Cancelled->value)->sum('outstanding_balance');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextAppointment(Patient $patient, $now): ?array
    {
        $next = Appointment::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->tap(fn (Builder $query) => $this->inBranches($query))
            ->where('scheduled_at', '>=', $now->startOfDay())
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value, AppointmentStatus::CheckedIn->value])
            ->with(['practitioner:id,name', 'branch' => $this->clinicBranch($patient)])->orderBy('scheduled_at')->first();

        return $next ? [
            'id' => $next->id,
            'date_label' => $next->scheduled_at->format('D, d M Y'),
            'time_label' => $next->scheduled_at->format('h:i A'),
            'is_today' => $next->scheduled_at->isSameDay($now),
            'practitioner' => $next->practitioner?->name,
            'branch' => $next->branch?->name,
            'status' => $next->status?->value,
        ] : null;
    }

    /**
     * What is being worked through: accepted dental plans, an active plan of
     * care, investigations awaited.
     *
     * @return list<array<string, mixed>>
     */
    private function treatmentInProgress(Patient $patient): array
    {
        // Read from `patient_treatments` (legacy dental lines and physio plans are
        // mirrored there); the shape is the one the current screen reads.
        return collect($this->treatments->forPatient($patient->clinic_id, $patient->id, $this->access->branchIds(), true, false, 4))
            ->map(fn (array $t) => ['kind' => $t['specialty'] === 'physiotherapy' ? 'physio' : ($t['specialty'] ?? 'treatment'), 'title' => $t['title'], 'done' => $t['progress']['done'], 'total' => $t['progress']['total'], 'id' => $t['id'], 'status' => $t['status']])
            ->values()->all();
    }

    /**
     * @return list<array{description: string, code: ?string, status: string, noted_on: ?string}>
     */
    private function activeConditions(Patient $patient): array
    {
        return ClinicalDiagnosis::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereHas('encounter', fn (Builder $query) => $this->inBranches($query)->whereNull('voided_at'))
            ->whereIn('status', ['confirmed', 'provisional'])
            ->where('created_at', '>=', now()->subYear())
            ->latest('id')->limit(20)->get(['description', 'code', 'status', 'created_at'])
            ->unique(fn (ClinicalDiagnosis $diagnosis) => strtolower(($diagnosis->code ?? '').'|'.$diagnosis->description))
            ->take(6)->map(fn (ClinicalDiagnosis $diagnosis) => [
                ...$diagnosis->only(['description', 'code', 'status']),
                // When it was recorded, on the clinic's calendar.
                'noted_on' => $diagnosis->created_at ? ClinicClock::fromUtc($diagnosis->created_at)->toDateString() : null,
            ])->values()->all();
    }

    /**
     * Medicines prescribed in the last 30 days, exactly as written.
     *
     * @return list<array<string, mixed>>
     */
    private function recentMedicines(Patient $patient): array
    {
        return $this->prescriptions($patient)->where('status', Prescription::FINALIZED)->whereHas('encounter', fn (Builder $query) => $query->whereNull('voided_at'))
            ->where('finalized_at', '>=', now()->subDays(30))->with('items')->latest('finalized_at')->limit(5)->get()
            ->flatMap(fn (Prescription $rx) => $rx->items->map(fn (PrescriptionItem $line) => [
                'name' => trim($line->drug_name.' '.($line->strength ?? '')),
                'dosage' => $line->dosage,
                'frequency' => config("clinic_prescriptions.frequencies.{$line->frequency}") ?? $line->frequency,
                'duration' => $line->duration,
                'prescribed_on' => $rx->finalized_at?->toIso8601String(),
            ]))
            ->unique('name')->take(8)->values()->all();
    }
}
