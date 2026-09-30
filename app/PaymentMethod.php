<?php

namespace App;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Upi = 'upi';
    case Gateway = 'gateway';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::BankTransfer => 'Bank Transfer',
            self::Upi => 'UPI',
            self::Gateway => 'Payment Gateway',
            self::Cheque => 'Cheque',
        };
    }
}
