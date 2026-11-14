<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Marzban;

use App\Modules\Providers\Exceptions\PanelApiException;

/**
 * Marzban answered and refused the call: `panelMessage` is its `detail` (a name taken, an inbound or protocol its Xray
 * config does not have, a user of another admin, a field it would not take); the message names the call, for the log.
 * `hint`, when there is one, says what to change on the panel, in its own terms (refused()).
 */
final class MarzbanApiException extends PanelApiException
{
    /** Marzban's own words for an inbound tag its Xray config does not have (its user models' validator). */
    private const NO_INBOUND = "Inbound %s doesn't exist";

    /**
     * Marzban's refusals of an inbound or protocol its Xray config no longer has: the user models' validator (a 422 by
     * its field) and the user router's own 400.
     */
    private const INBOUND_GONE = "/Inbound .+ doesn't exist|Protocol \\S+ is disabled on your server/";

    private const HINT_INBOUND = 'این اینباند دیگر در تنظیمات هسته (Core Settings) پنل Marzban نیست؛ اینباندهای این سرور را دوباره بخوانید و در پلن، اینباندی را انتخاب کنید که روی پنل هست.';

    /** Marzban's 403: a user another admin made, which a non-sudo admin may not touch. */
    private const HINT_NOT_YOURS = 'این کاربر را ادمین دیگری در Marzban ساخته است و ادمین غیر sudo فقط کاربرهای خودش را می‌بیند؛ با نام کاربری و رمز یک ادمین sudo پنل وصل شوید.';

    public function __construct(string $method, string $path, int $httpStatus, string $panelMessage, string $hint = '')
    {
        parent::__construct(self::callFailed('Marzban', $method, $path, $httpStatus, $panelMessage), $httpStatus, $panelMessage, $hint);
    }

    /** The panel's refusal of a call, with what to do about it when Marzban's words say: another admin's user, an inbound gone. */
    public static function refused(string $method, string $path, int $httpStatus, string $panelMessage): self
    {
        return new self($method, $path, $httpStatus, $panelMessage, match (true) {
            $httpStatus === 403 => self::HINT_NOT_YOURS,
            preg_match(self::INBOUND_GONE, $panelMessage) === 1 => self::HINT_INBOUND,
            default => '',
        });
    }

    /**
     * A user asked for on an inbound the panel's Xray config does not have, refused as Marzban would refuse it — said
     * before anything is sent: a user is made on its inbounds' protocols, which only the panel's list of them gives.
     */
    public static function missingInbound(string $tag): self
    {
        return new self('POST', '/api/user', 422, sprintf(self::NO_INBOUND, $tag), self::HINT_INBOUND);
    }
}
