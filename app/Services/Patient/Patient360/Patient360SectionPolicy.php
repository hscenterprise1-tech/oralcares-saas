<?php

namespace App\Services\Patient\Patient360;

use App\ClinicPermission;
use App\Models\AttachedDocument;
use App\Models\ClinicalEncounter;
use App\Models\DeletionRequest;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Governance\DeletionGovernance;
use App\Services\OwnerMode\OwnerMode;
use App\Support\Specialty\PracticeAreas;
use App\Support\Specialty\SpecialtyRegistry;

/**
 * The one place that decides what Patient 360 shows a user, and what they
 * may do there.
 *
 * Everyone sees the same page; this decides, from the user's permissions,
 * their practitioner specialty, the clinic's specialties, branch scope and
 * Owner Mode:
 *
 * - the persona (reception, billing, clinical, dental, physio, admin, owner)
 *   that orders the workspace and picks where it opens;
 * - the sections, and which fields inside them (a receptionist gets
 *   investigation *status*, never results);
 * - the actions, and for destructive ones whether the user acts directly
 *   (the owner) or asks the owner.
 *
 * Every section endpoint asks this class again before building its payload,
 * so restricted data is never sent and merely hidden. React renders only
 * what is returned here.
 */
class Patient360SectionPolicy
{
    public const SECTIONS = [
        'overview' => ['Overview', 'primary'],
        'visits' => ['Visits & History', 'primary'],
        'clinical' => ['Clinical', 'primary'],
        'dental' => ['Dental', 'specialty'],
        'physio' => ['Physiotherapy', 'specialty'],
        'appointments' => ['Appointments', 'primary'],
        'treatment' => ['Treatment', 'primary'],
        'prescriptions' => ['Prescriptions', 'primary'],
        'investigations' => ['Investigations', 'primary'],
        'billing' => ['Billing', 'primary'],
        'documents' => ['Documents', 'primary'],
        'consents' => ['Consents', 'record'],
        'follow-ups' => ['Follow-ups', 'record'],
        'timeline' => ['Timeline', 'record'],
        'communication' => ['Messages', 'record'],
        'requests' => ['Requests', 'record'],
        'audit' => ['Audit', 'record'],
    ];

    /** Workspace order per persona; sections the user may not open are dropped. */
    private const ORDER = [
        'reception' => ['overview', 'visits', 'dental', 'appointments', 'follow-ups', 'billing', 'investigations', 'documents', 'consents', 'communication', 'timeline', 'requests'],
        'billing' => ['billing', 'dental', 'overview', 'communication', 'timeline', 'requests'],
        'dental' => ['overview', 'visits', 'dental', 'clinical', 'treatment', 'prescriptions', 'investigations', 'follow-ups', 'appointments', 'documents', 'consents', 'billing', 'timeline', 'communication', 'requests'],
        'physio' => ['overview', 'visits', 'physio', 'clinical', 'treatment', 'follow-ups', 'appointments', 'prescriptions', 'investigations', 'documents', 'consents', 'billing', 'timeline', 'communication', 'requests'],
        'clinical' => ['overview', 'visits', 'clinical', 'prescriptions', 'investigations', 'treatment', 'dental', 'physio', 'follow-ups', 'appointments', 'documents', 'consents', 'billing', 'timeline', 'communication', 'requests'],
        'admin' => ['overview', 'visits', 'clinical', 'dental', 'physio', 'appointments', 'treatment', 'prescriptions', 'investigations', 'billing', 'documents', 'consents', 'follow-ups', 'timeline', 'communication', 'requests', 'audit'],
        'owner' => ['overview', 'visits', 'clinical', 'dental', 'physio', 'appointments', 'treatment', 'prescriptions', 'investigations', 'billing', 'documents', 'consents', 'follow-ups', 'timeline', 'communication', 'requests', 'audit'],
        'staff' => ['overview', 'visits', 'appointments', 'timeline'],
    ];

    private const DEFAULT_SECTION = ['billing' => 'billing', 'dental' => 'dental', 'physio' => 'physio'];

    /** Document types that belong to the clinical record. */
    public const CLINICAL_DOCUMENT_TYPES = ['medical_report', 'xray', 'intraoral_photo', 'lab_report'];

    /** @var array<string, array<string, mixed>> */
    private array $resolved = [];

