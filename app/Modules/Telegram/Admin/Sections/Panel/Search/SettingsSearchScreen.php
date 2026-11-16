<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Search;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🔎 جستجوی تنظیمات» (the owner's and an agent's, first on «⚙️ تنظیمات» and the home search's other mode): a word or
 * two typed, every setting of the panel's they find (SettingsIndex) — the seat's own, best first, at most twenty, a page
 * of ten at a time —, each a button straight to it: a typed value asked at once, a choice offered, a switch shown on its
 * card (or the part of it), a list or a card opened. What the panel alone sets is found too, with why, its button the
 * screen that says so.
 *
 * Actions: `q` (the question's answer: a search), `r` (page: the last search's results again).
 */
final class SettingsSearchScreen extends BaseScreen
{
    public const ID = 'ss';

    /** The menu it is on. */
    private const MENU = 'hc';

    /** Results a page. */
    private const PER_PAGE = 10;

    /** The most of a search kept. */
    private const LONGEST = 100;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $admin->forget(self::ID);

        return $admin->ask((new Prompt(
            'جستجوی تنظیمات',
            self::ID,
            'q',
            hint: 'یک یا چند کلمه از نام تنظیم، فارسی یا انگلیسی.',
            cancel: Button::to(Words::CANCEL, self::MENU),
        ))->example('حداقل شارژ'));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $term = $admin->state(self::ID)['q'] ?? '';

        return is_string($term) && $term !== '' ? $this->results($admin, $term, self::page($args)) : $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $term = Fmt::cut($answer->text(), self::LONGEST);
        if (SettingsIndex::normalize($term) === '') {
            return $this->show($admin, []);
        }
        $admin->remember(self::ID, ['q' => $term]);

        return $this->results($admin, $term, 1);
    }

    /**
     * Page `$page` of what `$term` finds: how many, a button each — its title and where it is —, why the panel alone sets
     * one of them, and the way to search again.
     */
    private function results(Admin $admin, string $term, int $page): View
    {
        $found = SettingsIndex::find($term, static fn(SettingsEntry $entry): bool => $admin->opens($entry->screen) && $entry->shownTo($admin->seat));
        [$shown, $page, $pages] = View::slice($found, $page, self::PER_PAGE);
        $view = View::make(Icons::SEARCH . ' جستجوی تنظیمات', Words::ROOT, Fmt::bare(Words::SETTINGS))
            ->total(count($found), 'تنظیم', '«' . $term . '»');
        if ($found === []) {
            $view->line()->line(Words::NOTHING_FOUND . ' کلمه دیگری بنویسید، یا بخشی از آن را.');
        }
        // Why the panel alone sets them, each reason once with the settings it is for (the login's fields share one).
        $only = [];
        foreach ($shown as $entry) {
            if ($entry->only !== '') {
                $only[$entry->only][$entry->title] = '«' . $entry->title . '»';
            }
        }
        if ($only !== []) {
            $view->section('🧰 فقط در پنل');
            foreach ($only as $why => $titles) {
                $view->line('• <b>' . Fmt::e(implode('، ', $titles)) . '</b>: ' . Fmt::e($why));
            }
        }
        $view->grid(1, ...array_map(self::button(...), $shown))
            ->pager($page, $pages, self::ID, 'r')
            ->row(Button::to(Icons::SEARCH . ' جستجوی دیگر', self::ID)->primary());

        return $view->help('هر کلمه‌ای که می‌نویسید در نام تنظیم، توضیحش یا جایش پیدا می‌شود؛ نتیجه‌ها از بهترین. دکمه هر نتیجه همان تنظیم را باز می‌کند: عدد یا متن را همان‌جا می‌پرسد، و کلید را در کارت خودش نشان می‌دهد تا با یک ضربه روشن یا خاموشش کنید.')
            ->back(self::MENU);
    }

    /** A result's button: its title and the place it is in (a section, the menu it is under), «🧰» for the panel's alone. */
    private static function button(SettingsEntry $entry): Button
    {
        $place = $entry->trail === [] ? '' : $entry->trail[count($entry->trail) - 1];
        $mark = match (true) {
            $entry->only !== '' => '🧰 ',
            $entry->isSection() => '🗂 ',
            default => '',
        };

        return $entry->button($mark . $entry->title . ($place === '' || $place === $entry->title ? '' : ' · ' . $place));
    }
}
