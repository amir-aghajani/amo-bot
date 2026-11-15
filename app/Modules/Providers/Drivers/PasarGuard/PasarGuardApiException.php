<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\PasarGuard;

use App\Modules\Providers\Exceptions\PanelApiException;

/**
 * PasarGuard answered and refused the call: `panelMessage` is its `detail` (a name taken, a group it does not have, a
 * role limit, a field it would not take); the message names the call, for the log. `hint`, when there is one, says what
 * to change on the panel, in its own terms (refused()).
 */
final class PasarGuardApiException extends PanelApiException
{
    /** The 403 of a panel before 5.0 for a user another admin made, which a non-sudo admin may not touch. */
    private const NOT_YOURS = "You're not allowed";

    /**
     * The 403s of PasarGuard's roles (5.0 and later): an action the admin's role lacks, an admin whose traffic ran out,
     * a role that makes users from templates only.
     */
    private const DENIED = 'Permission denied';
    private const LIMITED = 'Admin is limited';

    /** The words of a limited admin whose role blocks it altogether (app/operation/permissions.py). */
    private const ALL_BLOCKED = 'Admin is limited — all access blocked';
    private const TEMPLATE_ONLY = 'Manual user create/modify is not allowed for your role';

    /** The limits a role (or the admin itself) sets on its users' traffic, term and count, as the panel words them. */
    private const ROLE_LIMIT = '/^(User limit reached|Data limit (cannot|must)|Expire (cannot|must)|On-hold duration (cannot|must))/';

    /** What a role may demand of every user that the shop never sends: an on-hold timeout, a device (HWID) limit. */
    private const NOT_SENT = '/^(On-hold timeout|HWID limit) /';

    private const GROUP_GONE = 'Group not found';

    private const HINT_NOT_YOURS = 'این کاربر را ادمین دیگری در PasarGuard ساخته است و ادمین غیر sudo فقط کاربرهای خودش را می‌بیند؛ با نام کاربری و رمز یک ادمین sudo پنل وصل شوید.';
    private const HINT_PERMISSION = 'نقش (Role) این ادمین در PasarGuard این دسترسی را ندارد؛ در بخش Admins پنل نقشی به او بدهید که دسترسی کاربرها (ایجاد، مشاهده، ویرایش، حذف، بازنشانی مصرف و لغو اشتراک)، مشاهده گروه‌ها و مشاهده سیستم را دارد، مثل administrator.';
    private const HINT_LIMITED = 'حجم این ادمین در PasarGuard تمام شده است (وضعیت limited)؛ در بخش Admins پنل حجمش را بیشتر کنید یا مصرفش را صفر کنید.';
    private const HINT_TEMPLATE_ONLY = 'نقش این ادمین در PasarGuard کاربر را فقط از روی قالب می‌سازد؛ گزینه Require template را در نقشش خاموش کنید یا نقش دیگری به او بدهید.';
    private const HINT_ROLE_LIMIT = 'نقش این ادمین در PasarGuard (یا حدهای خود ادمین) برای حجم، مدت یا تعداد کاربرهایش حد گذاشته است؛ حجم و مدت پلن را در آن حد بگذارید یا حد را در پنل بردارید.';
    private const HINT_NOT_SENT = 'نقش این ادمین در PasarGuard برای هر کاربر زمان انتظار (On-hold timeout) یا محدودیت دستگاه (HWID) می‌خواهد که فروشگاه نمی‌فرستد؛ آن حد را از نقشش بردارید (برای HWID، محدودیت پیش‌فرض در تنظیمات HWID پنل هم کافی است).';
    private const HINT_GROUP = 'این گروه در PasarGuard نیست یا نقش این ادمین به آن دسترسی ندارد (Allowed groups)؛ اینباندهای این سرور را دوباره بخوانید و در پلن، گروهی را انتخاب کنید که روی پنل هست.';

    public function __construct(string $method, string $path, int $httpStatus, string $panelMessage, string $hint = '')
    {
        parent::__construct(self::callFailed('PasarGuard', $method, $path, $httpStatus, $panelMessage), $httpStatus, $panelMessage, $hint);
    }

    /** Only 3.x–4.x's "You're not allowed" is another admin's user; a "Permission denied" or a limited admin is not. */
    public function hidesAnothersClient(): bool
    {
        return $this->httpStatus === 403 && $this->panelMessage === self::NOT_YOURS;
    }

    /**
     * An admin limited with all access blocked (its role's "disabled when limited") can do nothing on the panel until it
     * is given traffic: the server is out of use, as a refused sign-in leaves it. Write actions blocked alone still read.
     */
    public function unavailable(): bool
    {
        return $this->httpStatus === 403 && $this->panelMessage === self::ALL_BLOCKED;
    }

    /**
     * The panel's refusal of a call, with what to do about it when PasarGuard's words say: another admin's user, a role
     * that lacks the action or limits its users, an admin out of traffic, a group gone.
     */
    public static function refused(string $method, string $path, int $httpStatus, string $panelMessage): self
    {
        return new self($method, $path, $httpStatus, $panelMessage, match (true) {
            $httpStatus === 403 && $panelMessage === self::NOT_YOURS => self::HINT_NOT_YOURS,
            $httpStatus === 403 && str_starts_with($panelMessage, self::DENIED) => self::HINT_PERMISSION,
            $httpStatus === 403 && str_starts_with($panelMessage, self::LIMITED) => self::HINT_LIMITED,
            $httpStatus === 403 && $panelMessage === self::TEMPLATE_ONLY => self::HINT_TEMPLATE_ONLY,
            preg_match(self::ROLE_LIMIT, $panelMessage) === 1 => self::HINT_ROLE_LIMIT,
            preg_match(self::NOT_SENT, $panelMessage) === 1 => self::HINT_NOT_SENT,
            $panelMessage === self::GROUP_GONE => self::HINT_GROUP,
            default => '',
        });
    }
}
