<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Paid = 'paid';
    case Refunded = 'refunded';
    case Pending = 'pending';
    case Canceled = 'canceled';
}
