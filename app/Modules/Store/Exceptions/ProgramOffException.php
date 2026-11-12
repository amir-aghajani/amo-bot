<?php

declare(strict_types=1);

namespace App\Modules\Store\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A program the shop runs none of now (`shop.programs` of `GET /` says so): its customer's screen is a 404 — the free
 * test accounts and the nightly draw.
 */
final class ProgramOffException extends DomainRuleException
{
    public const TEST_ACCOUNTS = 'این فروشگاه فعلا اکانت تست نمی‌دهد.';
    public const LOTTERY = 'این فروشگاه فعلا قرعه‌کشی شبانه ندارد.';

    public static function testAccounts(): self
    {
        return new self(self::TEST_ACCOUNTS);
    }

    public static function lottery(): self
    {
        return new self(self::LOTTERY);
    }

    public function status(): int
    {
        return 404;
    }
}
