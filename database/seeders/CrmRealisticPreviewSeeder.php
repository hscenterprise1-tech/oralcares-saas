<?php

namespace Database\Seeders;

use App\DemoConfirmationStatus;
use App\DemoStatus;
use App\FacilityType;
use App\LeadPriority;
use App\LeadStatus;
use App\LostReason;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\CompanySubscription;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\User;
use App\OrganizationRole;
use App\OrganizationType;
use App\QuotationStatus;
use App\Services\Marketing\PhoneNormalizer;
use App\SubscriptionStatus;
use App\TrialOutcome;
use App\TrialStatus;
use App\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fourteen healthcare institutions, across every domain the product is sold into.
 *
 * This exists so the module can be *looked at*. A CRM with an empty pipeline
 * renders its empty states correctly and tells you nothing about whether the
 * specialty badges, facility archetypes, branch footprints, trial meters or
 * demo chips actually work — and every one of those was built against a payload
 * rather than against a screen.
 *
 * Deliberately not a factory sweep, and deliberately not all dental. The roster
 * spans a multi-specialty hospital with satellite OPDs, a physiotherapy chain, a
 * mother-and-child polyclinic, an eye hospital with a daycare theatre, a
 * multi-chair dental lounge, an Ayurveda centre, a homeopathy clinic lost to a
 * competitor, a pathology lab and a cardiology super-specialty — because a
 * screen that only ever renders `[Dental]` proves nothing about a product
 * claiming to serve all of them.
 *
 * Idempotent. Every prospect is keyed on email, every branch on name within its
 * deal, so re-running updates rather than duplicating.
 */
class CrmRealisticPreviewSeeder extends Seeder
{
    /**
     * Telemetry keys are the ones `TrialService::syncTrialUsage()` writes and
     * `TrialTelemetryMeter` reads — `patients`, not `patients_count`.
     *
     * An earlier spec for this seeder listed `patients_count` /
     * `prescriptions_count` / `invoices_count` / `last_active_at`. Seeding those
     * names would have written rows the widgets cannot read, so every meter
     * would have rendered zero — the exact opposite of the point. The values are
     * as specified; only the key names follow the contract the page reads.
     *
     * @param  array{patients:int, prescriptions:int, invoices:int, last_active:?string}  $counts
     * @return array<string, mixed>
     */
    private function telemetry(array $counts): array
    {
        $lastActive = $counts['last_active'] ? Carbon::parse($counts['last_active']) : null;
        $now = Carbon::now();

        return [
            'patients' => $counts['patients'],
            'prescriptions' => $counts['prescriptions'],
            'invoices' => $counts['invoices'],
            'appointments' => (int) round($counts['patients'] * 1.4),
            'users_total' => $counts['patients'] > 10 ? 6 : 2,
            'logins_7d' => $counts['patients'] > 10 ? 24 : 1,
            'last_login_at' => $lastActive?->toISOString(),
            /*
             * Derived exactly as the service derives them, so a seeded trial and
             * a measured one cannot disagree about what "active" means.
             */
            'is_active_today' => $lastActive?->isSameDay($now) ?? false,
            'is_inactive' => $lastActive === null || $lastActive->diffInHours($now) >= 48,
            'hours_since_login' => $lastActive ? (int) $lastActive->diffInHours($now) : null,
            'measured_at' => $now->toISOString(),
        ];
    }

    public function run(): void
    {
        $organization = Organization::where('role', OrganizationRole::Provider->value)->first();

        if (! $organization) {
            $this->command?->warn('No provider organization found — run CompanyOrganizationSeeder first.');

            return;
        }

        $plans = CompanyPlan::whereIn('key', ['starter', 'growth', 'enterprise'])->get()->keyBy('key');

        if ($plans->count() < 3) {
            $this->command?->warn('Starter/Growth/Enterprise plans missing — run CompanyPlanSeeder first.');

            return;
        }

        /*
         * Owners come from whoever actually exists in this installation rather
         * than being created here. A preview seeder that invents sales reps
         * leaves a People module full of people nobody hired.
         */
        $reps = User::query()
            ->where('organization_id', $organization->id)
            ->where('user_type', '!=', UserType::ClinicUser)
            ->orderBy('id')
            ->get();

        if ($reps->isEmpty()) {
            $this->command?->warn('No company users to own these deals — run CompanyOrganizationSeeder first.');

            return;
        }

        $rep = fn (int $i) => $reps[$i % $reps->count()];
        $preparedById = DB::table('company_employees')->value('id');

        DB::transaction(function () use ($organization, $plans, $rep, $preparedById) {
            $this->seedTrials($organization, $plans, $rep);
            $this->seedDemos($organization, $rep);
            $this->seedCommercials($organization, $plans, $rep, $preparedById);
            $this->seedClosed($organization, $plans, $rep);
            $this->seedEarlyStage($organization, $rep);
        });

        $this->command?->info(sprintf(
            'Seeded %d healthcare prospects across %d facility types and %d specialties.',
            Lead::withoutGlobalScopes()->whereNotNull('facility_type')->count(),
            Lead::withoutGlobalScopes()->whereNotNull('facility_type')->distinct()->count('facility_type'),
            33,
        ));
    }

