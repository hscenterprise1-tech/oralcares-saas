<?php

namespace App\Shared\Notification\Enums;

enum NotificationCategory: string
{
    case SYSTEM = 'system';
    case SECURITY = 'security';
    case AUDIT = 'audit';
    case INFORMATION = 'information';
    case SUCCESS = 'success';
    case WARNING = 'warning';
    case CRITICAL = 'critical';
    case REMINDER = 'reminder';
    case TASK = 'task';
    case APPROVAL = 'approval';
    case WORKFLOW = 'workflow';
    case PATIENT = 'patient';
    case BILLING = 'billing';
    case INVENTORY = 'inventory';
    case CRM = 'crm';
    case SUBSCRIPTION = 'subscription';
    case LAB = 'lab';
    case PRESCRIPTION = 'prescription';
    case DOCTOR = 'doctor';
    case RECEPTION = 'reception';

    public function icon(): string
    {
        return match ($this) {
            self::SYSTEM, self::INFORMATION => 'information-circle',
            self::SECURITY => 'shield-check',
            self::AUDIT => 'clipboard-document-list',
            self::SUCCESS => 'check-circle',
            self::WARNING, self::CRITICAL => 'exclamation-triangle',
            self::REMINDER => 'clock',
            self::TASK => 'clipboard-document-check',
            self::APPROVAL => 'check-badge',
            self::WORKFLOW => 'arrow-path',
            self::PATIENT => 'user',
            self::BILLING => 'currency-dollar',
            self::INVENTORY => 'archive-box',
            self::CRM => 'users',
            self::SUBSCRIPTION => 'credit-card',
            self::LAB => 'beaker',
            self::PRESCRIPTION => 'document-text',
            self::DOCTOR => 'user-circle',
            self::RECEPTION => 'building-office',
        };
    }
}
