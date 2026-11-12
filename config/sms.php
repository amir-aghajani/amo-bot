<?php

declare(strict_types=1);

use App\Core\Config\ConfigValues;

return static fn(ConfigValues $settings): array => [
    // How one-time codes go out by SMS (Core\Sms): none, or an SMS driver's key — ippanel (IPPanel's pattern sending) —,
    // which makes its sender of its own SMS_* settings (the container's `sms.sender`).
    'driver' => $settings->string('SMS_DRIVER'),
    // Whether agents' websites may send sign-in codes too: the owner's account pays for every one.
    'agents' => $settings->bool('SMS_AGENTS'),
    // Every SMS_* setting of config.php as written, as text: a driver reads its own through its form, with the defaults
    // its fields declare.
    'settings' => $settings->prefixed('SMS_'),
];
