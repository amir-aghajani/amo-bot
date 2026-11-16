<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🙋 زیرمجموعه‌ها» (GET /referrals/invitees): the customers who came through a link, ordered by when they came or what
 * they earned their referrer — either way —, each a row «name · referrer · earned» opening its own screen
 * (InviteeScreen); one referrer's alone, as the referrers list leads here (`ref`).
 *
 * Actions: `ref` (referrer id: theirs alone), `all` (every referrer's again).
 */
final class InviteesScreen extends ListScreen
{
    public const ID = 'rfi';

    /** The panel's sortable headers, its own order first. */
    private const SORTS = ['joined' => 'زمان عضویت', 'earned' => 'پورسانت'];

    /** The ones of them that are moments. */
    private const MOMENTS = ['joined'];

    /** The referrer the list is narrowed to (its state's `referrer`) while it is shown; 0 for none. */
    private int $referrer = 0;

    public function show(Admin $admin, array $args): View
    {
        $this->referrer = (int) ($admin->state(self::ID)['referrer'] ?? 0);

        return parent::show($admin, $args);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return ReferralsScreen::INVITEES;
    }

    protected function path(): array
    {
        return ReferralsScreen::under();
    }

    protected function endpoint(): string
    {
        return '/referrals/invitees';
    }

    protected function key(): string
    {
        return 'invitees';
    }

    protected function filters(): array
    {
        return ['sort' => self::SORTS, Sorting::DIR => Sorting::directions($this->here, self::ID, self::SORTS, self::MOMENTS)];
    }

    protected function fixed(): array
    {
        return $this->referrer > 0 ? ['referrer' => $this->referrer] : [];
    }

    protected function noun(): string
    {
        return 'زیرمجموعه';
    }

    protected function searchHint(): string
    {
        return 'زیرمجموعه یا معرف: نام یا شناسه';
    }

    protected function empty(): string
    {
        return $this->referrer > 0
            ? 'این معرف هنوز کسی را نیاورده است.'
            : 'هنوز کسی با لینک دعوت نیامده است. کسی که اولین بار با لینک دعوت یک نفر وارد ربات شود، این‌جا می‌آید.';
    }

    protected function summary(array $meta): array
    {
        return $this->referrer > 0 ? [ReferralsScreen::REFERRER . ' فقط زیرمجموعه‌های معرف ' . Fmt::id($this->referrer)] : [];
    }

    protected function extra(View $view, Admin $admin): void
    {
        if ($this->referrer > 0) {
            $view->row(Button::to('✖️ همه معرف‌ها', self::ID, 'all'));
        }
        $view->help('مشتری‌هایی که با لینک دعوت آمده‌اند: چه کسی آن‌ها را آورده و پرداخت‌هایشان چقدر پورسانت برای معرف داشته است.');
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return ReferralsScreen::ID;
    }

    protected function item(array $row): Button
    {
        $user = is_array($row['user'] ?? null) ? $row['user'] : null;
        $referrer = is_array($row['referrer'] ?? null) ? $row['referrer'] : null;
        $earned = Words::figure($row['earned'] ?? null);

        return Button::to(
            Fmt::cut(Words::name($user), 16) . ($referrer !== null && $this->referrer === 0 ? ' · معرف: ' . Fmt::cut(Words::name($referrer), 16) : '') . ((float) $earned > 0 ? ' · ' . Fmt::plain(Fmt::money($earned)) : ''),
            InviteeScreen::ID,
            Nav::SHOW,
            (int) ($row['id'] ?? 0),
        );
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'ref' && self::id($args) > 0) {
            $admin->remember(self::ID, ['referrer' => self::id($args), 'search' => '', 'page' => 1]);
        }
        if ($action === 'all') {
            $admin->remember(self::ID, ['referrer' => 0, 'page' => 1]);
        }

        return $this->show($admin, []);
    }
}
