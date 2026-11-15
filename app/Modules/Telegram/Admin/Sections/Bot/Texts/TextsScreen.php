<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Texts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «📝 متن‌های ربات» (the owner's and an agent's): every text the bot sends, by where it shows — the panel's tabs «همه» and
 * «تغییر یافته» as chips, each with what it counts —, a group opening its texts (TextGroupScreen); and the panel's search
 * (title, what it is for, its wording, its key; Persian as typed: Arabic ی/ک, the half-space and the direction marks
 * ignored), whose finds are listed here, paged. The texts are the panel's one answer (GET /bot/texts).
 *
 * Actions: `f` (all|changed), `search` (asks), `clear`, `page` (n).
 */
final class TextsScreen extends BaseScreen
{
    public const ID = 'tx';

    /** The kinds' names (the panel's KIND_LABELS). */
    public const KINDS = ['message' => 'پیام', 'caption' => 'کپشن عکس', 'popup' => 'اعلان', 'button' => 'دکمه', 'part' => 'بخشی از پیام'];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $state = $admin->state(self::ID);
        $changed = ($state['f'] ?? 'all') === 'changed';
        $search = (string) ($state['search'] ?? '');
        $groups = self::groups($admin);
        $found = array_merge(...array_map(static fn(array $group): array => array_values(array_filter($group['texts'], static fn(array $text): bool => self::matches($text, $search))), $groups));
        $customized = count(array_filter($found, static fn(array $text): bool => Json::on($text, 'customized')));

        $view = View::make('📝 متن‌های ربات', 'مدیریت', 'ربات')
            ->line("هر پیامی که ربات برای مشتری می‌فرستد، با متن خودتان. متغیرهایی مثل \u{2068}%name%\u{2069} هنگام ارسال با مقدار واقعی پر می‌شوند.");
        if ($search !== '') {
            $view->line('🔎 «' . Fmt::e($search) . '»');
        }
        $view->row(
            Button::to(($changed ? '' : '● ') . 'همه (' . Fmt::plain(Fmt::number(count($found))) . ')', self::ID, 'f', 'all'),
            Button::to(($changed ? '● ' : '') . 'تغییر یافته (' . Fmt::plain(Fmt::number($customized)) . ')', self::ID, 'f', 'changed'),
        );

        $shown = $changed ? array_values(array_filter($found, static fn(array $text): bool => Json::on($text, 'customized'))) : $found;
        if ($shown === []) {
            $view->line()->line($changed && $search === ''
                ? '<b>هنوز متنی تغییر نکرده است.</b> ربات همه متن‌ها را با متن پیش‌فرض فروشگاه می‌فرستد.'
                : '<b>متنی پیدا نشد.</b> با این جستجو یا فیلتر متنی نیست.');
        } elseif ($search !== '') {
            $this->found($view, $admin, $shown);
        } else {
            foreach ($groups as $group) {
                $texts = $changed ? array_filter($group['texts'], static fn(array $text): bool => Json::on($text, 'customized')) : $group['texts'];
                if ($texts === []) {
                    continue;
                }
                $mine = count(array_filter($texts, static fn(array $text): bool => Json::on($text, 'customized')));
                $view->row(Button::to($group['title'] . ' · ' . Fmt::plain(Fmt::number(count($texts))) . ' متن' . ($mine > 0 ? ' · ' . Fmt::plain(Fmt::number($mine)) . ' تغییر یافته' : ''), TextGroupScreen::ID, 'show', $group['key']));
            }
        }

        $view->row($search === '' ? Button::to('🔎 جستجو', self::ID, 'search') : Button::to('✖️ پاک کردن جستجو', self::ID, 'clear'), Button::to('✨ ایموجی‌های پرمیوم', PremiumEmojisScreen::ID));

        return $view->back('hb');
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'f':
                $admin->remember(self::ID, ['f' => ($args[0] ?? '') === 'changed' ? 'changed' : 'all', 'page' => 1]);
                break;
            case 'search':
                return $admin->ask(new Prompt('چه چیزی جستجو شود؟', self::ID, 'search', hint: 'عنوان یا بخشی از متن'));
            case 'clear':
                $admin->remember(self::ID, ['search' => '', 'page' => 1]);
                break;
            case 'page':
                $admin->remember(self::ID, ['page' => self::page($args)]);
                break;
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action === 'search') {
            $admin->remember(self::ID, ['search' => mb_substr($answer->text(), 0, 100), 'page' => 1]);
        }

        return $this->show($admin, []);
    }

    /**
     * Every group of texts the panel lists, in its order, each with its texts.
     *
     * @return list<array{key: string, title: string, texts: list<array<string, mixed>>}>
     * @throws ApiRefusal
     */
    public static function groups(Admin $admin): array
    {
        $groups = [];
        foreach ((array) ($admin->api->get('/bot/texts')['groups'] ?? []) as $group) {
            if (is_array($group) && is_string($group['key'] ?? null)) {
                $groups[] = ['key' => $group['key'], 'title' => (string) ($group['title'] ?? $group['key']), 'texts' => Json::rows($group, 'texts')];
            }
        }

        return $groups;
    }

    /**
     * A text's button: its title, marked once reworded.
     *
     * @param array<string, mixed> $text
     */
    public static function button(array $text): Button
    {
        return Button::to((Json::on($text, 'customized') ? '✏️ ' : '📄 ') . (string) ($text['title'] ?? ''), TextScreen::ID, 'show', (string) ($text['key'] ?? ''));
    }

    /**
     * The search's finds, a page of them.
     *
     * @param list<array<string, mixed>> $texts
     */
    private function found(View $view, Admin $admin, array $texts): void
    {
        [$shown, $page, $pages] = View::slice($texts, (int) ($admin->state(self::ID)['page'] ?? 1));
        foreach ($shown as $text) {
            $view->row(self::button($text));
        }
        $view->pager($page, $pages, self::ID, 'page');
    }

    /**
     * Whether `$text` is found by `$search`: its title, what it is for, its wording or its key.
     *
     * @param array<string, mixed> $text
     */
    private static function matches(array $text, string $search): bool
    {
        $term = self::normalize(trim($search));
        if ($term === '') {
            return true;
        }

        return str_contains(self::normalize(implode("\n", [(string) ($text['title'] ?? ''), (string) ($text['description'] ?? ''), (string) ($text['value'] ?? ''), (string) ($text['key'] ?? '')])), $term);
    }

    /** Persian as people type it: Arabic ی/ک as Persian; case, the half-space and the direction marks ignored. */
    private static function normalize(string $text): string
    {
        return str_replace(['ي', 'ك', "\u{200C}", "\u{200E}", "\u{200F}"], ['ی', 'ک', '', '', ''], mb_strtolower($text));
    }
}
