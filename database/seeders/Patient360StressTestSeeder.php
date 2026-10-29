<?php

namespace Database\Seeders;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AttachedDocument;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientMedicalHistory;
use App\Models\PatientProfile;
use App\Models\PatientVisit;
use App\Models\User;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicalOrderService;
use App\Services\ClinicalPrescriptionService;
use App\Services\Dental\DentalRecord;
use App\Services\Patient\FollowUps;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LOCAL BROWSER TESTING ONLY — never called by DatabaseSeeder and refuses
 * any environment but local/testing. Run Patient360BrowserTestSeeder first.
 *
 * Two fictional QA patients in the browser-test clinic, for checking that
 * Patient 360's header and Overview stay aligned with real volumes of data:
 *
 * - "QA Full": every card filled, long names, long text, many records and
 *   amounts in lakhs;
 * - "QA Typical": a moderate record.
 *
 *   php artisan db:seed --class=Patient360StressTestSeeder
 *
 * Everything is written through the application's own services. Each
 * patient is created once (looked up by UHID); nothing else is touched.
 */
class Patient360StressTestSeeder extends Seeder
{
    public const FULL_UHID = 'QA-STRESS-FULL-0001';

    public const TYPICAL_UHID = 'QA-STRESS-TYPICAL-0001';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Patient360StressTestSeeder runs only in local or testing environments.');
        }

        $clinic = Clinic::query()->withoutGlobalScopes()->where('slug', 'p360-browser-dental')->first()
            ?? throw new RuntimeException('Run Patient360BrowserTestSeeder first.');
        $branch = Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('name', 'Main Branch')->firstOrFail();
        $dentistUser = User::query()->where('email', 'p360-dentist@browser-test.test')->firstOrFail();
        $dentist = ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('user_id', $dentistUser->id)->firstOrFail();
        $owner = User::query()->where('email', 'p360-owner@browser-test.test')->firstOrFail();

        auth()->login($dentistUser);
        try {
            if (! Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('uhid', self::FULL_UHID)->exists()) {
                DB::transaction(fn () => $this->full($clinic, $branch, $dentist, $owner));
            }
            if (! Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('uhid', self::TYPICAL_UHID)->exists()) {
                DB::transaction(fn () => $this->typical($clinic, $branch, $dentist));
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            auth()->logout();
        }
    }

    private function full(Clinic $clinic, Branch $branch, ClinicEmployee $dentist, User $owner): void
    {
        $today = CarbonImmutable::now();
        $patient = Patient::create([
            'clinic_id' => $clinic->id, 'uhid' => self::FULL_UHID,
            'first_name' => 'Venkata Lakshmi Narasimha', 'middle_name' => 'Subrahmanya', 'last_name' => 'Ramachandran-Iyengar (QA Full)',
            'phone' => '9876543210', 'alternate_phone' => '9123456780', 'gender' => 'female', 'date_of_birth' => '1958-11-23', 'age' => 67, 'blood_group' => 'AB+',
            'email' => 'venkata.lakshmi.narasimha.ramachandran.iyengar.longaddress@example-qa-fixture.test',
            'current_practitioner_id' => $dentist->id,
        ]);
        app(PatientBranchActivityRecorder::class)->record($clinic->id, $patient->id, $branch->id, $today->subMonths(10), 'registration');

        PatientProfile::query()->withoutGlobalScopes()->updateOrCreate(['patient_id' => $patient->id], [
            'clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id,
            'address' => 'Flat 1204, Tower B, Prestige Lakeside Habitat Apartments, Varthur Main Road, Opposite Gunjur Village Bus Stop',
            'locality' => 'Whitefield–Varthur Hobli', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'pincode' => '560087', 'country' => 'India',
            'preferred_channel' => 'whatsapp',
            'emergency_contact_name' => 'Srinivasa Raghavendra Ramachandran-Iyengar (son, lives in Chennai)', 'emergency_contact_phone' => '9988776655',
        ]);

        $entries = app(PatientClinicalEntries::class);
        $entries->add($patient, 'allergy', $entries->prepare($clinic->id, 'allergy', collect(['Penicillin and other beta-lactam antibiotics', 'Sulfonamides', 'Latex (examination gloves)', 'Chlorhexidine mouthwash', 'Ibuprofen and other NSAIDs', 'Iodine contrast'])->map(fn ($l) => ['label' => $l])->all(), 'a'), 'registration', null);
        $entries->add($patient, 'condition', $entries->prepare($clinic->id, 'condition', collect(['Type 2 diabetes mellitus, on insulin', 'Hypertension', 'Chronic kidney disease stage 3a', 'Hypothyroidism', 'Osteoporosis on bisphosphonate therapy'])->map(fn ($l) => ['label' => $l])->all(), 'c'), 'registration', null);
        $entries->add($patient, 'medication', $entries->prepare($clinic->id, 'medication', collect(['Insulin glargine 20 units at night', 'Telmisartan 40 mg', 'Levothyroxine 75 mcg', 'Alendronic acid 70 mg weekly', 'Aspirin 75 mg'])->map(fn ($l) => ['label' => $l])->all(), 'm'), 'registration', null);
        foreach ([
            ['Bleeding risk: on aspirin; check before extractions or surgery.', 'high'],
            ['Bisphosphonate therapy for over five years: discuss risk of jaw osteonecrosis before any extraction.', 'high'],
            ['Hard of hearing in the left ear; speak on the right side and confirm instructions in writing.', 'moderate'],
            ['Prefers appointments before 11 am because of insulin timing.', 'low'],
        ] as [$text, $severity]) {
            PatientAlert::create(['clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'type' => 'manual', 'description' => $text, 'severity' => $severity]);
        }
        PatientMedicalHistory::query()->withoutGlobalScopes()->updateOrCreate(['patient_id' => $patient->id], [
            'clinic_id' => $clinic->id,
            'notes' => 'Anxious about injections; topical anaesthetic first and short appointments. Daughter-in-law usually accompanies and helps with medicines. Last HbA1c 7.9% (August); physician Dr. K. Subramanian at Manipal Whitefield to be informed before any surgical procedure.',
        ]);

        $catalog = $this->procedures($clinic);
        $workspace = app(EncounterWorkspace::class);
        $dental = app(DentalRecord::class);
        $rx = app(ClinicalPrescriptionService::class);
        $billing = app(BillingService::class);
        $payments = app(PaymentService::class);

        // Six consultations over ten months, each with a visit, diagnoses and (later ones) prescriptions.
        $visits = [
            [300, 'Severe pain in the lower right back tooth on chewing, worse at night for two weeks', [['K04.0', 'Irreversible pulpitis, tooth 46', 'confirmed'], ['K05.1', 'Chronic gingivitis, plaque induced', 'confirmed']]],
            [220, 'Review after root canal; mild sensitivity remains', [['K04.5', 'Chronic apical periodontitis, tooth 46', 'confirmed']]],
            [150, 'Loose upper denture and sore spot on the palate', [['K08.1', 'Partial loss of teeth due to periodontal disease, upper arch', 'confirmed']]],
            [60, 'Bleeding gums when brushing and bad breath', [['K05.3', 'Chronic periodontitis, generalised, stage III grade B', 'confirmed'], ['E11.6', 'Diabetes with periodontal complications', 'provisional']]],
            [20, 'Broken filling in an upper front tooth', [['K02.1', 'Dentine caries with fractured composite restoration, tooth 21', 'confirmed']]],
            [4, 'Follow-up for deep cleaning, second quadrant, and a check of the crown margin on tooth 46', [['Z09', 'Follow-up after periodontal treatment', 'provisional']]],
        ];
        $encounters = [];
        foreach ($visits as $i => [$daysAgo, $complaint, $diagnoses]) {
            $at = $today->subDays($daysAgo)->setTime(10, 15);
            $this->at($at);
            $visit = PatientVisit::query()->create([
                'clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => $dentist->id,
                'registration_type' => $i === 0 ? 'new' : 'repeat', 'visit_date' => $at->toDateString(), 'visit_status' => 'completed', 'queue_status' => 'completed',
                'visit_reason' => $complaint,
            ]);
            $encounter = $workspace->startForPatient($patient, $branch, $dentist, $visit);
            $encounter = $workspace->autosave($encounter, ['chief_complaint' => $complaint], (int) $encounter->lock_version);
            foreach ($diagnoses as [$code, $description, $status]) {
                $workspace->addDiagnosis($encounter, ['code' => $code, 'description' => $description, 'status' => $status]);
            }
            $encounters[] = $encounter;
        }

        // Treatments at different stages: two plans, part done.
        $this->at($today->subDays(60)->setTime(11, 0));
        $perio = $dental->proposePlan($encounters[3], 'Full-mouth periodontal therapy with scaling and root planing in four quadrants', [
            ['clinical_procedure_id' => $catalog['srp']->id, 'tooth_number' => 16], ['clinical_procedure_id' => $catalog['srp']->id, 'tooth_number' => 26],
            ['clinical_procedure_id' => $catalog['srp']->id, 'tooth_number' => 36], ['clinical_procedure_id' => $catalog['srp']->id, 'tooth_number' => 46],
        ], null, $dentist);
        $dental->acceptPlan($perio);
        $restore = $dental->proposePlan($encounters[4], 'Anterior aesthetic restoration and zirconia crowns', [
            ['clinical_procedure_id' => $catalog['composite']->id, 'tooth_number' => 21, 'surfaces' => ['M', 'B']],
            ['clinical_procedure_id' => $catalog['crown']->id, 'tooth_number' => 11], ['clinical_procedure_id' => $catalog['crown']->id, 'tooth_number' => 22],
        ], null, $dentist);
        $dental->acceptPlan($restore);
        $implant = $dental->proposePlan($encounters[2], 'Implant-supported replacement of 36 with bone graft', [
            ['clinical_procedure_id' => $catalog['implant']->id, 'tooth_number' => 36],
        ], null, $dentist);
        $dental->acceptPlan($implant);

        $this->at($today->subDays(4)->setTime(10, 40));
        foreach ($perio->fresh()->records()->orderBy('id')->limit(3)->get() as $item) {
            $dental->completeItem($perio->fresh(), $item, $encounters[5], $dentist);
        }
        $first = $restore->fresh()->records()->orderBy('id')->first();
        $dental->completeItem($restore->fresh(), $first, $encounters[5], $dentist, 'filled');

        // Prescriptions in the last month, with long medicine names.
        $this->at($today->subDays(20)->setTime(10, 50));
        $draft = $rx->draftFor($encounters[4], $dentist);
        foreach ([
            ['drug_name' => 'Amoxicillin and Potassium Clavulanate (Augmentin Duo)', 'strength' => '625 mg', 'form' => 'Tablet', 'dosage' => '1 tablet', 'frequency' => 'BD', 'duration' => '5 days', 'timing' => 'after_food', 'route' => 'oral'],
            ['drug_name' => 'Chlorhexidine-free cetylpyridinium chloride mouth rinse', 'strength' => '0.07%', 'form' => 'Mouthwash', 'dosage' => '15 ml', 'frequency' => 'BD', 'duration' => '14 days', 'timing' => 'after_food', 'route' => 'topical'],
        ] as $line) {
            $rx->addItem($draft, $line);
        }
        $rx->finalize($draft->fresh(), $dentist);
        $this->at($today->subDays(4)->setTime(11, 5));
        $draft = $rx->draftFor($encounters[5], $dentist);
        foreach ([
            ['drug_name' => 'Paracetamol', 'strength' => '650 mg', 'form' => 'Tablet', 'dosage' => '1 tablet', 'frequency' => 'TDS', 'duration' => '3 days', 'timing' => 'after_food', 'route' => 'oral'],
            ['drug_name' => 'Metronidazole', 'strength' => '400 mg', 'form' => 'Tablet', 'dosage' => '1 tablet', 'frequency' => 'TDS', 'duration' => '5 days', 'timing' => 'after_food', 'route' => 'oral'],
            ['drug_name' => 'Benzydamine hydrochloride oral rinse', 'strength' => '0.15%', 'form' => 'Mouthwash', 'dosage' => '15 ml', 'frequency' => 'TDS', 'duration' => '7 days', 'timing' => 'after_food', 'route' => 'topical'],
        ] as $line) {
            $rx->addItem($draft, $line);
        }
        $rx->finalize($draft->fresh(), $dentist);

        // Investigations: one reported, two awaited.
        $orders = app(ClinicalOrderService::class);
        $order = $orders->placeOrder($encounters[5], ['priority' => 'routine'], [
            ['item_name' => 'Cone-beam CT (CBCT), mandible, left posterior region for implant planning', 'category' => 'imaging'],
            ['item_name' => 'HbA1c (glycated haemoglobin)', 'category' => 'lab'],
            ['item_name' => 'Serum creatinine with eGFR', 'category' => 'lab'],
        ], $dentist);
        $orders->recordResult($order->items()->where('item_name', 'HbA1c (glycated haemoglobin)')->firstOrFail(), $dentist, ['summary' => 'HbA1c 8.4% — above target; physician informed before the implant surgery.', 'is_abnormal' => true]);

        // Signed: all but the latest.
        foreach (array_slice($encounters, 0, 5) as $encounter) {
            $workspace->finalize($encounter->fresh(), $dentist);
        }

        // Appointments: upcoming, attended, missed, cancelled.
        foreach ([
            [-12, AppointmentStatus::Completed], [-40, AppointmentStatus::Completed], [-90, AppointmentStatus::NoShow], [-30, AppointmentStatus::NoShow],
            [-75, AppointmentStatus::Cancelled], [3, AppointmentStatus::Scheduled], [17, AppointmentStatus::Confirmed], [45, AppointmentStatus::Scheduled],
        ] as [$days, $status]) {
            Appointment::query()->create([
                'clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id, 'practitioner_id' => $dentist->id,
                'scheduled_at' => $today->addDays($days)->setTime(9, 30), 'duration_minutes' => 45, 'status' => $status,
            ]);
        }
        $this->at($today->subDays(4)->setTime(11, 30));
        app(FollowUps::class)->add($patient, $today->addDays(10)->toDateString(), 'Re-evaluate pocket depths after scaling and root planing and review HbA1c with the physician', $dentist, $branch->id);

        // Bills in lakhs: paid, part paid, unpaid.
        $this->at($today->subDays(150)->setTime(12, 0));
        $bill = $billing->createBill($clinic, $patient, $branch, [['description' => 'Complete upper denture with soft liner', 'quantity' => 1, 'unit_price' => 68500]], $encounters[2]);
        $payments->recordPayment($bill->fresh(), PaymentMethod::Cash, 68500);
        $this->at($today->subDays(60)->setTime(12, 0));
        $bill = $billing->createBill($clinic, $patient, $branch, [
            ['description' => 'Implant fixture with abutment and zirconia crown, tooth 36', 'quantity' => 1, 'unit_price' => 1185000.50],
            ['description' => 'Guided bone regeneration with collagen membrane', 'quantity' => 1, 'unit_price' => 245750],
        ], $encounters[3]);
        $payments->recordPayment($bill->fresh(), PaymentMethod::Upi, 875000);
        $this->at($today->subDays(4)->setTime(12, 0));
        $billing->createBill($clinic, $patient, $branch, [
            ['description' => 'Scaling and root planing, three quadrants', 'quantity' => 3, 'unit_price' => 4850],
            ['description' => 'Composite restoration, tooth 21', 'quantity' => 1, 'unit_price' => 3200],
        ], $encounters[5]);

        // Documents with long file names.
        foreach ([
            ['Cone_beam_CT_report_mandible_left_posterior_region_implant_planning_final_signed_v3.pdf', 'xray', 1],
            ['Physician_clearance_letter_Dr_K_Subramanian_Manipal_Whitefield_diabetes_and_CKD.pdf', 'other', 6],
            ['Intraoral_photographs_full_mouth_series_before_periodontal_therapy.jpg', 'other', 60],
            ['Informed_consent_implant_surgery_and_bone_graft_signed_by_patient_and_son.pdf', 'consent', 61],
            ['OPG_panoramic_radiograph.png', 'xray', 300],
        ] as [$name, $type, $daysAgo]) {
            $this->at($today->subDays($daysAgo)->setTime(13, 0));
            AttachedDocument::query()->create([
                'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'uploaded_by' => $owner->id,
                'file_name' => $name, 'file_path' => "qa-stress/{$patient->id}/{$name}", 'file_size_bytes' => 1_843_200,
                'document_type' => $type, 'scan_status' => AttachedDocument::SCAN_CLEAN, 'scanned_at' => now(),
            ]);
        }
    }

    private function typical(Clinic $clinic, Branch $branch, ClinicEmployee $dentist): void
    {
        $today = CarbonImmutable::now();
        $patient = Patient::create([
            'clinic_id' => $clinic->id, 'uhid' => self::TYPICAL_UHID, 'first_name' => 'Rohan', 'last_name' => 'Kapoor (QA Typical)',
            'phone' => '9811100022', 'gender' => 'male', 'age' => 38, 'email' => 'rohan.qa@example.test',
        ]);
        app(PatientBranchActivityRecorder::class)->record($clinic->id, $patient->id, $branch->id, $today->subMonths(2), 'registration');
        $entries = app(PatientClinicalEntries::class);
        $entries->add($patient, 'allergy', $entries->prepare($clinic->id, 'allergy', [['label' => 'Penicillin']], 'a'), 'registration', null);

        $this->at($today->subDays(14)->setTime(16, 0));
        $visit = PatientVisit::query()->create([
            'clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => $dentist->id,
            'registration_type' => 'new', 'visit_date' => now()->toDateString(), 'visit_status' => 'completed', 'queue_status' => 'completed',
        ]);
        $workspace = app(EncounterWorkspace::class);
        $encounter = $workspace->startForPatient($patient, $branch, $dentist, $visit);
        $encounter = $workspace->autosave($encounter, ['chief_complaint' => 'Sensitivity to cold'], (int) $encounter->lock_version);
        $workspace->addDiagnosis($encounter, ['code' => 'K03.8', 'description' => 'Dentine hypersensitivity', 'status' => 'confirmed']);
        $workspace->finalize($encounter->fresh(), $dentist);
        $bill = app(BillingService::class)->createBill($clinic, $patient, $branch, [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 500]], $encounter);
        app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 500);

        Appointment::query()->create([
            'clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id, 'practitioner_id' => $dentist->id,
            'scheduled_at' => $today->addDays(6)->setTime(17, 0), 'duration_minutes' => 30, 'status' => AppointmentStatus::Scheduled,
        ]);
    }

    /**
     * @return array<string, ClinicalProcedure>
     */
    private function procedures(Clinic $clinic): array
    {
        $make = function (string $code, string $name, string $category, float $price) use ($clinic): ClinicalProcedure {
            $procedure = ClinicalProcedure::query()->withoutGlobalScopes()->firstOrCreate(['clinic_id' => $clinic->id, 'code' => $code], ['name' => $name, 'category' => $category, 'is_active' => true]);
            ClinicalProcedurePrice::query()->withoutGlobalScopes()->firstOrCreate(['clinic_id' => $clinic->id, 'clinical_procedure_id' => $procedure->id], ['price' => $price]);

            return $procedure;
        };

        return [
            'srp' => $make('SRP', 'Scaling and root planing (per quadrant)', 'Periodontics', 4850),
            'composite' => $make('CMP', 'Composite restoration', 'Restorative', 3200),
            'crown' => $make('ZRC', 'Zirconia crown', 'Prosthodontics', 18500),
            'implant' => $make('IMP', 'Implant fixture with abutment and crown', 'Implantology', 118500),
        ];
    }

    private function at(CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }
}