    public function __construct(
        protected ClinicContext $clinicContext,
        protected Patient360Access $access,
        protected SpecialtyRegistry $specialties,
        protected OwnerMode $ownerMode,
        protected DeletionGovernance $governance,
    ) {}

    /**
     * Everything the page needs to know about this user here.
     *
     * @return array{persona: string, sections: list<array{key: string, label: string, group: string}>, default_section: string, fields: array<string, bool>, governance: array<string, ?string>, owner: bool, owner_mode: bool, dental_view: ?string, dental_focus: ?string}
     */
    public function lens(User $user, Patient $patient): array
    {
        // Keyed to this request: controllers (and so this class) can outlive one.
        $cacheKey = spl_object_id(request()).':'.$user->id.':'.$patient->id.':'.$patient->updated_at?->getTimestamp();
        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $fields = $this->fields($user, $patient);
        $persona = $this->persona($user, $fields);
        $available = $this->availableSections($user, $fields);
        $sections = collect(self::ORDER[$persona])->filter(fn (string $key) => $available[$key] ?? false)
            ->map(fn (string $key) => ['key' => $key, 'label' => self::SECTIONS[$key][0], 'group' => self::SECTIONS[$key][1]])
            ->values()->all();
        $keys = array_column($sections, 'key');

        return $this->resolved[$cacheKey] = [
            'persona' => $persona,
            'sections' => $sections,
            'default_section' => in_array(self::DEFAULT_SECTION[$persona] ?? 'overview', $keys, true) ? (self::DEFAULT_SECTION[$persona] ?? 'overview') : 'overview',
            'fields' => $fields,
            'governance' => collect($this->governance->actions())->mapWithKeys(fn ($action, string $key) => [$key => $this->governance->modeFor($user, $key)])->all(),
            'owner' => $this->ownerMode->isOwner(),
            'owner_mode' => $this->ownerMode->authorized(),
            'dental_view' => $fields['dental'] ? 'clinical' : ($fields['dental_operational'] ? 'operational' : ($fields['dental_financial'] ? 'financial' : null)),
            'dental_focus' => $fields['dental'] ? $this->dentalFocus() : null,
        ];
    }

    /**
     * Where a dental clinician's workspace opens: the `focus` of their first
     * Dental practice area (Endodontics → root canal work, Orthodontics →
     * orthodontics); anyone else gets the general view.
     */
    private function dentalFocus(): string
    {
        $areas = app(PracticeAreas::class);
        $profile = $this->clinicContext->currentEmployee()?->practitionerProfile;
        $dental = collect($profile?->practiceAreaKeys() ?? [])->first(fn (string $key) => $areas->get($key)['module'] === 'dental');

        return $areas->get($dental)['focus'] ?? 'general';
    }

    /** May this user open this section (by page key or endpoint name)? */
    public function allows(User $user, Patient $patient, string $section): bool
    {
        $key = ['encounters' => 'clinical', 'communications' => 'communication'][$section] ?? $section;

        return in_array($key, array_column($this->lens($user, $patient)['sections'], 'key'), true);
    }

    public function field(User $user, Patient $patient, string $field): bool
    {
        return $this->lens($user, $patient)['fields'][$field] ?? false;
    }

    /**
     * A document filed against a consultation or result, or of a clinical
     * type, is part of the clinical record; anything else (an ID proof, a
     * letter) is administrative.
     */
    public function isClinicalDocument(AttachedDocument $document): bool
    {
        return $document->encounter_id !== null || $document->clinical_order_item_id !== null
            || in_array($document->document_type, self::CLINICAL_DOCUMENT_TYPES, true);
    }

    public function canOpenDocument(User $user, Patient $patient, AttachedDocument $document): bool
    {
        return $this->field($user, $patient, $this->isClinicalDocument($document) ? 'clinical_documents' : 'admin_documents');
    }

