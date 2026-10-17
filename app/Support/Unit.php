<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a typed amount counts (Input::amountOf()): each step reads its own unit's words after the number and no other's,
 * so «۲۰ روز» typed where GB are awaited is no 20 GB.
 */
enum Unit
{
    case Toman;
    case Gigabyte;
    case Day;
    case Device;

    /**
     * The words a customer writes after an amount of it, as a regex alternation, longest first — with the «…ی»/«…ه» forms
     * they say of a service in («سرویس ۲۰ گیگی», «۲ نفره»).
     */
    public function words(): string
    {
        return match ($this) {
            self::Toman => 'تومانی|تومان|تومنی|تومن|ت|toman',
            self::Gigabyte => 'گیگابایت|گیگا|گیگی|گیگ|gb|g',
            self::Day => 'روزه|روز|days?',
            self::Device => 'دستگاه|کاربره|کاربر|نفره|نفر|devices?|users?',
        };
    }

    /**
     * The words an amount of it is multiplied by: «۵۰ هزار تومان» is 50,000, and «۵ هزار گیگ» 5,000 GB (an agent buys
     * traffic by the thousand); «میلیون» is money's alone. Days and devices are typed as digits («۳ هزار روز» is refused
     * as any amount that is no number of days is). Each step still bounds what it reads.
     *
     * @return array<string, int>
     */
    public function scales(): array
    {
        return match ($this) {
            self::Toman => ['هزار' => 1_000, 'میلیون' => 1_000_000],
            self::Gigabyte => ['هزار' => 1_000],
            self::Day, self::Device => [],
        };
    }
}
