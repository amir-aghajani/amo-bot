<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Rebecca;

use App\Modules\Providers\Exceptions\PanelApiException;

/**
 * Rebecca answered and refused the call: `panelMessage` is its `detail` (a name taken, a service gone, a permission or a
 * limit its admin lacks); the message names the call, for the log. `hint`, when there is one, says what to change on the
 * panel, in its own terms (refused()).
 */
final class RebeccaApiException extends PanelApiException
{
    /** Rebecca's 403 for a user another admin made, or a route kept for its sudo admins. */
    private const NOT_YOURS = "You're not allowed";

    /** Rebecca's refusals of a service it no longer has, or one with no active host to put a user on. */
    private const SERVICE_GONE = '/^(Service not found|Service does not have any active hosts)$/';

    /** What a plan naming several services on one server is refused with, before anything is sent. */
    private const SEVERAL_SERVICES = 'A Rebecca user belongs to one service.';

    private const HINT_NOT_YOURS = 'این کاربر را ادمین دیگری در Rebecca ساخته است، یا این کار فقط برای ادمین sudo است؛ کلید API یک ادمین sudo یا full access بدهید.';
    private const HINT_PERMISSION = 'ادمینی که کلید به نام اوست این اجازه را در Rebecca ندارد؛ دسترسی‌های کاربرها (ساخت، حذف، ریست مصرف، تغییر لینک، ساخت در حالت انتظار، حجم و زمان نامحدود و محدودیت IP) را به او بدهید، یا کلید یک ادمین full access بدهید.';
    private const HINT_SERVICE = 'این سرویس در Rebecca دیگر نیست یا هیچ هاست فعالی ندارد؛ اینباندهای این سرور را دوباره بخوانید و در پلن، سرویسی را انتخاب کنید که روی پنل فعال است.';
    private const HINT_ONE_SERVICE = 'هر کاربر Rebecca فقط در یک سرویس است؛ در پلن، روی این سرور فقط یک سرویس انتخاب کنید. پلنی که کل سرور را می‌فروشد، اکانت تست و سرویس دلخواه روی همه سرویس‌هایی ساخته می‌شوند که در صفحه سرور برای فروش علامت خورده‌اند: برای آن‌ها فقط یک سرویس را برای فروش علامت بزنید.';

    public function __construct(string $method, string $path, int $httpStatus, string $panelMessage, string $hint = '')
    {
        parent::__construct(self::callFailed('Rebecca', $method, $path, $httpStatus, $panelMessage), $httpStatus, $panelMessage, $hint);
    }

    /** Only "You're not allowed" is another admin's user; Rebecca's other 403s are permissions its admin lacks. */
    public function hidesAnothersClient(): bool
    {
        return $this->httpStatus === 403 && $this->panelMessage === self::NOT_YOURS;
    }

    /** The panel's refusal of a call, with what to do about it when Rebecca's words say: another admin's user, a permission, a service. */
    public static function refused(string $method, string $path, int $httpStatus, string $panelMessage): self
    {
        return new self($method, $path, $httpStatus, $panelMessage, match (true) {
            $httpStatus === 403 && $panelMessage === self::NOT_YOURS => self::HINT_NOT_YOURS,
            $httpStatus === 403 => self::HINT_PERMISSION,
            preg_match(self::SERVICE_GONE, $panelMessage) === 1 => self::HINT_SERVICE,
            default => '',
        });
    }

    /** A user asked for in several services — Rebecca keeps each in one —, refused before anything is sent. */
    public static function severalServices(): self
    {
        return new self('POST', '/api/user', 400, self::SEVERAL_SERVICES, self::HINT_ONE_SERVICE);
    }
}
