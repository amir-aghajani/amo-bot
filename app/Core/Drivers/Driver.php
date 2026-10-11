<?php

declare(strict_types=1);

namespace App\Core\Drivers;

/**
 * Something the shop does through someone else — a database, a mail service, a captcha, a VPN panel, a payment
 * gateway. A family is an interface extending this one plus a Registry in bootstrap/container.php, so a new driver is a
 * class and one registration; the panels draw its form from its description alone.
 */
interface Driver
{
    /** What config.php, a row or a request names it by. */
    public function key(): string;

    /** What the panels show of it: its name, description and form. */
    public function describe(): Descriptor;
}
