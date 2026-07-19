<?php

namespace App;

enum VisitPriority: string
{
    case Emergency = 'emergency';
    case SeniorCitizen = 'senior_citizen';
    case Child = 'child';
    case VIP = 'vip';
    case Normal = 'normal';
}
