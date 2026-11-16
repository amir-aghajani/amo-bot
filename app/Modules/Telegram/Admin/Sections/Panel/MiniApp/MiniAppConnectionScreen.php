<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\MiniApp;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «اتصال» of the mini app: on or off — held off while APP_URL is not https, warned of —, the bot's menu button that opens
 * it and its words (the default said while empty); then its address and the shop's bot, the address copied at a tap, the
 * menu button set again as the app now stands (after a new address or token), and how to make it the bot's main mini app
 * in @BotFather, folded under the card.
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
        $words = 'فروشگاه داخل تلگرام: مشتری بدون بیرون رفتن از تلگرام پلن می‌خرد، سرویس‌هایش را می‌بیند و کیف پولش را شارژ می‌کند؛ با همان حسابی که در ربات دارد. بیرون از تلگرام، آدرس مینی‌اپ فقط می‌گوید آن را از ربات باز کنید.';
        if (!Json::on($this->drawn(), 'secure')) {
            return $words;
        }

        return implode("\n", [
            $words,
            '',
            "<b>مینی‌اپ اصلی ربات در \u{2068}BotFather\u{2069}</b> (اختیاری: پروفایل ربات هم دکمه باز کردن مینی‌اپ را نشان می‌دهد)",
            "۱. در تلگرام \u{2068}@BotFather\u{2069} را باز کنید، ربات فروشگاه را انتخاب کنید و به \u{2068}Bot Settings › Configure Mini App\u{2069} بروید.",
            "۲. مینی‌اپ را روشن کنید و برای \u{2068}Main App URL\u{2069} آدرس مینی‌اپ را بفرستید.",
        ]);
    }

    protected function warnings(Admin $admin, array $values): array
    {
        $warning = self::notSecure($values, $admin->seat);

        return $warning === null ? [] : [$warning];
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('enabled', 'مینی‌اپ روشن باشد', 'خاموش که باشد، آدرس مینی‌اپ چیزی نشان نمی‌دهد و ربات دکمه‌ای برای آن ندارد.'),
            FieldDef::toggle('menu_button', 'دکمه منوی ربات', 'دکمه کنار جای نوشتن پیام در ربات، تا وقتی مینی‌اپ روشن است آن را باز می‌کند؛ خاموش باشد، همان دکمه همیشگی تلگرام می‌ماند.'),
            FieldDef::text('menu_button_text', 'متن دکمه منو', 'خالی بماند، متن پیش‌فرض نوشته می‌شود.')->nullable()->limits('حداکثر ۳۲ نویسه'),
        ];
    }

    /** The menu button's words left empty: the default said instead. */
    protected function shows(FieldDef $field, mixed $value): ?string
    {
        if ($field->name !== 'menu_button_text' || (is_string($value) && $value !== '')) {
            return null;
        }
        $default = $this->drawn()['menu_button_default'] ?? null;

        return is_string($default) && $default !== '' ? 'پیش‌فرض: «' . Fmt::e($default) . '»' : '';
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $url = is_string($values['url'] ?? null) ? $values['url'] : null;
        $bot = is_array($values['bot'] ?? null) && is_string($values['bot']['username'] ?? null) ? $values['bot']['username'] : null;
        $view->section('🔗 آدرس مینی‌اپ')
            ->card(['آدرس' => Fmt::code($url), 'ربات فروشگاه' => $bot === null ? null : Fmt::ltr('@' . $bot)])
            ->row($url === null ? null : Button::copy(Words::copy('آدرس'), $url));
        if (Json::on($values, 'secure') && Json::on($values, 'enabled') && Json::on($values, 'menu_button')) {
            $view->note('آدرس برنامه یا توکن ربات عوض شده؟ دکمه منوی ربات را با آدرس فعلی دوباره تنظیم کنید.')
                ->row(Button::to('🔁 تنظیم دوباره دکمه منو', self::ID, 'menu'));
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