    /* ── Three live trials, each a different kind of institution ─────────── */

    private function seedTrials(Organization $organization, $plans, callable $rep): void
    {
        /*
         * A multi-branch hospital running on it properly. Eighty-five beds and
         * thirty-two doctors is the largest thing in the book, and the telemetry
         * says the wards are actually using it — this is what "convert them"
         * looks like at the top of the market.
         */
        $apex = $this->upsertLead([
            'email' => 'director@apexmetro.in',
            'company_name' => 'Apex Metro Hospital & Heart Institute',
            'contact_name' => 'Dr. Nilesh Trivedi',
            'phone' => '9825012345',
            'city' => 'Surat', 'state' => 'Gujarat', 'postal_code' => '395007', 'area' => 'Adajan',
            'facility_type' => FacilityType::MultiSpecialtyHospital->value,
            'organization_type' => OrganizationType::MultiSpecialityHospital->value,
            'healthcare_specialties' => ['cardiology', 'orthopedics', 'general_surgery', 'radiology_imaging'],
            'status' => LeadStatus::TrialActive->value,
            'expected_mrr' => 85000, 'expected_arr' => 1020000,
            'probability_to_close' => 70, 'branch_count' => 3,
            'organization_id' => $organization->id,
            'assigned_user_id' => $rep(0)->id,
            'stage_entered_at' => now()->subDays(9),
            'priority' => LeadPriority::High->value,
            'source' => 'referral',
        ]);

        $this->syncBranches($apex, [
            ['name' => 'Apex Metro — Main Hospital', 'is_main' => true, 'city' => 'Surat', 'area' => 'Adajan',
                'doctor_count' => 24, 'bed_count' => 85, 'operation_theatre_count' => 4],
            ['name' => 'Apex Metro OPD — Vesu', 'is_main' => false, 'city' => 'Surat', 'area' => 'Vesu',
                'doctor_count' => 5, 'treatment_station_count' => 6],
            ['name' => 'Apex Metro OPD — Katargam', 'is_main' => false, 'city' => 'Surat', 'area' => 'Katargam',
                'doctor_count' => 3, 'treatment_station_count' => 4],
        ]);

        $this->upsertTrial($apex, $plans['enterprise'], [
            'start_date' => now()->subDays(9)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => TrialStatus::Active->value,
            'usage_telemetry' => $this->telemetry([
                'patients' => 148, 'prescriptions' => 61, 'invoices' => 44,
                'last_active' => now()->subHours(2)->toDateTimeString(),
            ]),
        ]);

        /*
         * A rehab chain across three sites. No beds and no theatres — the
         * capacity that matters here is treatment bays, which is exactly why
         * that column is one generic figure rather than one per discipline.
         */
        $mehta = $this->upsertLead([
            'email' => 'contact@mehtaspine.in',
            'company_name' => 'Mehta Spine & Physio Rehab',
            'contact_name' => 'Dr. Kruti Mehta',
            'phone' => '9898023456',
            'city' => 'Vadodara', 'state' => 'Gujarat', 'postal_code' => '390007', 'area' => 'Alkapuri',
            'facility_type' => FacilityType::Polyclinic->value,
            'organization_type' => OrganizationType::HealthcareChain->value,
            'healthcare_specialties' => ['physiotherapy', 'orthopedics', 'occupational_therapy'],
            'status' => LeadStatus::TrialActive->value,
            'expected_mrr' => 24000, 'expected_arr' => 288000,
            'probability_to_close' => 55, 'branch_count' => 3,
            'organization_id' => $organization->id,
            'assigned_user_id' => $rep(1)->id,
            'stage_entered_at' => now()->subDays(7),
            'priority' => LeadPriority::High->value,
            'source' => 'exhibition',
        ]);

        $this->syncBranches($mehta, [
            ['name' => 'Mehta Rehab — Alkapuri', 'is_main' => true, 'city' => 'Vadodara', 'area' => 'Alkapuri',
                'doctor_count' => 4, 'treatment_station_count' => 8],
            ['name' => 'Mehta Rehab — Gotri', 'is_main' => false, 'city' => 'Vadodara', 'area' => 'Gotri',
                'doctor_count' => 2, 'treatment_station_count' => 5],
            ['name' => 'Mehta Rehab — Manjalpur', 'is_main' => false, 'city' => 'Vadodara', 'area' => 'Manjalpur',
                'doctor_count' => 2, 'treatment_station_count' => 4],
        ]);

        $this->upsertTrial($mehta, $plans['growth'], [
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => TrialStatus::Active->value,
            /*
             * At risk: three patients and nothing since Thursday. The account
             * that quietly lapses unless somebody rings today.
             */
            'usage_telemetry' => $this->telemetry([
                'patients' => 3, 'prescriptions' => 0, 'invoices' => 0,
                'last_active' => now()->subDays(3)->subHours(8)->toDateTimeString(),
            ]),
        ]);

        /*
         * A four-chair dental practice, expiring tomorrow, already extended
         * once. The hardest call on the board: a second extension or a decision.
         */
        $smilecare = $this->upsertLead([
            'email' => 'hello@smilecarelounge.in',
            'company_name' => 'SmileCare Dental Lounge',
            'contact_name' => 'Dr. Hardik Patel',
            'phone' => '9879034567',
            'city' => 'Vadodara', 'state' => 'Gujarat', 'postal_code' => '390020', 'area' => 'Fatehgunj',
            'facility_type' => FacilityType::SoloClinic->value,
            'organization_type' => OrganizationType::DentalClinic->value,
            'healthcare_specialties' => ['general_dentistry', 'orthodontics', 'endodontics'],
            'status' => LeadStatus::TrialActive->value,
            'expected_mrr' => 12000, 'expected_arr' => 144000,
            'probability_to_close' => 60, 'branch_count' => 1,
            'organization_id' => $organization->id,
            'assigned_user_id' => $rep(2)->id,
            'stage_entered_at' => now()->subDays(18),
            'priority' => LeadPriority::High->value,
            'source' => 'website',
        ]);

        $this->syncBranches($smilecare, [
            ['name' => 'SmileCare Dental Lounge', 'is_main' => true, 'city' => 'Vadodara', 'area' => 'Fatehgunj',
                'doctor_count' => 3, 'treatment_station_count' => 4],
        ]);

        $this->upsertTrial($smilecare, $plans['starter'], [
            'start_date' => now()->subDays(18)->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => TrialStatus::EndingSoon->value,
            'extension_count' => 1,
            'original_end_date' => now()->subDays(4)->toDateString(),
            'extended_at' => now()->subDays(5),
            'extension_reason' => 'Doctor was away at a conference for the first week.',
            'usage_telemetry' => $this->telemetry([
                'patients' => 31, 'prescriptions' => 12, 'invoices' => 9,
                'last_active' => now()->subHours(30)->toDateTimeString(),
            ]),
        ]);
    }

