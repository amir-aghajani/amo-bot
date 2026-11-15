<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel;

use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Config\AdvancedScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Config\AppScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Config\TelegramScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «📡 دریافت پیام‌ها و وضعیت سیستم» (the owner's): whether the machinery behind the shop is alive — the version and PHP
 * (an unsafe PHP said, as the panel says it over every page), the shop's bot and how it gets its messages, the scheduled
 * tasks — and every bot put on webhooks or taken off them (the panel's «دریافت پیام‌ها»), each asked first, with what
 * happened to each bot after.
 *
 * Actions: `on` / `off` (ask), `onn` / `offf` (do).
 */
final class SystemScreen extends BaseScreen
{
    public const ID = 'ppw';

    /** How a bot gets its messages, as the panel's statuses word it. */
    private const MODE = ['webhook' => "\u{2068}Webhook\u{2069}", 'polling' => 'پولینگ', 'offline' => 'مشکل دارد'];

    /**
     * @var list<array<string, mixed>>|null What the last change did to each bot, for the update that made it (shownFor)
     */
    private ?array $outcomes = null;

    private ?Admin $shownFor = null;

    public function allows(AdminSeat $seat): bool
    {
        return Access::system($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $system = (array) ($admin->api->get('/system')['system'] ?? []);
        $bot = Json::part($system, 'bot');
        $main = Json::part($system, 'main_bot');
        $cron = Json::part($system, 'cron');
        $config = Access::main($admin->seat) ? $admin->api->get('/settings/config') : null;
        $groups = $config !== null && Json::part($config, 'groups');
        $appUrl = $config === null ? null : (is_array($groups['app'] ?? null) && is_string($groups['app']['url'] ?? null) ? $groups['app']['url'] : '');

        $view = View::make('📡 دریافت پیام‌ها و وضعیت سیستم', 'مدیریت', 'تنظیمات', 'تنظیمات پنل')
            ->line('نسخه ' . Fmt::ltr((string) ($system['version'] ?? '')) . ' · ' . Fmt::ltr('PHP ' . (string) ($system['php'] ?? '')));
        if (($system['php_safe'] ?? true) === false) {
            $view->line("⚠️ <b>\u{2068}PHP\u{2069} این هاست (" . Fmt::ltr((string) ($system['php'] ?? '')) . ') حفره امنیتی جدی دارد.</b> آن را از تنظیمات هاست (مثلا «Select PHP Version» در cPanel) به ' . Fmt::ltr('PHP ' . (string) ($system['php_required'] ?? '')) . ' یا بالاتر ببرید؛ تا آن وقت به‌روزرسانی فروشگاه نصب نمی‌شود.');
        }

        $isMain = ($bot['id'] ?? null) === Bot::MAIN;
        $name = ($isMain ? 'ربات اصلی' : 'ربات این فروشگاه') . (is_string($bot['username'] ?? null) ? ' ' . Fmt::ltr('@' . $bot['username']) : '');
        $mode = is_string($bot['mode'] ?? null) ? $bot['mode'] : 'offline';
        $view->line()->line('🤖 <b>' . $name . '</b>: ' . match (true) {
            ($bot['configured'] ?? false) !== true => 'وصل نشده',
            $mode !== 'offline' && ($bot['enabled'] ?? true) === false => 'غیرفعال از تنظیمات',
            default => self::MODE[$mode] ?? Fmt::e($mode),
        })->line('<i>' . match (true) {
            ($bot['configured'] ?? false) !== true => $isMain ? 'توکن ربات تنظیم نشده است.' : 'نماینده هنوز توکن رباتش را از ربات اصلی نفرستاده است.',
            $mode === 'offline' => 'پیامی نمی‌رسد: Webhook ثبت نشده است.',
            default => (is_string($bot['username'] ?? null) ? '' : 'توکن تنظیم شده · ') . 'آخرین پیام: ' . Fmt::date(is_string($bot['last_update_at'] ?? null) ? $bot['last_update_at'] : null, true),
        } . '</i>');
        $ran = is_string($cron['last_run_at'] ?? null) ? $cron['last_run_at'] : null;
        $view->line('⏱ <b>کارهای زمان‌بندی‌شده</b>: ' . ($ran !== null ? 'فعال' : 'تنظیم نشده'))
            ->line('<i>' . ($ran !== null ? 'آخرین اجرا: ' . Fmt::date($ran, true) . ' · ' . Fmt::number((int) ($cron['tasks'] ?? 0)) . ' وظیفه' : 'هنوز اجرا نشده‌اند؛ Cron را در «پیشرفته» راه بیندازید.') . '</i>');

        $mainMode = is_string($main['mode'] ?? null) ? $main['mode'] : 'offline';
        $view->line()
            ->line('<b>📡 دریافت پیام‌ها</b>: ' . (self::MODE[$mainMode] ?? Fmt::e($mainMode)))
            ->line('<i>با Webhook، تلگرام پیام‌ها را مستقیم به آدرس فروشگاه می‌فرستد و چیزی لازم نیست روی سرور اجرا بماند؛ راه هاست‌های اشتراکی. بدون آن، پیام‌ها فقط با پولینگ روی سرور گرفته می‌شوند. ثبت و برداشتن برای ربات اصلی و ربات همه نماینده‌ها با هم انجام می‌شود.</i>');
        $webhook = $config === null ? null : (((array) ($config['meta'] ?? []))['webhook_url'] ?? null);
        if ($mainMode === 'webhook' && is_string($webhook)) {
            $view->line("آدرس \u{2068}Webhook\u{2069} ربات اصلی: " . Fmt::ltr($webhook));
        }
        $https = $appUrl === null || str_starts_with($appUrl, 'https://');
        if (!$https) {
            $view->line("⚠️ تلگرام \u{2068}Webhook\u{2069} را فقط روی آدرس https می‌پذیرد و آدرس فروشگاه " . Fmt::ltr($appUrl === '' ? null : $appUrl) . ' است؛ آن را در «🏷 برنامه» درست کنید.');
        }
        if ($this->shownFor === $admin && $this->outcomes !== null) {
            $view->line()->lines(...self::outcomes($this->outcomes));
        }

        $view->row(
            $https ? Button::to($mainMode === 'webhook' ? '🔗 ثبت دوباره Webhook' : '🔗 ثبت Webhook', self::ID, 'on') : null,
            $mainMode === 'webhook' ? Button::to('⛓ برداشتن Webhook', self::ID, 'off') : null,
        );
        if (!$https && Access::main($admin->seat)) {
            $view->row(Button::to('🏷 برنامه', AppScreen::ID));
        }
        if (Access::main($admin->seat)) {
            $view->row(Button::to('🤖 ربات تلگرام', TelegramScreen::ID), Button::to('🛠 پیشرفته', AdvancedScreen::ID));
        }

        return $view->row(Button::to('⬆️ به‌روزرسانی', UpdateScreen::ID), Button::to('🔄 تازه‌سازی', self::ID))->back(PanelSettingsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'on':
                return $admin->confirm(
                    "\u{2068}Webhook\u{2069} ثبت شود؟",
                    Button::to('🔗 ثبت Webhook', self::ID, 'onn'),
                    Button::to('✖️ انصراف', self::ID),
                    'ربات اصلی و ربات همه نماینده‌ها روی آدرس فروشگاه ثبت می‌شوند و تلگرام پیام‌ها را مستقیم به فروشگاه می‌فرستد. اگر پولینگ روی سرور اجراست، متوقف می‌شود.',
                );
            case 'off':
                return $admin->confirm(
                    "\u{2068}Webhook\u{2069} برداشته شود؟",
                    Button::to('⛓ برداشتن Webhook', self::ID, 'offf'),
                    Button::to('✖️ انصراف', self::ID),
                    'Webhook ربات اصلی و ربات همه نماینده‌ها برداشته می‌شود و از این به بعد فقط وقتی پیامی می‌رسد که پولینگ روی سرور اجرا باشد؛ روی هاست اشتراکی معمولا این امکان نیست. این پیام هم ممکن است آخرین پیامی باشد که ربات تا آن وقت جواب می‌دهد.',
                );
            case 'onn':
            case 'offf':
                $bots = array_values(array_filter((array) (($action === 'onn' ? $admin->api->post('/system/webhook') : $admin->api->delete('/system/webhook'))['bots'] ?? []), is_array(...)));
                [$this->outcomes, $this->shownFor] = [$bots, $admin];
                if ($bots !== [] && array_filter($bots, static fn(array $bot): bool => ($bot['done'] ?? false) !== true) === []) {
                    $admin->notice($action === 'onn' ? "✅ \u{2068}Webhook\u{2069} همه ربات‌ها ثبت شد." : "✅ \u{2068}Webhook\u{2069} همه ربات‌ها برداشته شد.");
                }
                break;
        }

        return $this->show($admin, []);
    }

    /**
     * What happened to each bot — or that no bot has a token to switch.
     *
     * @param list<array<string, mixed>> $bots
     * @return list<string>
     */
    private static function outcomes(array $bots): array
    {
        if ($bots === []) {
            return ['ℹ️ هیچ رباتی توکن ندارد؛ اول توکن ربات را در «ربات تلگرام» ذخیره کنید.'];
        }
        $lines = ['<b>نتیجه برای هر ربات:</b>'];
        foreach ($bots as $each) {
            $bot = Json::part($each, 'bot');
            $name = match (true) {
                ($bot['id'] ?? null) === Bot::MAIN => 'ربات اصلی',
                is_string($bot['username'] ?? null) && $bot['username'] !== '' => Fmt::ltr('@' . $bot['username']),
                default => 'ربات نماینده ' . Fmt::id(is_int($bot['id'] ?? null) ? $bot['id'] : null),
            };
            $lines[] = (Json::on($each, 'done') ? '✅ ' : '⚠️ ') . $name . ': ' . Fmt::e((string) ($each['message'] ?? ''));
        }

        return $lines;
    }
}
