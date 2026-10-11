<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\PasarGuard;

use App\Modules\Providers\Exceptions\PanelApiException;

/**
 * PasarGuard answered and refused the call: `panelMessage` is its `detail` (a name taken, a group it does not have, a
 * role limit, a field it would not take); the message names the call, for the log.
 */
final class PasarGuardApiException extends PanelApiException
{
    public function __construct(string $method, string $path, int $httpStatus, string $panelMessage)
    {
        parent::__construct(self::callFailed('PasarGuard', $method, $path, $httpStatus, $panelMessage), $httpStatus, $panelMessage);
    }
}