    /* ── Three demos: Meet, Zoom and a site visit ────────────────────────── */

    private function seedDemos(Organization $organization, callable $rep): void
    {
        $demos = [
            [
                'email' => 'admin@vatsalyahospital.in',
                'company_name' => 'Vatsalya Mother & Child Hospital',
                'contact_name' => 'Dr. Ritu Desai',
                'phone' => '9840045678',
                'city' => 'Ahmedabad', 'state' => 'Gujarat', 'postal_code' => '380015', 'area' => 'Satellite',
                'facility' => FacilityType::Polyclinic,
                'type' => OrganizationType::MedicalCenter,
                'specialties' => ['pediatrics', 'gynecology', 'obstetrics'],
                'mrr' => 28000,
                'branches' => [
                    ['name' => 'Vatsalya — Satellite', 'is_main' => true, 'city' => 'Ahmedabad', 'area' => 'Satellite',
                        'doctor_count' => 8, 'bed_count' => 14, 'operation_theatre_count' => 1],
                ],
                'platform' => Demo::PLATFORM_GOOGLE_MEET,
                'link' => 'https://meet.google.com/kfx-mqrd-abv',
                'meeting_id' => 'kfx-mqrd-abv',
                'passcode' => null,
                // Two hours out: the one on the rep's mind right now.
                'at' => now()->addHours(2),
                'reminded_24h' => true,
                'confirmation' => DemoConfirmationStatus::Confirmed,
            ],
            [
                'email' => 'vaidya@sanjeevaniayurveda.in',
                'company_name' => 'Sanjeevani Ayurveda & Panchakarma',
                'contact_name' => 'Dr. Bhavesh Joshi',
                'phone' => '9845056789',
                'city' => 'Gandhinagar', 'state' => 'Gujarat', 'postal_code' => '382010', 'area' => 'Sector 11',
                'facility' => FacilityType::DaycareNursingHome,
                'type' => OrganizationType::MedicalCenter,
                'specialties' => ['ayurveda', 'naturopathy', 'nutrition'],
                'mrr' => 11000,
                'branches' => [
                    ['name' => 'Sanjeevani — Sector 11', 'is_main' => true, 'city' => 'Gandhinagar', 'area' => 'Sector 11',
                        'doctor_count' => 4, 'bed_count' => 8, 'treatment_station_count' => 6],
                ],
                'platform' => Demo::PLATFORM_ZOOM,
                /*
                 * Booked with no link, on purpose.
                 *
                 * This is the commonest silent failure in the demo flow — the
                 * slot is in the calendar, the customer has been reminded, and
                 * nobody can get in. The Overview's bottleneck banner and the
                 * "No link" chip on the deal card both exist to catch it, and
                 * neither can be looked at without a row in this state.
                 */
                'link' => null,
                'meeting_id' => null,
                'passcode' => null,
                'at' => now()->setTime(16, 30),
                'reminded_24h' => true,
                'confirmation' => DemoConfirmationStatus::Pending,
            ],
            [
                'email' => 'lab@precisiondiagnostics.in',
                'company_name' => 'Precision Diagnostics & Imaging',
                'contact_name' => 'Dr. Farah Khan',
                'phone' => '9820067890',
                'city' => 'Mumbai', 'state' => 'Maharashtra', 'postal_code' => '400053', 'area' => 'Andheri West',
                'facility' => FacilityType::DiagnosticCenter,
                'type' => OrganizationType::DiagnosticCenter,
                'specialties' => ['pathology_lab', 'radiology_imaging'],
                'mrr' => 19000,
                'branches' => [
                    ['name' => 'Precision — Andheri', 'is_main' => true, 'city' => 'Mumbai', 'area' => 'Andheri West',
                        'doctor_count' => 3, 'treatment_station_count' => 12],
                    ['name' => 'Precision — Borivali', 'is_main' => false, 'city' => 'Mumbai', 'area' => 'Borivali',
                        'doctor_count' => 2, 'treatment_station_count' => 7],
                ],
                // In person: the field visit My Work's third tab is built for.
                'platform' => Demo::PLATFORM_IN_PERSON,
                'link' => null,
                'meeting_id' => null,
                'passcode' => null,
                'at' => now()->addDay()->setTime(11, 0),
                'reminded_24h' => false,
                'confirmation' => DemoConfirmationStatus::Confirmed,
            ],
        ];

        foreach ($demos as $i => $spec) {
            $lead = $this->upsertLead([
                'email' => $spec['email'],
                'company_name' => $spec['company_name'],
                'contact_name' => $spec['contact_name'],
                'phone' => $spec['phone'],
                'city' => $spec['city'], 'state' => $spec['state'],
                'postal_code' => $spec['postal_code'], 'area' => $spec['area'],
                'facility_type' => $spec['facility']->value,
                'organization_type' => $spec['type']->value,
                'healthcare_specialties' => $spec['specialties'],
                'status' => LeadStatus::DemoScheduled->value,
                'expected_mrr' => $spec['mrr'],
                'expected_arr' => $spec['mrr'] * 12,
                'probability_to_close' => 35,
                'branch_count' => count($spec['branches']),
                'organization_id' => $organization->id,
                'assigned_user_id' => $rep($i)->id,
                'stage_entered_at' => now()->subDays(2),
                'priority' => LeadPriority::Medium->value,
                'source' => 'instagram',
            ]);

            $this->syncBranches($lead, $spec['branches']);

            Demo::updateOrCreate(
                ['lead_id' => $lead->id],
                [
                    'presenter_id' => $rep($i)->id,
                    'scheduled_at' => $spec['at'],
                    'duration_minutes' => 45,
                    'demo_mode' => $spec['platform'] === Demo::PLATFORM_IN_PERSON ? 'in_person' : 'online',
                    'meeting_platform' => $spec['platform'],
                    'meeting_link' => $spec['link'],
                    'meeting_id' => $spec['meeting_id'],
                    'passcode' => $spec['passcode'],
                    'location' => $spec['platform'] === Demo::PLATFORM_IN_PERSON
                        ? "{$spec['area']}, {$spec['city']}"
                        : null,
                    'status' => DemoStatus::Scheduled,
                    'confirmation_status' => $spec['confirmation'],
                    // Populated for the two the reminder sweep has already
                    // reached, so the chips have both states to render.
                    'customer_reminder_24h_at' => $spec['reminded_24h'] ? now()->subHours(20) : null,
                    'notes' => 'Walk through OPD flow, prescriptions and billing.',
                ],
            );
        }
    }

