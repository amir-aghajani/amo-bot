<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\MiniApp;

use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use Illuminate\Support\Carbon;

/**
 * «پیش‌نمایش» of the mini app: the app as one of the shop's customers sees it — the customer picked among the newest who
 * are not banned, or found by a search (the users list's own) —, a read-only session for them (POST /mini-app/preview,
 * 30 minutes, the app on or off) opened as a link in Telegram's light or dark theme, opened anew at a tap, a new
 * customer picked at another. The panel draws a phone around it; a chat opens the app's own address.
 *
 * Actions: `pick` (user id), `search` (asks), `clear`, `theme`, `again`, `other`.
 */
final class PreviewScreen extends BaseScreen
{
    public const ID = 'map';

    /** How many customers the picker lists: a search narrows the rest. */
    private const SHOWN = 6;

    /** What Telegram hands a mini app as it launches it: the Bot API version and a phone (the panel's telegram.ts). */
    private const VERSION = '8.0';

    private const PLATFORM = 'android';

    /** Telegram's own light and dark themes, as its Android client hands them to a mini app (the panel's telegram.ts). */
    private const THEMES = [
        'light' => [
            'bg_color' => '#ffffff', 'secondary_bg_color' => '#efeff4', 'section_bg_color' => '#ffffff', 'text_color' => '#000000',
            'hint_color' => '#8e8e93', 'link_color' => '#2481cc', 'button_color' => '#2481cc', 'button_text_color' => '#ffffff',
            'accent_text_color' => '#2481cc', 'section_header_text_color' => '#6d6d72', 'subtitle_text_color' => '#8e8e93',
            'destructive_text_color' => '#e53935', 'section_separator_color' => '#c8c7cc', 'header_bg_color' => '#efeff4',
            'bottom_bar_bg_color' => '#ffffff',
        ],
        'dark' => [
            'bg_color' => '#212121', 'secondary_bg_color' => '#0f0f0f', 'section_bg_color' => '#212121', 'text_color' => '#ffffff',
            'hint_color' => '#aaaaaa', 'link_color' => '#8774e1', 'button_color' => '#8774e1', 'button_text_color' => '#ffffff',
            'accent_text_color' => '#8774e1', 'section_header_text_color' => '#8774e1', 'subtitle_text_color' => '#aaaaaa',
            'destructive_text_color' => '#ff595a', 'section_separator_color' => '#2c2c2c', 'header_bg_color' => '#212121',
            'bottom_bar_bg_color' => '#212121',
        ],
    ];