    /**
     * The actions bar, in the order they are offered. `kind`: primary (one),
     * desk (beside it on a wide screen), more (in the menu).
     *
     * @return list<array<string, mixed>>
     */
    public function actions(User $user, Patient $patient, ?ClinicalEncounter $openConsultation, int $pendingRequests): array
    {
        $lens = $this->lens($user, $patient);
        $fields = $lens['fields'];
        $persona = $lens['persona'];
        $actions = [];

        if ($openConsultation) {
            $url = route('clinic.consultations.show', $openConsultation);
            $actions[] = ['key' => 'continue_consultation', 'label' => 'Continue consultation', 'url' => $url, 'method' => 'get'];
            if ($user->hasClinicPermission(ClinicPermission::PrescriptionManage)) {
                $actions[] = ['key' => 'prescribe', 'label' => 'Write prescription', 'url' => $url.'#prescriptions', 'method' => 'get'];
            }
            $actions[] = ['key' => 'order_investigation', 'label' => 'Order investigation', 'url' => $url.'#investigations', 'method' => 'get'];
            if ($fields['dental']) {
                $actions[] = ['key' => 'treatment_plan', 'label' => 'Treatment plan', 'url' => $url.'#dental', 'method' => 'get'];
            }
        } elseif ($user->can('start', [ClinicalEncounter::class, $patient])) {
            $actions[] = ['key' => 'start_consultation', 'label' => $persona === 'physio' ? 'Start session' : 'Start consultation', 'url' => route('clinic.consultations.start-for-patient', $patient), 'method' => 'post'];
        }

        if ($user->hasClinicPermission(ClinicPermission::QueueManage)) {
            $actions[] = ['key' => 'check_in', 'label' => 'Check in', 'url' => route('clinic.reception.check-in'), 'method' => 'post'];
        }
        if ($fields['appointments'] && $user->hasClinicPermission(ClinicPermission::AppointmentCreate)) {
            $actions[] = ['key' => 'book', 'label' => 'Book appointment', 'url' => route('clinic.appointments.store'), 'method' => 'post'];
        }
        if ($fields['financial'] && $user->hasClinicPermission(ClinicPermission::PaymentCollect)) {
            $actions[] = ['key' => 'collect_payment', 'label' => 'Collect payment', 'section' => 'billing'];
        }
        if ($fields['follow_ups'] && $this->access->canManageFollowUps($user)) {
            $actions[] = ['key' => 'follow_up', 'label' => 'Add follow-up', 'section' => 'follow-ups'];
        }
        if ($this->allows($user, $patient, 'communication') && $persona === 'reception') {
            $actions[] = ['key' => 'communication', 'label' => 'Messages', 'section' => 'communication'];
        }
        if ($user->hasClinicPermission(ClinicPermission::DocumentsManage)) {
            $actions[] = ['key' => 'upload_document', 'label' => 'Upload document', 'url' => route('clinic.patients.documents.store', $patient), 'method' => 'post'];
        }
        if ($user->hasClinicPermission(ClinicPermission::PatientEdit)) {
            $actions[] = ['key' => 'edit', 'label' => 'Edit details', 'url' => route('clinic.patients.edit', $patient), 'method' => 'get'];
            $actions[] = ['key' => 'transfer', 'label' => 'Assign doctor', 'url' => route('clinic.patients.transfer', $patient), 'method' => 'post'];
        }
        // Anyone who can open the patient can print what they see (`Patient360Controller::print`).
        $actions[] = ['key' => 'print', 'label' => 'Print summary', 'url' => route('clinic.patients.print', $patient), 'method' => 'get'];
        if ($lens['owner'] || $pendingRequests > 0) {
            $actions[] = ['key' => 'approvals', 'label' => $lens['owner'] ? 'Approval queue' : 'My requests', 'url' => route('clinic.approvals.index'), 'method' => 'get', 'count' => $lens['owner'] ? $pendingRequests : null];
        }

        $primary = match ($persona) {
            'reception' => ['check_in', 'book'],
            'billing' => ['collect_payment'],
            default => ['continue_consultation', 'start_consultation', 'book', 'check_in', 'collect_payment'],
        };
        $desk = match ($persona) {
            'reception' => ['book', 'collect_payment', 'follow_up'],
            'billing' => ['communication'],
            'dental', 'physio', 'clinical' => ['prescribe', 'order_investigation', 'treatment_plan', 'follow_up'],
            default => ['book', 'check_in', 'collect_payment'],
        };

        $primaryKey = collect($primary)->first(fn (string $key) => collect($actions)->contains('key', $key));

        return collect($actions)->map(fn (array $action) => [
            ...$action,
            'kind' => $action['key'] === $primaryKey ? 'primary' : (in_array($action['key'], $desk, true) ? 'desk' : 'more'),
        ])->values()->all();
    }