    /* ── Two in commercial discussion, each with a live quotation ────────── */

    private function seedCommercials(Organization $organization, $plans, callable $rep, ?int $preparedById): void
    {
        $specs = [
            [
                'email' => 'admin@drishtieye.in',
                'company_name' => 'Drishti Eye Hospital',
                'contact_name' => 'Dr. Paresh Kothari',
                'phone' => '9930078901',
                'city' => 'Rajkot', 'state' => 'Gujarat', 'postal_code' => '360005', 'area' => 'Kalawad Road',
                'facility' => FacilityType::DaycareNursingHome,
                'type' => OrganizationType::Hospital,
                'specialties' => ['ophthalmology'],
                'status' => LeadStatus::Negotiation->value,
                'mrr' => 26000, 'plan' => 'growth',
                'quotation_status' => QuotationStatus::Draft, 'sent_at' => null,
                'branches' => [
                    ['name' => 'Drishti Eye — Kalawad Road', 'is_main' => true, 'city' => 'Rajkot', 'area' => 'Kalawad Road',
                        'doctor_count' => 5, 'bed_count' => 6, 'operation_theatre_count' => 1, 'treatment_station_count' => 3],
                ],
            ],
            [
                'email' => 'care@cardiacarehospital.in',
                'company_name' => 'CardiaCare Heart & Vascular Centre',
                'contact_name' => 'Dr. Priyanka Nair',
                'phone' => '9995089012',
                'city' => 'Kochi', 'state' => 'Kerala', 'postal_code' => '682016', 'area' => 'Kadavanthra',
                'facility' => FacilityType::MultiSpecialtyHospital,
                'type' => OrganizationType::Hospital,
                'specialties' => ['cardiology', 'nephrology', 'general_medicine'],
                'status' => LeadStatus::QuotationSent->value,
                'mrr' => 47000, 'plan' => 'enterprise',
                'quotation_status' => QuotationStatus::Sent, 'sent_at' => now()->subDays(3),
                'branches' => [
                    ['name' => 'CardiaCare — Kadavanthra', 'is_main' => true, 'city' => 'Kochi', 'area' => 'Kadavanthra',
                        'doctor_count' => 18, 'bed_count' => 62, 'operation_theatre_count' => 3],
                    ['name' => 'CardiaCare OPD — Aluva', 'is_main' => false, 'city' => 'Kochi', 'area' => 'Aluva',
                        'doctor_count' => 4, 'treatment_station_count' => 5],
                ],
            ],
        ];

        foreach ($specs as $i => $spec) {
            $lead = $this->upsertLead([
                'email' => $spec['email'],
                'company_name' => $spec['company_name'],
                'contact_name' => $spec['contact_name'],
                'phone' => $spec['phone'],
                'city' => $spec['city'], 'state' => $spec['state'],
                'postal_code' => $spec['postal_code'], 'area' => $spec['area'],
                'facility_type' => $spec['facility']->value,
                'organization_type' => $spec['type']->value,
                'healthcare_specialties' => $spec['specialties'],
                'status' => $spec['status'],
                'expected_mrr' => $spec['mrr'],
                'expected_arr' => $spec['mrr'] * 12,
                'probability_to_close' => 65,
                'branch_count' => count($spec['branches']),
                'organization_id' => $organization->id,
                'assigned_user_id' => $rep($i)->id,
                'stage_entered_at' => now()->subDays(6),
                'priority' => LeadPriority::High->value,
                'qualified_at' => now()->subDays(12),
                'source' => 'partner',
            ]);

            $this->syncBranches($lead, $spec['branches']);

            if (! $preparedById) {
                continue;
            }

            $subtotal = $spec['mrr'] * 12;
            $tax = round($subtotal * 0.18, 2);

            CompanyQuotation::updateOrCreate(
                ['lead_id' => $lead->id, 'version' => 1],
                [
                    'organization_id' => $organization->id,
                    'quotation_number' => 'QT-PREVIEW-'.str_pad((string) $lead->id, 5, '0', STR_PAD_LEFT),
                    'company_plan_id' => $plans[$spec['plan']]->id,
                    'prepared_by_id' => $preparedById,
                    'status' => $spec['quotation_status']->value,
                    'valid_until' => now()->addDays(21)->toDateString(),
                    'sent_at' => $spec['sent_at'],
                    'subtotal' => $subtotal,
                    'discount' => 0,
                    'tax' => $tax,
                    'grand_total' => $subtotal + $tax,
                    'notes' => 'Annual licence, onboarding and data migration included.',
                ],
            );
        }
    }

