<?php

namespace App;

enum ActivityType: string
{
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Meeting = 'meeting';
    case Demo = 'demo';
    case Trial = 'trial';
    case FollowUp = 'follow_up';
    case Note = 'note';
    case Document = 'document';
    case Quotation = 'quotation';
    case Agreement = 'agreement';
    case Provisioning = 'provisioning';
    case SystemUpdate = 'system_update';
    case RecordCreated = 'record_created';
    case RecordUpdated = 'record_updated';
    case RecordDeleted = 'record_deleted';
}
