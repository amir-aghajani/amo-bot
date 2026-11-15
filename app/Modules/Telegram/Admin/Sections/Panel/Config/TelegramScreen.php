<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Config;

use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Sections\Panel\SystemScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «ربات تلگرام» of config.php: the main bot's token — checked with Telegram before saving, its @username filled in from
 * the check —, its @username, and the advanced rest. Moving the Bot API address to another origin sends every bot's token
 * there: the token is typed again and the save asks for the owner's password.
 *
 * Actions besides the form's: `test` (the token checked).
 */
final class TelegramScreen extends ConfigScreen
{
    public const ID = 'ppt';

    /** When a save here reaches the main bot, by how it gets its messages (the panel's words, without the console's). */
    private const AFTER_SAVE = [
        'webhook' => 'ربات روی Webhook است و مقادیر تازه از پیام بعدی به کار می‌روند؛ اگر توکن ربات دیگری را گذاشتید یا رمز Webhook را عوض کردید، Webhook را در «📡 دریافت پیام‌ها» دوباره ثبت کنید.',
        'polling' => 'ربات با پولینگ کار می‌کند و مقادیر تازه را وقتی دوباره راه بیفتد می‌خواند؛ روی هاست، Webhook را در «📡 دریافت پیام‌ها» ثبت کنید تا از پیام بعدی به کار بروند.',
        'offline' => 'ربات الان پیامی نمی‌گیرد: Webhook را در «📡 دریافت پیام‌ها» ثبت کنید.',
    ];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🤖 ربات اصلی';
    }

    protected function group(): string
    {
        return 'telegram';
    }

    protected function done(): string
    {
        return '✅ تنظیمات ربات ذخیره شد.';
    }

    protected function formOf(array $config): DraftForm
    {
        $group = self::groupOf($config, 'telegram');
        $webhook = is_string(self::meta($config)['webhook_url'] ?? null) ? self::meta($config)['webhook_url'] : '';
        $token = DraftForm::field('token', 'secret', 'توکن ربات اصلی', "از \u{2068}BotFather\u{2069} با دستور \u{2068}/newbot\u{2069} یا \u{2068}/token\u{2069} بگیرید.", required: true);
        $token['bound_to'] = ['api_url'];
        $token['moved'] = 'آدرس API تلگرام عوض شده است؛ توکن ربات اصلی را دوباره وارد کنید: توکن ذخیره‌شده به آدرس دیگری فرستاده نمی‌شود.';

        return DraftForm::fixed([
            $token,
            DraftForm::field('username', 'text', 'نام کاربری ربات اصلی', 'بدون @؛ با «🧪 بررسی توکن» خودش پر می‌شود.', ltr: true),
            DraftForm::field('api_url', 'url', "آدرس \u{2068}API\u{2069} تلگرام", "برای سرور \u{2068}Bot API\u{2069} محلی یا آینه تغییر دهید؛ توکن همه ربات‌ها، ربات‌های نماینده‌ها هم، به همین آدرس فرستاده می‌شود.", required: true),
            DraftForm::field('current_password', 'secret', 'رمز عبور فعلی پنل', 'آدرس API تلگرام به جای دیگری می‌رود و از این به بعد توکن همه ربات‌ها، ربات‌های نماینده‌ها هم، به آن فرستاده می‌شود؛ برای همین رمز عبور ورود به پنل خواسته می‌شود.', required: true, transient: true),
            DraftForm::field('poll_timeout', 'number', "تایم‌اوت \u{2068}long polling\u{2069}", 'فقط در حالت polling استفاده می‌شود.', required: true, unit: 'ثانیه', min: 1, max: 60),
            DraftForm::field('webhook_secret', 'secret', "رمز \u{2068}Webhook\u{2069}", 'بخشی از آدرس Webhook است' . ($webhook !== '' ? ': ' . $webhook : '') . '. بعد از تغییر، Webhook را در «📡 دریافت پیام‌ها» دوباره ثبت کنید.', generate: 24),
        ], $group);
    }

    protected function visible(array $field, DraftForm $form, string $driver, array $values): bool
    {
        return DraftForm::name($field) !== 'current_password' || $this->moves($form, $values);
    }

    protected function intro(Admin $admin, DraftForm $form, string $driver): array
    {
        $system = $admin->api->get('/system')['system'] ?? null;
        $main = is_array($system) && Json::part($system, 'main_bot');
        $mode = $main['mode'] ?? null;
        $lines = ["توکن \u{2068}BotFather\u{2069} و نام کاربری ربات اصلی فروشگاه." . (is_string($mode) && isset(self::AFTER_SAVE[$mode]) ? ' ' . self::AFTER_SAVE[$mode] : '')];
        $test = $admin->state(self::ID)['test'] ?? null;
        if (is_array($test)) {
            $lines[] = '';
            $lines[] = '✅ <b>ربات شناسایی شد:</b> ' . Fmt::e((string) ($test['name'] ?? '')) . ' · ' . Fmt::ltr('@' . (string) ($test['username'] ?? '')) . ' · ' . Fmt::ltr('id ' . (string) ($test['id'] ?? ''));
        }

        return [...parent::intro($admin, $form, $driver), ...$lines];
    }

    protected function extra(View $view, Admin $admin, DraftForm $form, string $driver, array $values): void
    {
        $view->row(Button::to('🧪 بررسی توکن', self::ID, 'test'), Button::to('📡 دریافت پیام‌ها', SystemScreen::ID));
    }

    protected function action(Admin $admin, string $action, array $args, DraftForm $form, string $driver, array $values): View
    {
        if ($action !== 'test') {
            return parent::action($admin, $action, $args, $form, $driver, $values);
        }
        $token = (string) ($values['token'] ?? '');
        $typed = DraftForm::unseal($token, $this->crypt);
        $bot = (array) ($admin->api->post('/settings/config/telegram/test', $typed === '' ? [] : ['token' => $typed])['bot'] ?? []);
        $admin->remember(self::ID, ['test' => ['name' => $bot['name'] ?? '', 'username' => $bot['username'] ?? '', 'id' => $bot['id'] ?? ''], 'tested' => $token]);
        if (is_string($bot['username'] ?? null) && $bot['username'] !== ($values['username'] ?? '')) {
            $values['username'] = $bot['username'];
            $this->keep($admin, $driver, $values, []);
        }
        $admin->notice('✅ توکن درست است.');

        return $this->card($admin, $form);
    }

    protected function keep(Admin $admin, string $driver, array $values, array $errors): void
    {
        parent::keep($admin, $driver, $values, $errors);
        // What the check said was about the token it checked: another one typed, it goes.
        if (($values['token'] ?? '') !== ($admin->state(self::ID)['tested'] ?? null)) {
            $admin->remember(self::ID, ['test' => null, 'tested' => null]);
        }
    }

    protected function forgetDraft(Admin $admin): void
    {
        parent::forgetDraft($admin);
        $admin->remember(self::ID, ['test' => null, 'tested' => null]);
    }

    /**
     * Whether the draft moves the Bot API's address to another origin (scheme, host or port).
     *
     * @param array<string, string|bool> $values
     */
    private function moves(DraftForm $form, array $values): bool
    {
        $now = DraftForm::origin((string) ($values['api_url'] ?? ''));
        $was = $form->stored['']['api_url'] ?? '';

        return $now !== '' && str_contains($now, '://') && $now !== DraftForm::origin(is_string($was) ? $was : '');
    }
}
