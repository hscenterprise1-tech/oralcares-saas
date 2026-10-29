<?php

namespace App;

/**
 * Granular clinic operational capabilities and permissions.
 *
 * Designed to be generic, specialty-agnostic, and role-configurable.
 */
enum ClinicPermission: string
{
    // Reception & Patient Capabilities
    case PatientView = 'patient.view';
    case PatientCreate = 'patient.create';
    case PatientEdit = 'patient.edit';
    case PatientSearch = 'patient.search';

    // Appointment & Queue Capabilities
    case AppointmentView = 'appointment.view';
    case AppointmentCreate = 'appointment.create';
    case AppointmentManage = 'appointment.manage';
    case QueueManage = 'queue.manage';
    // Running the front office (registration, check-in, the day's patients):
    // assignable on its own, so a doctor who also covers reception holds it
    // without becoming a different role. A practitioner's `queue.manage` (to
    // call their own next patient) is not front-office work.
    case FrontOffice = 'front_office.operate';
    case VisitView = 'visit.view';
    case VisitCreate = 'visit.create';

    // Clinical Capabilities (Generic for all specialties)
    case ClinicalView = 'clinical.view';
    case ClinicalEdit = 'clinical.edit';
    case PrescriptionManage = 'prescription.manage';

    // Treatment engine
    case TreatmentStart = 'treatment.start';
    case TreatmentExecute = 'treatment.execute';
    // Confirming at the desk that a physiotherapy session took place (and which package paid for it).
    // Not clinical: no notes, no diagnosis, no sign-off, no other treatment work.
    case PhysioSessionConfirm = 'physio.session.confirm';
    case CatalogManage = 'catalog.manage';
    case SpecialtyConfigure = 'specialty.configure';

    // Patient records
    case ConsentManage = 'consent.manage';
    case PatientMerge = 'patient.merge';
    case DocumentsManage = 'documents.manage';

    // Billing & Financial POS Capabilities
    case BillingView = 'billing.view';
    case BillingCreate = 'billing.create';
    case PaymentCollect = 'payment.collect';
    case DiscountApply = 'discount.apply';
    case RefundProcess = 'refund.process';

    // Staff & Administration Capabilities
    case PractitionerManage = 'practitioner.manage';
    case StaffManage = 'staff.manage';
    case RolesManage = 'roles.manage';
    case BranchManage = 'branch.manage';
    case SettingsManage = 'settings.manage';
    case ReportsView = 'reports.view';

    // Staff documents and leave. Verifying a document and deciding leave are
    // their own grants: managing staff details is not enough for either, and
    // requesting leave must not need staff management at all.
    case StaffDocumentsVerify = 'staff.documents.verify';
    case LeaveRequest = 'leave.request';
    case LeaveApprove = 'leave.approve';

    // The clinic's business: spending, and pay. Salaries are visible only to
    // `payroll.view`; running and paying payroll is `payroll.manage`.
    case ExpenseManage = 'expense.manage';
    case ExpenseApprove = 'expense.approve';
    case PayrollView = 'payroll.view';
    case PayrollManage = 'payroll.manage';

    /**
     * Get all permission values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Standard role permission templates.
     *
     * @return array<string, array<int, string>>
     */
    public static function defaultRolePermissions(): array
    {
        return [
            'Owner' => self::values(),
            // Which specialties the clinic practises is the owner's decision alone.
            'Admin' => array_values(array_diff(self::values(), [self::SpecialtyConfigure->value])),
            'Reception' => [
                self::PatientView->value,
                self::PatientSearch->value,
                self::PatientCreate->value,
                self::PatientEdit->value,
                self::AppointmentView->value,
                self::AppointmentCreate->value,
                self::AppointmentManage->value,
                self::QueueManage->value,
                self::FrontOffice->value,
                self::VisitView->value,
                self::VisitCreate->value,
                self::BillingView->value,
                self::BillingCreate->value,
                self::PaymentCollect->value,
                self::PhysioSessionConfirm->value,
                self::DocumentsManage->value,
                self::ConsentManage->value,
                self::PatientMerge->value,
                self::LeaveRequest->value,
            ],
            'Practitioner' => [
                self::PatientView->value,
                self::PatientSearch->value,
                self::AppointmentView->value,
                self::QueueManage->value,
                self::VisitView->value,
                self::ClinicalView->value,
                self::ClinicalEdit->value,
                self::PrescriptionManage->value,
                self::TreatmentStart->value,
                self::TreatmentExecute->value,
                self::ConsentManage->value,
                self::DocumentsManage->value,
                self::BillingView->value,
                self::LeaveRequest->value,
            ],
            'Billing' => [
                self::PatientView->value,
                self::PatientSearch->value,
                self::BillingView->value,
                self::BillingCreate->value,
                self::PaymentCollect->value,
                self::DiscountApply->value,
                self::ReportsView->value,
                self::LeaveRequest->value,
            ],
            'Staff' => [
                self::PatientView->value,
                self::AppointmentView->value,
                self::LeaveRequest->value,
            ],
        ];
    }
}
