<?php

namespace App;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case UPI = 'upi';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';
}
