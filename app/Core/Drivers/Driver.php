<?php

declare(strict_types=1);

namespace App\Core\Drivers;

/**
 * One way of doing a thing through someone else — a database, a mail service, a captcha, a panel, a payment gateway —:
 * a family's drivers are one interface (extending this one) and one Registry, so a new one is a class and its
 * registration in bootstrap/container.php. What it asks of the admin is its form (Descriptor::$form), which the panels
 * draw from its description alone.
 */
interface Driver
{
    /** What config.php, a row or a request names it by. */
    public function key(): string;

    /** What the panels show of it: its name, its words and its form. */
    public function describe(): Descriptor;
}
