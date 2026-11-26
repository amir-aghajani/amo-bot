<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot;

use App\Modules\Telegram\Admin\Sections\Bot\Settings\ReportTopicsCard;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\Origin;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «📋 گروه گزارش‌ها» (the owner's and an agent's; the panel's report group card): not connected, the steps in Telegram and
 * the "add the bot to a group" link (a one-time code) or the message to send there instead, and why the last group did
 * not connect; connected, the group, whether all is well, what waits to be sent and its topics, with «📨 پیام تست»,
 * «🔍 بررسی دوباره», another group connected, and «✂️ قطع اتصال», asked first. «🧵 بخش‌های گزارش» is the topics' switches
 * (ReportTopicsCard). Each action applies at once. Opened from a 0.10.0 window's «🤖 تنظیمات ربات», its «⬅️ بازگشت»
 * goes back there (Origin).
 *
 * Actions: `link` (a new connect link), `other` (the steps, to connect another group), `test`, `check`, `cut` (asks),
 * `cutd`, `v` (the card again, its origin kept).
 */
final class ReportGroupScreen extends BaseScreen
{
    public const ID = 'rg';

    public const TITLE = Icons::REPORT_GROUP . ' گروه گزارش‌ها';

    /** The card again where it was left, its origin kept: the way back of its topics' switches. */
    public const VIEW = 'v';

    public function show(Admin $admin, array $args): View
    {
        Origin::opened($admin, self::ID, $args);

        return $this->view($admin, self::group($admin->api->get('/bot/report-group')), false);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'link':
                return $this->view($admin, self::group($admin->api->post('/bot/report-group/link')), true);
            case 'other':
                return $this->view($admin, self::group($admin->api->get('/bot/report-group')), true);
            case 'test':
                $answer = $admin->api->post('/bot/report-group/test');
                $queued = (int) ($answer['queued'] ?? 0);
                $sent = (int) ($answer['sent'] ?? 0);
                if ($queued === 0) {
                    $admin->alert('همه بخش‌های گزارش خاموش است؛ پیامی فرستاده نشد.');
                } elseif ($sent === $queued) {
                    $admin->notice('✅ پیام تست به ' . Fmt::plain(Fmt::number($sent)) . ' تاپیک فرستاده شد');
                } else {
                    $admin->alert(Fmt::plain(Fmt::number($queued - $sent)) . ' پیام تست در صف ارسال ماند.');
                }

                return $this->view($admin, self::group($answer), false);
            case 'check':
                $group = self::group($admin->api->post('/bot/report-group/check'));
                if (is_string($group['problem'] ?? null)) {
                    $admin->alert('گروه بررسی شد؛ مشکل هنوز هست.');
                } else {
                    $admin->notice('✅ گروه درست است و ربات دسترسی‌هایش را دارد');
                }

                return $this->view($admin, $group, false);
            case 'cut':
                $group = self::group($admin->api->get('/bot/report-group'));

                return $admin->confirm(
                    'قطع اتصال گروه گزارش‌ها؟',
                    Button::to(Words::yes('قطع شود', '✂️'), self::ID, 'cutd'),
                    Button::to(Words::CANCEL, self::ID, self::VIEW),
                    (is_string($group['title'] ?? null) ? 'گزارش‌ها دیگر به «' . Fmt::e($group['title']) . '» فرستاده نمی‌شود و گزارش‌هایی که در صف مانده‌اند دور ریخته می‌شوند. ' : '')
                        . 'ربات در گروه می‌ماند و پیام‌های قبلی سر جایشان هستند؛ اگر نمی‌خواهید، ربات را از تلگرام از گروه بیرون کنید.',
                );
            case 'cutd':
                $group = self::group($admin->api->delete('/bot/report-group'));
                $admin->notice('✂️ اتصال گروه گزارش‌ها قطع شد');

                return $this->view($admin, $group, false);
        }

        return $this->view($admin, self::group($admin->api->get('/bot/report-group')), false);
    }

    /**
     * The group's card; `$connecting` shows the steps to connect one beside a connected group.
     *
     * @param array<string, mixed> $group
     */
    private function view(Admin $admin, array $group, bool $connecting): View
    {
        $main = $admin->seat->shop()->isMain();
        $view = View::make(self::TITLE, ...BotScreens::PATH);
        $connected = Json::on($group, 'connected');
        if ($connected) {
            $this->connected($view, $group);
        }
        if (!$connected || $connecting) {
            $this->steps($view, $group, $main);
            if ($connected) {
                $view->line('<i>با وصل شدن گروه دیگر، گزارش‌ها به آن گروه می‌رود و «' . Fmt::e((string) ($group['title'] ?? '')) . '» کنار گذاشته می‌شود.</i>');
            }
        }
        if ($connected) {
            $view->row(Button::to('📨 پیام تست', self::ID, 'test')->primary(), Button::to('🔍 بررسی دوباره', self::ID, 'check'));
        }
        $view->row(Button::to(ReportTopicsCard::TITLE, ReportTopicsCard::ID));
        if ($connected) {
            $view->row(
                $connecting ? null : Button::to('🔗 اتصال گروه دیگر', self::ID, 'other'),
                Button::to('✂️ قطع اتصال', self::ID, 'cut')->danger(),
            );
        }

        return $view->help('گزارش خریدها، تمدیدها، اکانت‌های تست، حذف سرویس‌ها، شارژ کیف پول، رسیدها، کاربران جدید، خطاها، تیکت‌ها، نظرات، پشتیبان‌ها' . ($main ? '، آمار روزانه و درخواست‌های نمایندگی' : ' و آمار روزانه') . ' در یک گروه تلگرام، هر کدام در تاپیک خودش. ربات تاپیک‌ها را خودش می‌سازد و مدیرهای ربات رسیدها را همان‌جا با دکمه تایید یا رد می‌کنند و با ریپلای به تیکت‌ها پاسخ می‌دهند. کدام گزارش‌ها فرستاده شوند در «' . Fmt::e(ReportTopicsCard::TITLE) . '» است.')
            ->back(Origin::back($admin, self::ID, BotScreens::MENU))
            ->refresh(self::ID, self::VIEW);
    }

    /**
     * The connected group: its name and id, whether all is well, what waits to be sent, and its topics.
     *
     * @param array<string, mixed> $group
     */
    private function connected(View $view, array $group): void
    {
        $problem = is_string($group['problem'] ?? null) ? $group['problem'] : null;
        if ($problem !== null) {
            $view->line('⚠️ ' . Fmt::e($problem) . ' بعد از درست کردن، «🔍 بررسی دوباره» را بزنید.');
        }
        $waiting = (int) ($group['waiting'] ?? 0);
        $view->card([
            'وضعیت' => $problem !== null ? '⚠️ مشکل دارد' : '🟢 وصل است',
            'گروه' => Fmt::e(is_string($group['title'] ?? null) ? $group['title'] : 'گروه بدون نام'),
            'شناسه' => Fmt::code(is_scalar($group['chat_id'] ?? null) ? (string) $group['chat_id'] : null),
            'وصل شده در' => Fmt::date(is_string($group['connected_at'] ?? null) ? $group['connected_at'] : null),
            'در صف ارسال' => $waiting > 0 ? Fmt::number($waiting) . ' گزارش' . (is_string($group['paused_until'] ?? null) ? ' · تلگرام تا ' . Fmt::date($group['paused_until'], true) . ' نگه داشته' : '') : null,
        ]);

        // The topics by their state, a line each: made, not made yet (made with their first report), switched off.
        $states = ['🟢 ساخته شده' => [], '🟡 ساخته نشده' => [], Words::OFF => []];
        foreach ((array) ($group['topics'] ?? []) as $topic) {
            if (!is_array($topic)) {
                continue;
            }
            $states[match (true) {
                ($topic['enabled'] ?? true) !== true => Words::OFF,
                Json::on($topic, 'ready') => '🟢 ساخته شده',
                default => '🟡 ساخته نشده',
            }][] = Fmt::e((string) ($topic['title'] ?? ''));
        }
        $view->section(Icons::REPORT_TOPICS . ' تاپیک‌ها');
        foreach ($states as $state => $titles) {
            if ($titles !== []) {
                $view->line($state . ': ' . implode('، ', $titles));
            }
        }
        if ($states['🟡 ساخته نشده'] !== []) {
            $view->line('<i>ربات تاپیکی را که ساخته نشده با اولین گزارش همان بخش در گروه می‌سازد.</i>');
        }
        if ($waiting > 0) {
            $view->line('<i>تلگرام در هر گروه بیشتر از ۲۰ پیام در دقیقه نمی‌پذیرد، پس گزارش‌ها به نوبت فرستاده می‌شوند.</i>');
        }
    }

    /**
     * Connecting a group: the steps in Telegram, then the link (or the message to send instead), or why none can be made.
     *
     * @param array<string, mixed> $group
     */
    private function steps(View $view, array $group, bool $main): void
    {
        $link = is_array($group['link'] ?? null) ? $group['link'] : null;
        // Step 2 names the buttons the card has now: the link to make first, or the one that adds the bot.
        $view->section('🔗 وصل کردن گروه')
            ->line("۱. در تلگرام یک گروه بسازید (یا گروهی خالی که دارید) و از تنظیمات گروه گزینه \u{2068}Topics\u{2069} (تاپیک‌ها) را روشن کنید.")
            ->line(($link === null ? '۲. «🔗 ساخت لینک اتصال» را بزنید؛ بعد با دکمه «➕ افزودن ربات به گروه»' : '۲. با دکمه «➕ افزودن ربات به گروه»') . ' همان گروه را انتخاب کنید؛ ربات با دسترسی «مدیریت تاپیک‌ها» مدیر گروه می‌شود.')
            ->line('۳. ربات تاپیک‌ها را خودش می‌سازد؛ بعد «🔄 تازه‌سازی» را بزنید تا وصل شدن گروه همین‌جا دیده شود.');
        if (($group['bot_username'] ?? null) === null) {
            $view->line('⚠️ ' . ($main
                ? "نام کاربری ربات هنوز مشخص نیست: در «تنظیمات پنل»، بخش «ربات تلگرام»، «بررسی توکن» را بزنید و ذخیره کنید (یا \u{2068}Webhook\u{2069} را ثبت کنید) تا نامش از تلگرام گرفته شود؛ بعد لینک اتصال ساخته می‌شود."
                : 'ربات این فروشگاه هنوز وصل نشده است: توکنش در ربات اصلی از «نمایندگی» ← «ربات من» فرستاده می‌شود؛ بعد لینک اتصال ساخته می‌شود.'));
        } elseif ($link !== null) {
            $url = is_string($link['url'] ?? null) ? $link['url'] : '';
            $command = is_string($link['command'] ?? null) ? $link['command'] : null;
            $view->line()
                ->line('⏳ در انتظار وصل شدن گروه… لینک فقط یک بار و تا ' . Fmt::date(is_string($link['expires_at'] ?? null) ? $link['expires_at'] : null, true) . ' کار می‌کند:')
                ->line(Fmt::code($url))
                ->line('<i>لینک در تلگرام باز نشد؟ ربات را خودتان مدیر گروه کنید و دسترسی «مدیریت تاپیک‌ها» را به آن بدهید، بعد این پیام را در گروه بفرستید:</i>')
                ->line(Fmt::code($command));
            $view->row(str_starts_with($url, 'https://') ? Button::url('➕ افزودن ربات به گروه', $url)->primary() : null)
                ->row($command !== null ? Button::copy(Words::copy('پیام اتصال'), $command) : null, Button::to('🔗 لینک تازه', self::ID, 'link'));
        } else {
            $view->row(Button::to('🔗 ساخت لینک اتصال', self::ID, 'link')->primary());
        }
        $attempt = is_array($group['attempt'] ?? null) ? $group['attempt'] : null;
        if ($attempt !== null) {
            $view->line()->line('⛔️ گروه «' . Fmt::e((string) ($attempt['title'] ?? '')) . '» وصل نشد: ' . Fmt::e((string) ($attempt['message'] ?? '')));
        }
    }

    /**
     * The group as an answer carries it.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private static function group(array $answer): array
    {
        return Json::part($answer, 'group');
    }
}
