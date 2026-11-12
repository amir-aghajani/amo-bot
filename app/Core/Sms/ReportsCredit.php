<?php

declare(strict_types=1);

namespace App\Core\Sms;

/** A sender whose provider tells the account's credit (IPPanel's): the owner's settings show it, and so check the key. */
interface ReportsCredit
{
    /** @throws SmsFailedException when the provider could not be asked, or refused the key */
    public function credit(): SmsCredit;
}
