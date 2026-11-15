<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Nav;

/**
 * The ways from the support screens into the other sections (a customer's page, an order, a filtered list), as the
 * panel's links lead there: a button only to a screen that is built and that the seat may open — none to a dead end.
 */
final class Links
{
    /** A customer's own page (Sections\Customers). */
    public const CUSTOMER = 'usv';

    /** An order's, a payment's and a service's own screens (Sections\Sales). */
    public const ORDER = 'orv';
    public const PAYMENT = 'pyv';
    public const SERVICE = 'sbv';

    /** A button to `$screen`'s `$action` when it is built and the seat may open it; null otherwise. */
    public static function to(Admin $admin, string $label, string $screen, string $action = Nav::SHOW, int|string ...$args): ?Button
    {
        return $admin->opens($screen) ? Button::to($label, $screen, $action, ...$args) : null;
    }

    /**
     * A customer's page, the panel's userPage(), from their reference (UserDirectory::presentRef()).
     *
     * @param array<string, mixed>|null $ref
     */
    public static function customer(Admin $admin, ?array $ref, string $label = ''): ?Button
    {
        $id = is_int($ref['id'] ?? null) ? $ref['id'] : 0;

        return $id > 0 ? self::to($admin, '👤 ' . ($label !== '' ? $label : Words::name($ref)), self::CUSTOMER, Nav::SHOW, $id) : null;
    }
}
