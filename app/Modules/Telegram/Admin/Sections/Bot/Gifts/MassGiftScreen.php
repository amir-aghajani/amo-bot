<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Gifts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One mass gift (among the latest the panel lists): what it gives and to whom, the reason, how far it got — each server's
 * part, the panels it waits on, the last refusal —, who gave it and when; while it runs, «⏩ پیشبرد» works it a few
 * seconds as the panel's open card does (the scheduler carries on without it) and «🚫 لغو هدیه» stops it, asked first.
 *
 * Actions: `run` (id), `stop` (id: asks), `stopped` (id).
 */
final class MassGiftScreen extends BaseScreen
{
    public const ID = 'mgv';

    private const STATUS = ['running' => 'در حال انجام', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده'];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $grant = self::find($admin, self::id($args));

        return $grant === null ? $admin->open(MassGiftsScreen::ID) : $this->view($grant);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'run':
                $grant = (array) ($admin->api->post("/mass-grants/{$id}/run")['grant'] ?? []);
                if (($grant['status'] ?? null) === 'done') {
                    $granted = (int) ($grant['granted'] ?? 0);
                    $admin->notice($granted > 0 ? '✅ ' . MassGiftsScreen::gift($grant) . ' به ' . Fmt::plain(Fmt::number($granted)) . ' سرویس اضافه شد' : '✅ هدیه همگانی تمام شد؛ سرویسی شامل نشد');
                }

                return $this->view($grant);
            case 'stop':
                $grant = self::find($admin, $id);
                $gift = $grant === null ? '' : MassGiftsScreen::gift($grant);

                return $admin->confirm(
                    'این هدیه همگانی لغو شود؟',
                    Button::to(Words::yes('لغو شود', '🚫'), self::ID, 'stopped', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                    Fmt::e($gift) . ' به بقیه سرویس‌ها اضافه نمی‌شود. سرویس‌هایی که تا این لحظه گرفته‌اند همان را نگه می‌دارند؛ روی همه سرورها لغو می‌شود.',
                );
            case 'stopped':
                $grant = (array) ($admin->api->post("/mass-grants/{$id}/cancel")['grant'] ?? []);
                $admin->notice('🚫 هدیه همگانی لغو شد');

                return $this->view($grant);
        }

        return $this->show($admin, $args);
    }

