<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui;

use App\Modules\Providers\Exceptions\PanelApiException;

/**
 * 3x-ui answered and refused the call: {"success": false, "msg": "…"}. `panelMessage` is its own wording (often why a
 * port or an email was refused); the message names the call, for the log.
 */
final class ThreeXuiApiException extends PanelApiException
{
    public function __construct(string $method, string $path, int $httpStatus, string $panelMessage)
    {
        parent::__construct(self::callFailed('3x-ui', $method, $path, $httpStatus, $panelMessage), $httpStatus, $panelMessage);
    }
}
