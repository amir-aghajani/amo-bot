<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\MiniApp;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «اتصال» of the mini app: on or off — held off while APP_URL is not https —, the bot's menu button that opens it and its
 * words; then its address to copy, the shop's bot, the menu button set again as the app now stands (after a new address
 * or token), and how to make it the bot's main mini app in @BotFather.
 *
 * Actions besides the card's: `menu` (the menu button set again).
 */
final class MiniAppConnectionScreen extends MiniAppCard
{
    public const ID = 'mac';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🔌 اتصال مینی‌اپ';
    }

    protected function intro(): string
    {
        return 'فروشگاه داخل تلگرام: مشتری بدون بیرون رفتن از تلگرام پلن می‌خرد، سرویس‌هایش را می‌بیند و کیف پولش را شارژ می‌کند؛ با همان حسابی که در ربات دارد.';
    }

    protected function lead(array $values): array
    {
        $warning = $this->here !== null ? self::notSecure($values, $this->here->seat) : null;
        $default = is_string($values['menu_button_default'] ?? null) ? $values['menu_button_default'] : '';

        return [...($warning !== null ? ['', $warning] : []), ...($default !== '' ? ['', 'متن پیش‌فرض دکمه منو: «' . Fmt::e($default) . '»'] : [])];
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('enabled', 'مینی‌اپ روشن باشد', 'خاموش که باشد، آدرس مینی‌اپ چیزی نشان نمی‌دهد و ربات دکمه‌ای برای آن ندارد.'),
            FieldDef::toggle('menu_button', 'دکمه منوی ربات', 'دکمه کنار جای نوشتن پیام در ربات، تا وقتی مینی‌اپ روشن است آن را باز می‌کند؛ خاموش باشد، همان دکمه همیشگی تلگرام می‌ماند.'),
            FieldDef::text('menu_button_text', 'متن دکمه منو', 'خالی بماند، متن پیش‌فرض نوشته می‌شود. حداکثر ۳۲ نویسه.')->nullable(),
        ];
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $bot = is_array($values['bot'] ?? null) ? $values['bot'] : null;
        $view->line()
            ->line('<b>🔗 آدرس مینی‌اپ</b>')
            ->line(Fmt::code(is_string($values['url'] ?? null) ? $values['url'] : null))
            ->line('<i>مینی‌اپ روی همین آدرس است و ربات با دکمه‌هایش آن را باز می‌کند؛ بیرون از تلگرام فقط می‌گوید آن را از ربات باز کنید.</i>');
        if ($bot !== null && is_string($bot['username'] ?? null)) {
            $view->line('ربات فروشگاه: ' . Fmt::ltr('@' . $bot['username']));
        }
        $secure = Json::on($values, 'secure');
        if ($secure && Json::on($values, 'enabled') && Json::on($values, 'menu_button')) {
            $view->line()->line('<i>آدرس برنامه یا توکن ربات عوض شده؟ دکمه منوی ربات را با آدرس فعلی دوباره تنظیم کنید.</i>')
                ->row(Button::to('🔄 تنظیم دوباره دکمه منو', self::ID, 'menu'));
        }
        if ($secure) {
            $view->line()
                ->line("<b>مینی‌اپ اصلی ربات در \u{2068}BotFather\u{2069}</b>")
                ->line('<i>اختیاری: با این کار، پروفایل ربات هم دکمه باز کردن مینی‌اپ را نشان می‌دهد.</i>')
                ->line("۱. در تلگرام \u{2068}@BotFather\u{2069} را باز کنید، ربات فروشگاه را انتخاب کنید و به \u{2068}Bot Settings › Configure Mini App\u{2069} بروید.")
                ->line("۲. مینی‌اپ را روشن کنید و برای \u{2068}Main App URL\u{2069} همین آدرس را بفرستید.");
        }
    }

    protected function action(Admin $admin, string $action, array $args, array $values): View
    {
        if ($action === 'menu') {
            $answer = $admin->api->patch('/mini-app', []);
            $values = MiniAppSource::saved($admin, $answer);
            if (!is_string($answer['menu_button_error'] ?? null)) {
                $admin->notice('✅ دکمه منوی ربات دوباره تنظیم شد.');
            }

            return $this->card($admin, $values);
        }

        return parent::action($admin, $action, $args, $values);
    }
}
