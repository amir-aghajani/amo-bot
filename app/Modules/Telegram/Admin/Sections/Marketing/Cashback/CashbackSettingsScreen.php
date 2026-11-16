<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Cashback;

use App\Modules\Telegram\Admin\Sections\Bot\Settings\BotCard;
use App\Modules\Telegram\Admin\Sections\Marketing\ProgramSwitch;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/**
 * «⚙️ تنظیمات کش‌بک» (the panel's card over the rules, `cashback`), reached from the rules' list: whether delivered
 * orders earn cashback at all.
 */
final class CashbackSettingsScreen extends BotCard
{
    use ProgramSwitch;

    public const ID = 'cbs';

    protected function group(): string
    {
        return 'cashback';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return Marketing::settings(Marketing::CASHBACK);
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::CASHBACK);
    }

    protected function parent(): string
    {
        return CashbackRulesScreen::ID;
    }

    protected function intro(): string
    {
        return 'وقتی سفارشی تحویل شد، درصدی از مبلغی که مشتری پرداخت کرده به کیف پول او برمی‌گردد و در تلگرام یا با ایمیل خبردار می‌شود؛ با هر روش پرداختی، حتی کیف پول. اگر سفارش بعدا برگشت داده شود، کش‌بک آن از مبلغ برگشتی کم می‌شود.' . "\n\n"
            . 'درصد هر سفارش بالاترین قانون فعالی است که با آن جور است، به اضافه کش‌بک سطح وفاداری مشتری؛ حداکثر ۱۰۰ درصد. خاموش که باشد، هیچ سفارشی کش‌بک نمی‌گیرد، کش‌بک سطح‌ها هم نه.';
    }

    protected function fields(): array
    {
        return [FieldDef::toggle('cashback_enabled', 'کش‌بک فعال باشد')];
    }
}
