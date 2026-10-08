<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

enum WalletTransactionType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