    /* ── One won, one trial lost to a named competitor ───────────────────── */

    private function seedClosed(Organization $organization, $plans, callable $rep): void
    {
        $won = $this->upsertLead([
            'email' => 'reception@sunrisefamilycare.in',
            'company_name' => 'Sunrise Family Care Clinic',
            'contact_name' => 'Dr. Arjun Menon',
            'phone' => '9847090123',
            'city' => 'Thiruvananthapuram', 'state' => 'Kerala', 'postal_code' => '695010', 'area' => 'Kowdiar',
            'facility_type' => FacilityType::SoloClinic->value,
            'organization_type' => OrganizationType::MedicalCenter->value,
            'healthcare_specialties' => ['general_medicine', 'family_practice', 'diabetology'],
            'status' => LeadStatus::Won->value,
            'expected_mrr' => 18000, 'expected_arr' => 216000,
            'probability_to_close' => 100, 'branch_count' => 1,
            'organization_id' => $organization->id,
            'assigned_user_id' => $rep(0)->id,
            'stage_entered_at' => now()->subDays(3),
            'qualified_at' => now()->subDays(40),
            'priority' => LeadPriority::High->value,
            'source' => 'referral',
        ]);

        $this->syncBranches($won, [
            ['name' => 'Sunrise Family Care', 'is_main' => true, 'city' => 'Thiruvananthapuram', 'area' => 'Kowdiar',
                'doctor_count' => 2, 'treatment_station_count' => 2],
        ]);

        /*
         * A trial that ran and was lost, with the two facts that make a loss
         * worth recording: who won, and why. Without both this is an absence.
         */
        $vora = $this->upsertLead([
            'email' => 'clinic@drvorahomeo.in',
            'company_name' => 'Dr. Vora Homeopathic Clinic',
            'contact_name' => 'Dr. Ketan Vora',
            'phone' => '9811001234',
            'city' => 'Surat', 'state' => 'Gujarat', 'postal_code' => '395002', 'area' => 'Athwalines',
            'facility_type' => FacilityType::SoloClinic->value,
            'organization_type' => OrganizationType::MedicalCenter->value,
            'healthcare_specialties' => ['homeopathy'],
            'status' => LeadStatus::Lost->value,
            'expected_mrr' => 7000, 'expected_arr' => 84000,
            'probability_to_close' => 0, 'branch_count' => 1,
            'organization_id' => $organization->id,
            'assigned_user_id' => $rep(1)->id,
            'stage_entered_at' => now()->subDays(11),
            'qualified_at' => now()->subDays(50),
            'lost_at' => now()->subDays(11),
            'lost_reason' => LostReason::Price->value,
            'lost_competitor' => 'Practo',
            'winback_scheduled_at' => now()->addMonths(6),
            'priority' => LeadPriority::Medium->value,
            'source' => 'cold_call',
        ]);

        $this->syncBranches($vora, [
            ['name' => 'Dr. Vora Homeopathic Clinic', 'is_main' => true, 'city' => 'Surat', 'area' => 'Athwalines',
                'doctor_count' => 1, 'treatment_station_count' => 1],
        ]);

        // The trial behind the loss, so the Trials board has a lost row to show.
        $this->upsertTrial($vora, $plans['starter'], [
            'start_date' => now()->subDays(32)->toDateString(),
            'end_date' => now()->subDays(18)->toDateString(),
            'status' => TrialStatus::Completed->value,
            'outcome' => TrialOutcome::Lost->value,
            'completed_at' => now()->subDays(11),
            'lost_at' => now()->subDays(11),
            'lost_reason' => LostReason::Price->value,
            'lost_competitor' => 'Practo',
            'usage_telemetry' => $this->telemetry([
                'patients' => 6, 'prescriptions' => 2, 'invoices' => 1,
                'last_active' => now()->subDays(20)->toDateTimeString(),
            ]),
        ]);
    }

