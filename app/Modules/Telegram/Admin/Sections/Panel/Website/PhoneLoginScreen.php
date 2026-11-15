<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Sections\Panel\Config\SmsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «ورود با موبایل» of the website: sign-in by a code texted to the customer — held off while no SMS goes out for the
 * site (none set up, or an agent's site the owner does not text for), said as the owner's doing to the owner, as the
 * owner's to an agent.
 */
final class PhoneLoginScreen extends WebsiteCard
{
    public const ID = 'wsp';

    /** The website's `phone.sms` while codes go out. */
    private const READY = 'ready';

    /** … for an agent's site the owner does not text for. */
    private const NOT_FOR_AGENTS = 'not_for_agents';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '📱 ورود مشتری با شماره موبایل';
    }

    protected function intro(): string
    {
        return 'مشتری شماره موبایلش را وارد می‌کند و کدی شش رقمی با پیامک می‌گیرد؛ با همان کد وارد حسابش می‌شود، و اگر با این شماره حسابی نداشته باشد حساب تازه‌ای برایش ساخته می‌شود.';
    }

    protected function lead(array $values): array
    {
        $sms = self::part($values, 'phone')['sms'] ?? self::READY;
        if ($sms === self::READY) {
            return [];
        }
        $meanwhile = Json::on($values, 'phone_login') ? 'کسی نمی‌تواند با شماره موبایل وارد شود.' : 'این گزینه روشن نمی‌شود.';
        $owner = $this->here !== null && $this->here->seat->isOwner();

        return ['', '⚠️ ' . match (true) {
            $sms === self::NOT_FOR_AGENTS && $owner => 'پیامک را برای سایت نماینده‌ها باز نکرده‌اید؛ تا آن را در «تنظیمات پنل › پیامک» باز نکنید، ' . $meanwhile,
            $sms === self::NOT_FOR_AGENTS => 'مالک فروشگاه پیامک را برای سایت نماینده‌ها باز نکرده است؛ تا باز نکند، ' . $meanwhile,
            !$owner => 'پیامک را مالک فروشگاه راه می‌اندازد و هنوز راه‌اندازی نشده است؛ تا راه نیفتد، ' . $meanwhile,
            default => 'پیامک در «تنظیمات پنل › پیامک» راه‌اندازی نشده است؛ تا راه نیفتد، ' . $meanwhile,
        }];
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('phone_login', 'ورود با شماره موبایل (پیامک)', 'فقط شماره موبایل ایران پذیرفته می‌شود. مشتری‌ای که شماره‌اش را در ربات فرستاده، با همین شماره به همان حساب ربات وارد می‌شود.'),
        ];
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        if ((self::part($values, 'phone')['sms'] ?? self::READY) !== self::READY && Access::main($admin->seat)) {
            $view->row(Button::to('📨 تنظیمات پیامک پنل', SmsScreen::ID));
        }
    }
}