    /**
     * The gift of `$id` among the latest the panel lists (it has no route for one); null when it is not there.
     *
     * @return array<string, mixed>|null
     */
    private static function find(Admin $admin, int $id): ?array
    {
        foreach ((array) ($admin->api->get('/mass-grants')['grants'] ?? []) as $grant) {
            if (is_array($grant) && (int) ($grant['id'] ?? 0) === $id) {
                return $grant;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $grant */
    private function view(array $grant): View
    {
        $id = (int) ($grant['id'] ?? 0);
        $running = ($grant['status'] ?? null) === 'running';
        $parts = Json::rows($grant, 'parts');
        $total = (int) ($grant['total'] ?? 0);
        $view = View::make(Icons::MASS_GIFT . ' هدیه همگانی ' . Fmt::plain(Fmt::id($id)), ...[...BotScreens::PATH, Fmt::bare(MassGiftsScreen::TITLE)])
            ->card([
                'وضعیت' => (MassGiftsScreen::MARKS[(string) ($grant['status'] ?? '')] ?? '') . ' ' . Fmt::e(self::STATUS[(string) ($grant['status'] ?? '')] ?? ''),
                'هدیه' => Fmt::e(MassGiftsScreen::gift($grant)),
                'به' => Fmt::e(MassGiftsScreen::audience($grant)),
                'پیشرفت' => $running ? self::bar(self::reached($grant), $total) . ' ' . Fmt::number(self::reached($grant)) . ' از ' . Fmt::number($total) : null,
                $running ? 'تا این‌جا' : 'نتیجه' => self::tally($grant, $running),
                'سرورها' => $running && $parts !== [] ? implode('، ', array_map(static fn(array $part): string => Fmt::e((string) ($part['server']['name'] ?? '')) . ' ' . Fmt::number(self::reached($part)) . '/' . Fmt::number((int) ($part['total'] ?? 0)), $parts)) : null,
                'سرویس‌های در انتظار اولین اتصال' => Json::on($grant, 'include_unstarted') ? 'شامل می‌شوند' : null,
                'پیام به مشتری‌ها' => ($grant['notify'] ?? true) === false ? Words::OFF : null,
                'داده شده' => Fmt::date(is_string($grant['created_at'] ?? null) ? $grant['created_at'] : null, true) . (is_string($grant['reviewer'] ?? null) ? ' · ' . Fmt::e((string) ($grant['reviewer_name'] ?? $grant['reviewer'])) : ''),
            ]);
        if (is_string($grant['reason'] ?? null) && $grant['reason'] !== '') {
            $view->section('✍️ دلیل')->line('<blockquote>' . Fmt::e($grant['reason']) . '</blockquote>');
        }
        $waiting = array_filter($parts, static fn(array $part): bool => ($part['status'] ?? null) === 'running' && is_string($part['waiting_reason'] ?? null));
        if ($running && $waiting !== []) {
            $view->line()->line('⚠️ کار روی این سرورها تا رفع مشکل پنلشان منتظر می‌ماند و خودکار دوباره تلاش می‌شود؛ بقیه سرورها جلو می‌روند.');
            foreach ($waiting as $part) {
                $view->line('• ' . Fmt::e((string) ($part['server']['name'] ?? '')) . ': ' . Fmt::e((string) $part['waiting_reason']));
            }
        }
        foreach ($parts as $part) {
            if (is_string($part['last_failure'] ?? null)) {
                $view->line()->line('⛔️ آخرین خطا: ' . Fmt::e($part['last_failure']));
                break;
            }
        }
        if ($running) {
            $view->row(Button::to('⏩ پیشبرد', self::ID, 'run', $id)->primary(), Button::to('🚫 لغو هدیه', self::ID, 'stop', $id)->danger())
                ->note('«⏩ پیشبرد» چند ثانیه هدیه را جلو می‌برد و جایش را نشان می‌دهد؛ بی آن هم کارهای زمان‌بندی‌شده ادامه‌اش می‌دهند.');
        }

        return $view->back(MassGiftsScreen::ID)->refresh(self::ID, 'show', $id);
    }

    /** A bar of ten for how far it got: «▰▰▰▱▱▱▱▱▱▱». */
    private static function bar(int $done, int $total): string
    {
        $filled = $total > 0 ? min(10, (int) round(10 * $done / $total)) : 0;

        return str_repeat('▰', $filled) . str_repeat('▱', 10 - $filled);
    }

    /**
     * What came of it, in the panel's words — so far, while it runs (how many were checked is its «پیشرفت»).
     *
     * @param array<string, mixed> $grant
     */
    private static function tally(array $grant, bool $running): string
    {
        $granted = (int) ($grant['granted'] ?? 0);
        $skipped = (int) ($grant['skipped'] ?? 0);
        $failed = (int) ($grant['failed'] ?? 0);
        $parts = [$running || $granted > 0 ? 'به ' . Fmt::number($granted) . ' سرویس اضافه شد' : 'به سرویسی اضافه نشد'];
        if ($skipped > 0) {
            $parts[] = Fmt::number($skipped) . ' سرویس شامل نشد';
        }
        if ($failed > 0) {
            $parts[] = Fmt::number($failed) . ' ناموفق';
        }

        return implode(' · ', $parts);
    }

    /**
     * The services a gift or a part has been through so far.
     *
     * @param array<string, mixed> $row
     */
    private static function reached(array $row): int
    {
        return min((int) ($row['granted'] ?? 0) + (int) ($row['skipped'] ?? 0) + (int) ($row['failed'] ?? 0), (int) ($row['total'] ?? 0));
    }
}