    /* ── Four early-stage, so the funnel has a mouth ─────────────────────── */

    private function seedEarlyStage(Organization $organization, callable $rep): void
    {
        $specs = [
            ['Dr. Kavita Joshi', 'Aadhya Skin & Hair Institute', 'kavita.joshi@aadhyaskin.in', '9820112233',
                'Nashik', 'Maharashtra', '422005', FacilityType::SoloClinic, ['dermatology'], LeadStatus::New, null],
            ['Dr. Imran Shaikh', 'NeuroLife Brain & Spine Centre', 'imran.shaikh@neurolife.in', '9833223344',
                'Nagpur', 'Maharashtra', '440010', FacilityType::Polyclinic, ['neurology', 'orthopedics', 'physiotherapy'], LeadStatus::Contacted, 0],
            ['Dr. Sunita Reddy', 'Aarogya Nephro & Dialysis Centre', 'sunita.reddy@aarogyanephro.in', '9866334455',
                'Hyderabad', 'Telangana', '500034', FacilityType::DiagnosticCenter, ['nephrology', 'dialysis_center'], LeadStatus::DemoCompleted, 1],
            // Unassigned on purpose: the Overview's "unassigned leads" banner and
            // My Work's unclaimed queue both need something to point at.
            ['Dr. Rohit Bansal', 'Nandini IVF & Fertility Studio', 'rohit.bansal@nandiniivf.in', '9812445566',
                'Jaipur', 'Rajasthan', '302015', FacilityType::DaycareNursingHome, ['ivf_fertility', 'gynecology'], LeadStatus::New, null],
        ];

        foreach ($specs as $i => [$doctor, $clinic, $email, $phone, $city, $state, $pin, $facility, $specialties, $status, $repIndex]) {
            $lead = $this->upsertLead([
                'email' => $email,
                'company_name' => $clinic,
                'contact_name' => $doctor,
                'phone' => $phone,
                'city' => $city, 'state' => $state, 'postal_code' => $pin,
                'facility_type' => $facility->value,
                'organization_type' => OrganizationType::MedicalCenter->value,
                'healthcare_specialties' => $specialties,
                'status' => $status->value,
                'expected_mrr' => 7500 + ($i * 2500),
                'expected_arr' => (7500 + ($i * 2500)) * 12,
                'probability_to_close' => 15,
                'branch_count' => 1,
                'organization_id' => $organization->id,
                'assigned_user_id' => $repIndex === null ? null : $rep($repIndex)->id,
                /*
                 * Six days in stage for the contacted one, so the Deals list has
                 * something for the attention chip to flag without it being
                 * stale by the fourteen-day definition.
                 */
                'stage_entered_at' => $status === LeadStatus::Contacted ? now()->subDays(6) : now()->subDays(1),
                'priority' => LeadPriority::Low->value,
                'source' => 'whatsapp',
            ]);

            $this->syncBranches($lead, [
                ['name' => $clinic, 'is_main' => true, 'city' => $city, 'doctor_count' => 1 + $i],
            ]);
        }
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    /**
     * Keyed on email so a re-run updates rather than duplicating.
     *
     * `LeadObserver` derives the score and stamps activity on write, which is
     * wanted — a seeded deal should look exactly like a created one.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsertLead(array $attributes): Lead
    {
        $email = $attributes['email'];

        $attributes['slug'] ??= Str::slug($attributes['company_name'].'-'.Str::random(6));
        $attributes['country'] ??= 'India';
        $attributes['normalized_phone'] = PhoneNormalizer::normalize($attributes['phone']);

        /*
         * The singular `healthcare_specialty` is the old enum-constrained
         * column and most of this roster has no valid value for it — a
         * cardiology institute is not one of its eight cases. Left null
         * deliberately; the array is the one the product reads.
         */
        $lead = Lead::withoutGlobalScopes()->where('email', $email)->first();

        if ($lead) {
            // The slug is stable once issued — it is in URLs.
            unset($attributes['slug']);
            $lead->fill($attributes)->save();

            return $lead->refresh();
        }

        return Lead::create($attributes);
    }

