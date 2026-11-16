<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/** «افزودن سرویس با لینک» (`service_claims`, the main shop's alone): whether a customer may add a client made by hand on a panel by its link. */
final class ServiceClaimsCard extends BotCard
{
    public const ID = 'bsc';

    public const TITLE = Icons::CLAIMS . ' افزودن سرویس با لینک';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures() && $seat->shop()->isMain();
    }

    protected function group(): string
    {
        return 'service_claims';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function intro(): string
    {
        return 'برای کلاینتی که خودتان روی پنل یکی از سرورها ساخته‌اید و لینکش را به مشتری داده‌اید: مشتری لینک اشتراک یا یکی از کانفیگ‌های همان سرویس را به ربات (یا در سایت) می‌فرستد و سرویس به «سرویس‌های من» او اضافه می‌شود. این امکان فقط در ربات اصلی است.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('claim_enabled', 'افزودن سرویس با لینکش', 'روشن که باشد، لینکی که مشتری می‌فرستد روی پنل سرورها جستجو می‌شود و سرویسی که پیدا شود به حساب او اضافه می‌شود. خاموش، لینک فرستاده‌شده پذیرفته نمی‌شود و سرویس‌هایی که قبلا اضافه شده‌اند سر جایشان می‌مانند.'),
        ];
    }

    protected function notes(): array
    {
        return [
            'اولین کسی که لینک را بفرستد سرویس را می‌گیرد. سرویسی که در فروشگاه هست، به نام هر مشتری که باشد، دوباره اضافه نمی‌شود.',
            'هر سرویسی که اضافه شود در گروه گزارش‌ها، تاپیک «خریدها»، گزارش می‌شود.',
            'اگر سرویس به دست مشتری اشتباهی رسید، آن را در «اشتراک‌ها › افزوده‌شده با لینک» باز کنید و در حذف سرویس «حذف فقط از فروشگاه (کلاینت روی پنل می‌ماند)» را بزنید: سرویس فقط از فروشگاه حذف می‌شود، کلاینت روی پنل می‌ماند و مشتری درست می‌تواند لینک را دوباره بفرستد.',
            'سرویس اضافه‌شده تا اولین تمدید پلن ندارد؛ هنگام تمدید، مشتری یکی از پلن‌هایی را که روی سرور همان سرویس فروخته می‌شوند انتخاب می‌کند.',
        ];
    }
}
