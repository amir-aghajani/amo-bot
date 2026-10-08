<?php

declare(strict_types=1);

use App\Core\Config\ConfigValues;

return static fn(ConfigValues $settings): array => [
    // How email goes out (Core\Mail): none, or a mail driver's key — smtp (an account on a mail server), native (the
    // host's own mail, as PHP's mail() sends it), resend (Resend's API) —, which makes its transport of its own MAIL_*
    // settings (the container's `mail.transport`).
    'transport' => $settings->string('MAIL_TRANSPORT'),
    // Every MAIL_* setting of config.php as written, as text: a driver reads its own through its form, with the defaults
    // its fields declare.
    'settings' => $settings->prefixed('MAIL_'),
    // Who the shop's emails come from: no address, no email.
    'from_address' => $settings->string('MAIL_FROM_ADDRESS'),
    // Empty: the shop's own name (an agent's shop: its bot's).
    'from_name' => $settings->string('MAIL_FROM_NAME'),
];
