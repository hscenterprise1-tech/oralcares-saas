<?php

namespace App;

enum InventoryTransactionType: string
{
    case OpeningStock = 'opening_stock';
    case Purchase = 'purchase';
    case Adjustment = 'adjustment';
    case Consumption = 'consumption';
    case Return = 'return';
    case Transfer = 'transfer';
    case Expiry = 'expiry';
    case Damage = 'damage';
}
