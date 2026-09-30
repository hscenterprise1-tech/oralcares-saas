<?php

namespace App\Shared\Search\Enums;

enum SearchModule: string
{
    case PATIENT = 'patient';
    case BILLING = 'billing';
    case INVENTORY = 'inventory';
    case CRM = 'crm';
    case CLINICAL = 'clinical';
    case HR = 'hr';
    case WORKFLOW = 'workflow';
    case NOTIFICATION = 'notification';
    // Extendable for future modules
}
