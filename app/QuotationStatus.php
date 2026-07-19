<?php

namespace App;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Prepared = 'prepared';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Negotiation = 'negotiation';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';
}