    /** Open requests the owner must decide (owner) or this user made (others). */
    public function pendingRequests(User $user, Patient $patient): int
    {
        return DeletionRequest::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereIn('status', [DeletionRequest::PENDING, DeletionRequest::CLARIFICATION])
            ->when(! $this->ownerMode->isOwner(), fn ($query) => $query->where('requester_user_id', $user->id))
            ->count();
    }

    /**
     * @return array<string, bool>
     */
    private function fields(User $user, Patient $patient): array
    {
        $clinical = $this->access->canViewClinical($user);
        $workspaces = $clinical ? $this->access->specialtyWorkspaces($patient) : ['dental' => false, 'physio' => false];
        $appointments = $this->access->canViewAppointments($user);
        $queue = $user->hasClinicPermission(ClinicPermission::QueueManage);
        $financial = $this->access->canViewBilling($user);

        return [
            'clinical' => $clinical,
            'clinical_write' => $user->hasClinicPermission(ClinicPermission::ClinicalEdit),
            'medical_history' => $clinical,
            'clinical_notes' => $clinical,
            'prescriptions' => $clinical,
            'investigation_results' => $clinical,
            'investigation_status' => $clinical || $queue,
            // Visits & History: who came and when — for the desk, the calendar and clinicians.
            'visits' => $clinical || $appointments || $queue,
            'dental' => $workspaces['dental'],
            // The dental record for the front desk (teeth under treatment, lab status) and for billing (treatment, estimates).
            'dental_operational' => ! $clinical && ($appointments || $queue) && $this->access->specialtyWorkspaces($patient)['dental'],
            'dental_financial' => ! $clinical && $financial && $this->access->specialtyWorkspaces($patient)['dental'],
            'physio' => $workspaces['physio'],
            'appointments' => $appointments,
            'financial' => $financial,
            'refunds' => $financial && $user->hasClinicPermission(ClinicPermission::RefundProcess),
            'clinical_documents' => $clinical,
            'admin_documents' => $clinical || $user->hasClinicPermission(ClinicPermission::DocumentsManage) || $user->hasClinicPermission(ClinicPermission::PatientEdit),
            'follow_ups' => $clinical || $appointments,
            'communication' => $clinical || $appointments || $financial,
            'audit' => $this->access->canViewAudit($user),
        ];
    }

    /**
     * @param  array<string, bool>  $fields
     * @return array<string, bool>
     */
    private function availableSections(User $user, array $fields): array
    {
        return [
            'overview' => true,
            'clinical' => $fields['clinical'],
            'dental' => $fields['dental'] || $fields['dental_operational'] || $fields['dental_financial'],
            'physio' => $fields['physio'],
            'appointments' => $fields['appointments'],
            'treatment' => $fields['clinical'],
            'prescriptions' => $fields['prescriptions'],
            'investigations' => $fields['investigation_status'],
            'visits' => $fields['visits'],
            'billing' => $fields['financial'],
            'documents' => $fields['admin_documents'] || $fields['clinical_documents'],
            // Whoever opens the patient sees what they agreed to; recording one needs `consent.manage`.
            'consents' => true,
            'follow-ups' => $fields['follow_ups'],
            'timeline' => true,
            'communication' => $fields['communication'],
            'requests' => collect($this->governance->actions())->keys()->contains(fn (string $key) => $this->governance->modeFor($user, $key) !== null),
            'audit' => $fields['audit'],
        ];
    }

    /**
     * @param  array<string, bool>  $fields
     */
    private function persona(User $user, array $fields): string
    {
        if ($this->ownerMode->isOwner()) {
            return 'owner';
        }
        if ($user->hasClinicPermission(ClinicPermission::SettingsManage)) {
            return 'admin';
        }
        if ($fields['clinical']) {
            // What the clinician practises (their practice areas), not a free-text title.
            $extensions = $this->specialties->extensionsFor($this->clinicContext->currentEmployee()?->practitionerProfile?->specialtyKeys() ?? []);
            if ($fields['dental'] && in_array('odontogram', $extensions, true)) {
                return 'dental';
            }
            if ($fields['physio'] && array_intersect(['physio_assessment', 'soap_notes'], $extensions) !== []) {
                return 'physio';
            }

            return 'clinical';
        }
        if ($fields['financial'] && ! $fields['appointments']) {
            return 'billing';
        }
        if ($fields['appointments'] || $fields['investigation_status']) {
            return 'reception';
        }

        return 'staff';
    }
}