    public function show(Admin $admin, array $args): View
    {
        $app = MiniAppSource::app($admin->api->get('/mini-app'));
        $state = $admin->state(self::ID);
        $session = is_array($state['session'] ?? null) ? $state['session'] : null;
        $theme = ($state['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
        $view = View::make('👁 پیش‌نمایش مینی‌اپ', ...MiniAppScreen::under())
            ->line('مینی‌اپ را همان‌طور ببینید که یک مشتری در تلگرامش می‌بیند: با حساب، سرویس‌ها و کیف پول خود او.');

        if ($session === null) {
            $search = is_string($state['search'] ?? null) ? $state['search'] : '';
            $users = array_slice(Json::rows($admin->api->get('/users', ['status' => 'active', 'search' => $search]), 'users'), 0, self::SHOWN);
            $view->section('👤 یک مشتری انتخاب کنید');
            if ($search !== '') {
                $view->line('🔎 «' . Fmt::e($search) . '»');
            }
            if ($users === []) {
                $view->line($search === '' ? 'هنوز مشتری‌ای نیست.' : 'کسی با این جستجو پیدا نشد.');
            }
            $names = [];
            foreach ($users as $user) {
                $names[(int) ($user['id'] ?? 0)] = self::name($user);
                $view->row(Button::to('👤 ' . self::name($user) . ' · ' . Fmt::id(is_int($user['id'] ?? null) ? $user['id'] : null), self::ID, 'pick', (int) ($user['id'] ?? 0)));
            }
            $admin->remember(self::ID, ['names' => $names]);
            $view->row($search === '' ? Button::to(Words::SEARCH, self::ID, 'search') : Button::to(Words::CLEAR_SEARCH, self::ID, 'clear'));
        } else {
            $ends = Fmt::moment(is_string($session['expires_at'] ?? null) ? $session['expires_at'] : null);
            $ended = $ends === null || $ends->isPast();
            $view->card([
                'مشتری' => Fmt::e((string) ($session['name'] ?? '')) . ' ' . Fmt::id(is_int($session['user'] ?? null) ? $session['user'] : null),
                'تم تلگرام' => $theme === 'dark' ? '🌙 تیره' : '☀️ روشن',
                'باز تا' => $ends === null ? null : Fmt::date($ends->toIso8601String(), true),
            ]);
            if ($ended) {
                $view->line('⏰ <b>زمان پیش‌نمایش تمام شد.</b> هر پیش‌نمایش ۳۰ دقیقه باز می‌ماند؛ برای دیدن دوباره، آن را دوباره باز کنید.');
            } else {
                $view->row(Button::url('👁 باز کردن پیش‌نمایش', self::address($app, $session, $theme)));
            }
            $view->row(
                Button::to($theme === 'dark' ? '☀️ تم روشن' : '🌙 تم تیره', self::ID, 'theme'),
                Button::to('🔁 باز کردن دوباره', self::ID, 'again')->primary(),
            )->row(Button::to('👤 مشتری دیگر', self::ID, 'other'));
            $view->note('لینک را با کسی به اشتراک نگذارید: تا پایان پیش‌نمایش، حساب همین مشتری را نشان می‌دهد.');
        }

        return $view->help('پیش‌نمایش فقط نشان می‌دهد: در آن خرید، پرداخت، تیکت یا هیچ تغییری انجام نمی‌شود. هر بار ۳۰ دقیقه باز می‌ماند.'
            . (($app['enabled'] ?? false) !== true ? ' مینی‌اپ خاموش است، ولی پیش‌نمایش با این حال کار می‌کند؛ مشتری‌ها چیزی نمی‌بینند.' : ''))
            ->back(MiniAppScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $session = $admin->state(self::ID)['session'] ?? null;
        switch ($action) {
            case 'pick':
                $this->open($admin, self::id($args), self::nameOf($admin, self::id($args)));
                break;
            case 'again':
                if (is_array($session) && is_int($session['user'] ?? null)) {
                    $this->open($admin, $session['user'], (string) ($session['name'] ?? ''));
                }
                break;
            case 'theme':
                $admin->remember(self::ID, ['theme' => ($admin->state(self::ID)['theme'] ?? 'light') === 'dark' ? 'light' : 'dark']);
                break;
            case 'other':
                $admin->remember(self::ID, ['session' => null]);
                break;
            case 'search':
                return $admin->ask((new Prompt('کدام مشتری؟', self::ID, 'search', [], 'نام، نام کاربری، شناسه تلگرام یا شماره موبایل.'))->example('@ali_r'));
            case 'clear':
                $admin->remember(self::ID, ['search' => '']);
                break;
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action === 'search') {
            $admin->remember(self::ID, ['search' => mb_substr($answer->text(), 0, 100)]);
        }

        return $this->show($admin, []);
    }

    /** A preview opened as customer `$user`, kept for the screen: its token, its end and the app's address on this host. */
    private function open(Admin $admin, int $user, string $name): void
    {
        $preview = (array) ($admin->api->post('/mini-app/preview', ['user_id' => $user])['preview'] ?? []);
        $admin->remember(self::ID, ['session' => [
            'user' => $user,
            'name' => $name,
            'token' => (string) ($preview['token'] ?? ''),
            'path' => (string) ($preview['path'] ?? '/'),
            'expires_at' => is_string($preview['expires_at'] ?? null) ? $preview['expires_at'] : Carbon::now()->addMinutes(30)->toIso8601String(),
        ]]);
        $admin->notice('✅ پیش‌نمایش باز شد.');
    }

    /** A customer's name off the list the picker showed (the newest, or a search's), for the screen to say. */
    private static function nameOf(Admin $admin, int $user): string
    {
        $names = $admin->state(self::ID)['names'] ?? [];
        $name = is_array($names) ? ($names[$user] ?? $names[(string) $user] ?? '') : '';

        return is_string($name) ? $name : '';
    }

    /**
     * Where the link opens the app: this host's origin, the app's address, and in its fragment what Telegram hands a mini
     * app as it launches it — the preview's token in place of the launch data, the Bot API version, a phone, the theme.
     *
     * @param array<string, mixed> $app
     * @param array<string, mixed> $session
     */
    private static function address(array $app, array $session, string $theme): string
    {
        $origin = DraftForm::origin(is_string($app['url'] ?? null) ? $app['url'] : '');
        $fragment = http_build_query([
            'tgWebAppPreview' => (string) ($session['token'] ?? ''),
            'tgWebAppVersion' => self::VERSION,
            'tgWebAppPlatform' => self::PLATFORM,
            'tgWebAppThemeParams' => (string) json_encode(self::THEMES[$theme]),
        ], '', '&', PHP_QUERY_RFC3986);

        return $origin . '/' . ltrim((string) ($session['path'] ?? ''), '/') . '#' . $fragment;
    }

    /** @param array<string, mixed> $user */
    private static function name(array $user): string
    {
        return match (true) {
            is_string($user['name'] ?? null) && $user['name'] !== '' => $user['name'],
            is_string($user['username'] ?? null) && $user['username'] !== '' => '@' . $user['username'],
            is_int($user['telegram_id'] ?? null) => (string) $user['telegram_id'],
            default => '#' . (int) ($user['id'] ?? 0),
        };
    }
}
