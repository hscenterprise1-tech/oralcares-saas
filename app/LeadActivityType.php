<?php

namespace App;

enum LeadActivityType: string
{
    case LeadCreated = 'lead_created';
    case StatusChanged = 'status_changed';
    case AssignmentChanged = 'assignment_changed';
    case FollowUpCompleted = 'follow_up_completed';
    case NoteAdded = 'note_added';
    case DemoScheduled = 'demo_scheduled';
    case ProposalSent = 'proposal_sent';
    case LeadWon = 'lead_won';
    case LeadLost = 'lead_lost';
    case ConversionStarted = 'conversion_started';
}
