<?php

namespace App\Observers\Security;

use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalEncounterAddendum;
use App\Models\ClinicalNote;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\ConsentRecord;
use App\Models\DiagnosticResult;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingItem;
use App\Models\PatientBillingPayment;
use App\Models\PatientBillingRefund;
use App\Models\PatientProcedure;
use App\Models\PatientSessionPackage;
use App\Models\PatientToothStatusHistory;
use App\Models\PhysioAssessment;
use App\Models\PhysioCarePlan;
use App\Models\PhysioSession;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Services\Security\SecurityAudit;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Puts clinical and financial record changes into the security trail
 * (docs/security/audit-events.md).
 *
 * It records the minimum needed to trace a change without copying medical
 * content into the audit log:
 *   - clinical and personal fields: only the names of the fields that
 *     changed, never their values;
 *   - status fields: the old and new value (e.g. draft → cancelled);
 *   - money fields: the old and new amounts.
 *
 * Specific events such as `billing.invoice.cancelled` or
 * `encounter.amended` are recorded in addition to the generic `.updated`, so
 * the trail can be searched by what happened, not only by what changed.
 */
class ClinicalAuditObserver
{
    /**
     * @var array<class-string, array{prefix: string, status?: array<int, string>, money?: array<int, string>, personal?: array<int, string>}>
     */
    public const SUBJECTS = [
        Patient::class => [
            'prefix' => 'patient',
            'status' => ['status'],
            'personal' => ['first_name', 'middle_name', 'last_name', 'name', 'phone', 'alternate_phone', 'email', 'date_of_birth', 'age', 'gender', 'blood_group', 'uhid'],
        ],
        ClinicalEncounter::class => ['prefix' => 'encounter', 'status' => ['status']],
        ClinicalDiagnosis::class => ['prefix' => 'encounter.diagnosis', 'status' => ['status', 'is_primary']],
        PatientProcedure::class => ['prefix' => 'encounter.procedure', 'status' => ['status', 'quantity']],
        ClinicalNote::class => ['prefix' => 'encounter.note', 'status' => ['status', 'note_type']],
        ClinicalEncounterAddendum::class => ['prefix' => 'encounter.addendum', 'status' => ['kind', 'section']],
        Prescription::class => ['prefix' => 'prescription', 'status' => ['status']],
        PatientToothStatusHistory::class => ['prefix' => 'dental.tooth', 'status' => ['tooth_number', 'kind', 'status']],
        TreatmentPlan::class => ['prefix' => 'dental.plan', 'status' => ['status']],
        TreatmentRecord::class => ['prefix' => 'dental.plan_item', 'status' => ['status', 'tooth_number']],
        PhysioAssessment::class => ['prefix' => 'physio.assessment', 'status' => ['pain_at_rest', 'pain_on_activity']],
        PhysioSession::class => ['prefix' => 'physio.session', 'status' => ['pain_before', 'pain_after', 'package_id']],
        PhysioCarePlan::class => ['prefix' => 'physio.plan', 'status' => ['status']],
        PatientSessionPackage::class => ['prefix' => 'physio.package', 'status' => ['status', 'sessions_used']],
        ClinicalOrder::class => ['prefix' => 'investigation.order', 'status' => ['status', 'priority']],
        ClinicalOrderItem::class => ['prefix' => 'investigation.item', 'status' => ['status', 'category']],
        DiagnosticResult::class => ['prefix' => 'investigation.result', 'status' => ['status', 'is_abnormal']],
        PrescriptionItem::class => ['prefix' => 'prescription.line', 'status' => ['frequency', 'duration']],
        PatientBilling::class => ['prefix' => 'billing.invoice', 'status' => ['status'], 'money' => ['total_amount', 'discount_amount', 'tax_amount', 'final_amount', 'paid_amount', 'refunded_amount', 'outstanding_balance']],
        PatientBillingItem::class => ['prefix' => 'billing.line', 'money' => ['unit_price', 'discount_amount', 'tax_amount', 'final_amount']],
        Invoice::class => ['prefix' => 'billing.invoice', 'status' => ['status'], 'money' => ['subtotal_amount', 'tax_amount', 'discount_amount', 'total_amount', 'amount_paid', 'balance_due']],
        PatientBillingPayment::class => ['prefix' => 'billing.payment', 'money' => ['amount'], 'status' => ['payment_method']],
        PatientBillingRefund::class => ['prefix' => 'billing.refund', 'money' => ['amount']],
        ConsentRecord::class => ['prefix' => 'consent', 'status' => ['status', 'consent_type']],
    ];

    public function __construct(private readonly SecurityAudit $audit) {}