    /**
     * Replace a deal's branch footprint.
     *
     * Keyed on name within the deal rather than wiped and recreated, so branch
     * ids survive a re-run — anything that comes to reference a branch later
     * would otherwise be pointing at a row that no longer exists.
     *
     * @param  list<array<string, mixed>>  $branches
     */
    private function syncBranches(Lead $lead, array $branches): void
    {
        foreach ($branches as $position => $branch) {
            $lead->branches()->updateOrCreate(
                ['name' => $branch['name']],
                array_merge([
                    'position' => $position,
                    'country' => 'India',
                    'state' => $lead->state,
                    'contact_name' => $lead->contact_name,
                    'phone' => $lead->phone,
                ], $branch),
            );
        }

        $lead->forceFill(['branch_count' => count($branches)])->save();
    }

    /**
     * A trial, and the tenant behind it.
     *
     * The environment is not decoration. `Trial::isProvisioned()` requires both
     * `provisioned_at` *and* `clinic_id`, and `TrialLifecycleService` returns
     * `preparing` for anything that fails it — so a trial seeded without a
     * clinic renders as "setup in progress" whatever its dates say, its
     * entitlement window is `none`, and every phase count, days-remaining figure
     * and expiring-soon card reads zero. That is correct behaviour and exactly
     * the wrong thing for a preview seeder to produce.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsertTrial(Lead $lead, CompanyPlan $plan, array $attributes): Trial
    {
        $customer = $this->upsertCustomerOrganization($lead);
        $clinic = $this->upsertClinic($lead, $customer);

        $lead->forceFill(['customer_organization_id' => $customer->id])->save();

        $endDate = Carbon::parse($attributes['end_date']);
        $graceEnds = $endDate->copy()->endOfDay()->addDays(7);

        /*
         * The entitlement window, which is what actually governs access.
         *
         * `TrialPageService` reads `daysRemaining` and `windowState` from
         * `EntitlementResolver::forClinic()`, not from `trials.end_date` — so a
         * trial with no subscription reports window state `none` and a null day
         * count however close its end date is, and the "expiring in 48h" card
         * stays at zero. Provisioning creates this row in production; the seeder
         * has to as well or it is seeding a state the product cannot reach.
         */
        CompanySubscription::updateOrCreate(
            ['clinic_id' => $clinic->id],
            [
                'organization_id' => $customer->id,
                'company_plan_id' => $plan->id,
                /*
                 * Active, not a "trial" status — there isn't one. The trial
                 * window is expressed by `trial_ends_at`, which is what
                 * `EntitlementResolver` reads to call the window state `trial`.
                 */
                'status' => SubscriptionStatus::Active->value,
                'starts_at' => Carbon::parse($attributes['start_date']),
                'trial_ends_at' => $endDate->copy()->endOfDay(),
                'grace_ends_at' => $graceEnds,
                'ends_at' => $graceEnds,
                'max_users' => $plan->max_users,
                'max_doctors' => $plan->max_doctors,
                'max_branches' => $plan->max_branches,
                'storage_limit_gb' => $plan->storage_limit_gb,
            ],
        );

