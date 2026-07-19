<?php

namespace App;

enum OpportunityStage: string
{
    case New = 'new';
    case Qualified = 'qualified';
    case Contacted = 'contacted';
    case DemoScheduled = 'demo_scheduled';
    case DemoCompleted = 'demo_completed';
    case TrialOffered = 'trial_offered';
    case TrialActive = 'trial_active';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';
    case Archived = 'archived';
}