    public function created(Model $model): void
    {
        $spec = $this->spec($model);
        $context = [];

        foreach ([...($spec['status'] ?? []), ...($spec['money'] ?? [])] as $field) {
            $context[$field] = $this->plain($model->getAttribute($field));
        }

        $this->record($model, "{$spec['prefix']}.created", $context);
    }

    public function updated(Model $model): void
    {
        $spec = $this->spec($model);
        $changed = array_values(array_diff(array_keys($model->getChanges()), ['updated_at']));

        if ($changed === []) {
            return;
        }

        $context = ['changed_fields' => $changed];

        foreach ([...($spec['status'] ?? []), ...($spec['money'] ?? [])] as $field) {
            if ($model->wasChanged($field)) {
                $context['from'][$field] = $this->plain($model->getOriginal($field));
                $context['to'][$field] = $this->plain($model->getAttribute($field));
            }
        }

        $personal = array_values(array_intersect($changed, $spec['personal'] ?? []));

        if ($personal !== []) {
            $context['personal_fields_changed'] = $personal;
        }

        $this->record($model, "{$spec['prefix']}.updated", $context);

        foreach ($this->specificEvents($model, $spec) as $event) {
            $this->record($model, $event, $context);
        }
    }

    public function deleted(Model $model): void
    {
        $this->record($model, $this->spec($model)['prefix'].'.deleted', []);
    }

    /**
     * @param  array{prefix: string}  $spec
     * @return array<int, string>
     */
    private function specificEvents(Model $model, array $spec): array
    {
        $from = $model->wasChanged('status') ? $this->plain($model->getOriginal('status')) : null;
        $to = $model->wasChanged('status') ? $this->plain($model->getAttribute('status')) : null;
        $events = [];

        switch ($spec['prefix']) {
            case 'encounter':
                if ($to === 'completed') {
                    $events[] = 'encounter.completed';
                } elseif ($this->plain($model->getOriginal('status')) === 'completed') {
                    // A change to an encounter that had already been completed.
                    $events[] = 'encounter.amended';
                }
                break;

            case 'investigation.item':
                if (in_array($to, ['reviewed', 'cancelled'], true)) {
                    $events[] = "investigation.{$to}";
                }
                break;

            case 'prescription':
                if (in_array($to, ['finalized', 'issued', 'signed'], true)) {
                    $events[] = 'prescription.finalized';
                } elseif ($to === 'cancelled') {
                    $events[] = 'prescription.cancelled';
                }
                break;

            case 'billing.invoice':
                if ($to === 'cancelled') {
                    $events[] = 'billing.invoice.cancelled';
                }
                if ($to === 'refunded') {
                    $events[] = 'billing.invoice.refunded';
                }
                if ($model->wasChanged('discount_amount')) {
                    $events[] = 'billing.invoice.adjusted';
                }
                break;

            case 'consent':
                if (in_array($to, ['granted', 'signed', 'approved', 'active'], true)) {
                    $events[] = 'consent.approved';
                } elseif (in_array($to, ['revoked', 'withdrawn'], true)) {
                    $events[] = 'consent.revoked';
                }
                break;
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(Model $model, string $event, array $context): void
    {
        $this->audit->record($event, $model, $context + $this->owner($model), ['clinic_id' => $this->clinicId($model)]);
    }

    /**
     * The patient a record belongs to, by id only, so the trail can answer
     * "who touched this patient's records" without holding their details.
     *
     * @return array<string, int>
     */
    private function owner(Model $model): array
    {
        if ($model instanceof Patient) {
            return [];
        }

        $patientId = $model->getAttribute('patient_id')
            ?? ($model instanceof PatientBillingPayment || $model instanceof PatientBillingRefund
                ? PatientBilling::query()->withoutGlobalScopes()->whereKey($model->getAttribute('patient_billing_id'))->value('patient_id')
                : null);

        return $patientId ? ['patient_id' => (int) $patientId] : [];
    }

    private function clinicId(Model $model): ?int
    {
        if ($model->getAttribute('clinic_id')) {
            return (int) $model->getAttribute('clinic_id');
        }

        if ($model instanceof PatientBillingPayment || $model instanceof PatientBillingRefund) {
            return PatientBilling::query()->withoutGlobalScopes()->whereKey($model->getAttribute('patient_billing_id'))->value('clinic_id');
        }

        if ($model->getAttribute('patient_id')) {
            return Patient::query()->withoutGlobalScopes()->whereKey($model->getAttribute('patient_id'))->value('clinic_id');
        }

        return null;
    }

    /**
     * @return array{prefix: string, status?: array<int, string>, money?: array<int, string>, personal?: array<int, string>}
     */
    private function spec(Model $model): array
    {
        return self::SUBJECTS[$model::class];
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
