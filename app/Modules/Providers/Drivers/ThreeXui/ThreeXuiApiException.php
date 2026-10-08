<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui;

use App\Modules\Providers\Exceptions\PanelApiException;

/**
 * The panel answered and refused the call: {"success": false, "msg": "…"}. `panelMessage` is its own wording (often
 * why a port or an email was refused); the message names the call, for the log.
 */
final class ThreeXuiApiException extends PanelApiException
{
    public function __construct(string $method, string $path, int $httpStatus, string $panelMessage)
    {
        $reason = $panelMessage !== '' ? $panelMessage : "HTTP {$httpStatus}";

        parent::__construct("3x-ui {$method} {$path} failed: {$reason}", $httpStatus, $panelMessage);
    }
}
