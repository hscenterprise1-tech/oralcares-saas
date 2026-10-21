<?php

namespace App;

/**
 * How soon a checked-in patient should be seen. Operational triage set by
 * the desk, never a clinical diagnosis. Which ones a clinic offers is its
 * check-in setting (`PatientFlowConfigService::checkIn()`).
 */
enum VisitPriority: string
{
    case Emergency = 'emergency';
    case Urgent = 'urgent';
    case SeniorCitizen = 'senior_citizen';
    case Child = 'child';
    case VIP = 'vip';
    case Normal = 'normal';

    public function label(): string
    {
        return match ($this) {
            self::VIP => 'VIP',
            self::SeniorCitizen => 'Senior citizen',
            default => ucfirst($this->value),
        };
    }

    /** Queue order: emergency first, then urgent, then everyone else by arrival. */
    public function rank(): int
    {
        return match ($this) {
            self::Emergency => 0,
            self::Urgent => 1,
            default => 2,
        };
    }
}
