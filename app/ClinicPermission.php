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
    case PatientDelete = 'patient.delete';
    case PatientSearch = 'patient.search';

    // Appointment & Queue Capabilities
    case AppointmentView = 'appointment.view';
    case AppointmentCreate = 'appointment.create';
    case AppointmentManage = 'appointment.manage';
    case QueueManage = 'queue.manage';
    case VisitView = 'visit.view';
    case VisitCreate = 'visit.create';

    // Clinical Capabilities (Generic for all specialties)
    case ClinicalView = 'clinical.view';
    case ClinicalEdit = 'clinical.edit';
    case PrescriptionManage = 'prescription.manage';
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
            'Admin' => self::values(),
            'Reception' => [
                self::PatientView->value,
                self::PatientSearch->value,
                self::PatientCreate->value,
                self::PatientEdit->value,
                self::AppointmentView->value,
                self::AppointmentCreate->value,
                self::AppointmentManage->value,
                self::QueueManage->value,
                self::VisitView->value,
                self::VisitCreate->value,
                self::BillingView->value,
                self::BillingCreate->value,
                self::PaymentCollect->value,
                self::DocumentsManage->value,
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
                self::DocumentsManage->value,
                self::BillingView->value,
            ],
            'Billing' => [
                self::PatientView->value,
                self::PatientSearch->value,
                self::BillingView->value,
                self::BillingCreate->value,
                self::PaymentCollect->value,
                self::DiscountApply->value,
                self::ReportsView->value,
            ],
            'Staff' => [
                self::PatientView->value,
                self::AppointmentView->value,
            ],
        ];
    }
}
