<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Remnawave;

use App\Modules\Providers\Exceptions\PanelApiException;

/**
 * Remnawave answered and refused the call: `panelMessage` is its `message` (a name taken, a field its validation
 * refused, an error of its own), `errorCode` its code for it («A019», empty for a validation's list); the message names
 * the call, for the log. `hint`, when there is one, says what to change, in the panel's own terms (refused()).
 */
final class RemnawaveApiException extends PanelApiException
{
    /** Remnawave's codes for a user already switched as asked (actions/disable, actions/enable): nothing to do. */
    private const ALREADY = ['A029', 'A030'];

    /** Remnawave's refusal of a deadline it finds past — what a clock behind the panel's makes of the soonest one. */
    private const PAST_DEADLINE = 'Expiration date cannot be in the past';

    private const HINT_CLOCK = 'پنل این زمان را گذشته می‌داند؛ ساعت هاست فروشگاه و سرور پنل را بررسی کنید که با هم یکی باشند.';

    public function __construct(string $method, string $path, int $httpStatus, string $panelMessage, public readonly string $errorCode = '', string $hint = '')
    {
        parent::__construct(self::callFailed('Remnawave', $method, $path, $httpStatus, $panelMessage), $httpStatus, $panelMessage, $hint);
    }

    /** The panel's refusal of a call, with what to do about it when Remnawave's words say: a deadline it finds past. */
    public static function refused(string $method, string $path, int $httpStatus, string $panelMessage, string $errorCode): self
    {
        return new self($method, $path, $httpStatus, $panelMessage, $errorCode, str_contains($panelMessage, self::PAST_DEADLINE) ? self::HINT_CLOCK : '');
    }

    /** Whether the panel refused to switch a user that was already as asked (someone switched it in between). */
    public function alreadySo(): bool
    {
        return in_array($this->errorCode, self::ALREADY, true);
    }
}
