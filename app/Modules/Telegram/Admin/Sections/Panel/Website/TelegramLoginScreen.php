<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/**
 * «ورود با تلگرام» of the website: the switch, the Client ID @BotFather shows for the site (the bot's own id said beside
 * it) and the Client Secret only the redirect flow needs — typed, never shown back —, with the steps in BotFather.
 */
final class TelegramLoginScreen extends WebsiteCard
{
    public const ID = 'wst';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '✈️ ورود مشتری با تلگرام';
    }

    protected function intro(): string
    {
        return 'مشتری با حساب تلگرامش وارد وب‌سایت می‌شود و همان حسابی را می‌بیند که در ربات دارد؛ با همان سرویس‌ها و کیف پول.';
    }

    protected function lead(array $values): array
    {
        $url = is_string($values['url'] ?? null) ? $values['url'] : '';
        $bot = self::part($values, 'telegram')['bot_id'] ?? null;

        return [
            '',
            "<b>راه‌اندازی در \u{2068}BotFather\u{2069}</b>",
            "۱. در تلگرام \u{2068}@BotFather\u{2069} را باز کنید، ربات فروشگاه را انتخاب کنید و به \u{2068}Bot Settings › Login Widget\u{2069} بروید.",
            ($url === ''
                ? "۲. اول آدرس وب‌سایت را در بخش «🔌 اتصال» ذخیره کنید، بعد همان را در \u{2068}Allowed URLs\u{2069} اضافه کنید"
                : '۲. آدرس وب‌سایت، ' . Fmt::ltr($url) . "، را در \u{2068}Allowed URLs\u{2069} اضافه کنید")
                . '؛ اگر وب‌سایت از روش redirect استفاده می‌کند، آدرس صفحه بازگشت را هم.',
            "۳. \u{2068}Client ID\u{2069} را پایین‌تر وارد کنید؛ برای روش redirect، \u{2068}Client Secret\u{2069} را هم.",
            ...(is_int($bot) ? ['', 'شناسه ربات این فروشگاه: ' . Fmt::code((string) $bot) . " — \u{2068}Client ID\u{2069} معمولا همین است، ولی عددی را بنویسید که \u{2068}BotFather\u{2069} نشان می‌دهد."] : []),
        ];
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('telegram_login', 'ورود با تلگرام', "خاموش که باشد، وب‌سایت ورود با تلگرام را نمی‌پذیرد. برای روشن کردنش \u{2068}Client ID\u{2069} لازم است."),
            FieldDef::text('telegram_client_id', "\u{2068}Client ID\u{2069}", "عددی که \u{2068}@BotFather\u{2069} در \u{2068}Bot Settings › Login Widget\u{2069} نشان می‌دهد.")->nullable(),
            FieldDef::secret(WebsiteSource::SECRET, "\u{2068}Client Secret\u{2069}", 'فقط روش redirect به آن نیاز دارد؛ روش popup بدون آن کار می‌کند. پیامتان بعد از خواندن پاک می‌شود و دیگر نشان داده نمی‌شود.')->nullable(),
        ];
    }
}
