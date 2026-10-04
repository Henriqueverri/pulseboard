<?php

namespace App\Enums;

/**
 * Where a transaction (or one of its status changes) came from.
 */
enum TransactionSource: string
{
    case Seed = 'seed';
    case Ingest = 'ingest';
}
