<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;

/**
 * A press on a customer card's button, checked (CustomerCards::press()): in the shop's connected report group, by one of
 * its bot admins, about a customer of the shop. `<prefix><action>:<customer id>[:<arg>…]` — `args` are what follows the
 * customer's id, as strings.
 */
final class CardPress
{
    /** @param list<string> $args */
    public function __construct(
        public readonly Update $update,
        public readonly User $admin,
        public readonly User $user,
        public readonly string $action,
        public readonly array $args,
    ) {}

    /** The n-th argument after the customer's id, as a whole number; null when missing or not one. */
    public function int(int $index): ?int
    {
        $arg = $this->args[$index] ?? '';

        return ctype_digit($arg) && strlen($arg) <= 18 ? (int) $arg : null;
    }
}
