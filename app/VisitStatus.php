<?php

namespace App;

enum VisitStatus: string
{
    case CheckedIn = 'checked_in';
    case Waiting = 'waiting';
    case Called = 'called';
    case InConsultation = 'in_consultation';
    case SentToXray = 'sent_to_xray';
    case SentToLab = 'sent_to_lab';
    case SentToBilling = 'sent_to_billing';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
