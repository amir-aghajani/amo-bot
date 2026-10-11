<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/** Which way a wallet line moved the balance. */
enum WalletTransactionType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