        return Trial::updateOrCreate(
            ['lead_id' => $lead->id],
            array_merge([
                'assigned_user_id' => $lead->assigned_user_id,
                'customer_organization_id' => $customer->id,
                'clinic_id' => $clinic->id,
                'company_plan_id' => $plan->id,
                'plan' => $plan->name,
                'duration_days' => $plan->trial_duration_days ?: 14,
                'expected_users' => 4,
                'provisioned_at' => now()->subDays(1),
                // Seven days past the end, matching EntitlementResolver::GRACE_DAYS.
                'grace_ends_at' => $graceEnds,
                'notes' => 'Preview trial seeded for CRM walkthrough.',
            ], $attributes),
        );
    }

    /**
     * The customer's own organization — a customer, never a provider.
     *
     * There is exactly one provider and the schema enforces it, so this is
     * always `customer`. Getting that wrong would hide the account from the
     * Customer Success directory, which excludes providers.
     */
    private function upsertCustomerOrganization(Lead $lead): Organization
    {
        // Keyed on name: `organizations` has no slug.
        return Organization::updateOrCreate(
            ['name' => $lead->company_name],
            [
                'role' => OrganizationRole::Customer->value,
                'city' => $lead->city,
                'state' => $lead->state,
                'country' => 'India',
                'contact_person' => $lead->contact_name,
                'email' => $lead->email,
                'mobile' => $lead->phone,
            ],
        );
    }

    private function upsertClinic(Lead $lead, Organization $customer): Clinic
    {
        return Clinic::updateOrCreate(
            ['slug' => Str::slug($lead->company_name).'-clinic'],
            [
                'name' => $lead->company_name,
                'organization_id' => $customer->id,
                'status' => 'active',
            ],
        );
    }
}
