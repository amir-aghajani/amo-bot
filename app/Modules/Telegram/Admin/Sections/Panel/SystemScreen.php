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
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🛰 دریافت پیام‌ها و وضعیت سیستم» (the owner's): whether the machinery behind the shop is alive — the version and PHP
 * (an unsafe PHP said, as the panel says it over every page), the shop's bot and how it gets its messages, the scheduled
 * tasks — and every bot put on webhooks or taken off them (the panel's «دریافت پیام‌ها»), each asked first, with what
 * happened to each bot after.
 *
 * Actions: `on` / `off` (ask), `onn` / `offf` (do).
 */
final class SystemScreen extends BaseScreen
{
    public const ID = 'ppw';

    public const TITLE = Icons::SYSTEM . ' دریافت پیام‌ها و وضعیت سیستم';

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
        $groups = $config !== null ? Json::part($config, 'groups') : [];
        $appUrl = $config === null ? null : (is_array($groups['app'] ?? null) && is_string($groups['app']['url'] ?? null) ? $groups['app']['url'] : '');

        $view = View::make(self::TITLE, ...PanelSettingsScreen::under());
        if (($system['php_safe'] ?? true) === false) {
            $view->line("⚠️ <b>\u{2068}PHP\u{2069} این هاست (" . Fmt::ltr((string) ($system['php'] ?? '')) . ") حفره امنیتی جدی دارد.</b> آن را از تنظیمات هاست (مثلا «\u{2068}Select PHP Version\u{2069}» در \u{2068}cPanel\u{2069}) به " . Fmt::ltr('PHP ' . (string) ($system['php_required'] ?? '')) . ' یا بالاتر ببرید؛ تا آن وقت به‌روزرسانی فروشگاه نصب نمی‌شود.');
        }
        $https = $appUrl === null || str_starts_with($appUrl, 'https://');
        if (!$https) {
            $view->line("⚠️ تلگرام \u{2068}Webhook\u{2069} را فقط روی آدرس https می‌پذیرد و آدرس فروشگاه " . Fmt::ltr($appUrl === '' ? null : $appUrl) . ' است؛ آن را در «' . AppScreen::TITLE . '» درست کنید.');
        }

        $isMain = ($bot['id'] ?? null) === Bot::MAIN;
        $mode = is_string($bot['mode'] ?? null) ? $bot['mode'] : 'offline';
        $mainMode = is_string($main['mode'] ?? null) ? $main['mode'] : 'offline';
        $ran = is_string($cron['last_run_at'] ?? null) ? $cron['last_run_at'] : null;
        $webhook = $config === null ? null : (((array) ($config['meta'] ?? []))['webhook_url'] ?? null);
        // Whether the shop's bot's line says how it gets its messages: not while it has no token, or is off in settings.
        $saysMode = ($bot['configured'] ?? false) === true && !($mode !== 'offline' && ($bot['enabled'] ?? true) === false);
        $view->card([
            'نسخه' => Fmt::ltr((string) ($system['version'] ?? '')) . ' · ' . Fmt::ltr('PHP ' . (string) ($system['php'] ?? '')),
            ($isMain ? 'ربات اصلی' : 'ربات این فروشگاه') . (is_string($bot['username'] ?? null) ? ' ' . Fmt::plain(Fmt::ltr('@' . $bot['username'])) : '') => match (true) {
                ($bot['configured'] ?? false) !== true => 'وصل نشده — ' . ($isMain ? 'توکن ربات تنظیم نشده است' : 'نماینده هنوز توکن رباتش را از ربات اصلی نفرستاده است'),
                $mode !== 'offline' && ($bot['enabled'] ?? true) === false => 'غیرفعال از تنظیمات',
                $mode === 'offline' => self::MODE['offline'] . ' — Webhook ثبت نشده است',
                default => (self::MODE[$mode] ?? Fmt::e($mode)) . ' · آخرین پیام: ' . Fmt::date(is_string($bot['last_update_at'] ?? null) ? $bot['last_update_at'] : null, true),
            },
            'کارهای زمان‌بندی‌شده' => $ran !== null ? 'فعال · آخرین اجرا: ' . Fmt::date($ran, true) . ' · ' . Fmt::number((int) ($cron['tasks'] ?? 0)) . ' وظیفه' : 'هنوز اجرا نشده‌اند؛ Cron را در «پیشرفته» راه بیندازید',
            // The main bot's way, said once: left out when the line above is the main bot's and says it already.
            'دریافت پیام‌های ربات اصلی' => $isMain && $saysMode ? null : self::MODE[$mainMode] ?? Fmt::e($mainMode),
            "آدرس \u{2068}Webhook\u{2069} ربات اصلی" => $mainMode === 'webhook' && is_string($webhook) ? Fmt::code($webhook) : null,
        ]);
        if ($this->shownFor === $admin && $this->outcomes !== null) {
            $view->section('نتیجه برای هر ربات')->lines(...self::outcomes($this->outcomes));
        }

        $view->row(
            $https ? Button::to($mainMode === 'webhook' ? '🔗 ثبت دوباره Webhook' : '🔗 ثبت Webhook', self::ID, 'on')->success() : null,
            $mainMode === 'webhook' ? Button::to('⛓ برداشتن Webhook', self::ID, 'off')->danger() : null,
        );
        if (!$https && Access::main($admin->seat)) {
            $view->row(Button::to(AppScreen::TITLE, AppScreen::ID)->primary());
        }
        if (Access::main($admin->seat)) {
            $view->row(Button::to(TelegramScreen::TITLE, TelegramScreen::ID), Button::to(AdvancedScreen::TITLE, AdvancedScreen::ID));
        }

        return $view->row(Button::to(UpdateScreen::TITLE, UpdateScreen::ID))
            ->help('با Webhook، تلگرام پیام‌ها را مستقیم به آدرس فروشگاه می‌فرستد و چیزی لازم نیست روی سرور اجرا بماند؛ راه هاست‌های اشتراکی. بدون آن، پیام‌ها فقط با پولینگ روی سرور گرفته می‌شوند. ثبت و برداشتن برای ربات اصلی و ربات همه نماینده‌ها با هم انجام می‌شود.')
            ->back(PanelSettingsScreen::ID)
            ->refresh(self::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'on':
                return $admin->confirm(
                    "\u{2068}Webhook\u{2069} ثبت شود؟",
                    Button::to(Words::yes('ثبت شود'), self::ID, 'onn'),
                    Button::to(Words::CANCEL, self::ID),
                    'ربات اصلی و ربات همه نماینده‌ها روی آدرس فروشگاه ثبت می‌شوند و تلگرام پیام‌ها را مستقیم به فروشگاه می‌فرستد. اگر پولینگ روی سرور اجراست، متوقف می‌شود.',
                    danger: false,
                );
            case 'off':
                return $admin->confirm(
                    "\u{2068}Webhook\u{2069} برداشته شود؟",
                    Button::to(Words::yes('برداشته شود'), self::ID, 'offf'),
                    Button::to(Words::CANCEL, self::ID),
                    'Webhook ربات اصلی و ربات همه نماینده‌ها برداشته می‌شود و از این به بعد فقط وقتی پیامی می‌رسد که پولینگ روی سرور اجرا باشد؛ روی هاست اشتراکی معمولا این امکان نیست. این پیام هم ممکن است آخرین پیامی باشد که ربات تا آن وقت جواب می‌دهد.',
                    danger: true,
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
        $lines = [];
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
