<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Gifts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One mass gift (among the latest the panel lists): what it gives and to whom, the reason, how far it got — each server's
 * part, the panels it waits on, the last refusal —, who gave it and when; while it runs, «🔄» works it a few seconds as
 * the panel's open card does (the scheduler carries on without it) and «🚫 لغو هدیه» stops it, asked first.
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
        $id = self::id($args);
        foreach ((array) ($admin->api->get('/mass-grants')['grants'] ?? []) as $grant) {
            if (is_array($grant) && (int) ($grant['id'] ?? 0) === $id) {
                return $this->view($grant);
            }
        }

        return $admin->open(MassGiftsScreen::ID);
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
                $grant = (array) ($admin->api->get('/mass-grants')['grants'] ?? []);
                $gift = '';
                foreach ($grant as $row) {
                    if (is_array($row) && (int) ($row['id'] ?? 0) === $id) {
                        $gift = MassGiftsScreen::gift($row);
                    }
                }

                return $admin->confirm(
                    'این هدیه همگانی لغو شود؟',
                    Button::to('🚫 بله، لغو شود', self::ID, 'stopped', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    Fmt::e($gift) . ' به بقیه سرویس‌ها اضافه نمی‌شود. سرویس‌هایی که تا این لحظه گرفته‌اند همان را نگه می‌دارند؛ روی همه سرورها لغو می‌شود.',
                );
            case 'stopped':
                $grant = (array) ($admin->api->post("/mass-grants/{$id}/cancel")['grant'] ?? []);
                $admin->notice('🚫 هدیه همگانی لغو شد');

                return $this->view($grant);
        }

        return $this->show($admin, $args);
    }

    /** @param array<string, mixed> $grant */
    private function view(array $grant): View
    {
        $id = (int) ($grant['id'] ?? 0);
        $running = ($grant['status'] ?? null) === 'running';
        $parts = Json::rows($grant, 'parts');
        $view = View::make('🎁 هدیه همگانی ' . Fmt::plain(Fmt::id($id)), 'مدیریت', 'ربات', 'هدیه همگانی')
            ->line('<b>' . Fmt::e(MassGiftsScreen::gift($grant)) . '</b> · ' . Fmt::e(MassGiftsScreen::audience($grant)));
        if (is_string($grant['reason'] ?? null) && $grant['reason'] !== '') {
            $view->line('<blockquote>' . Fmt::e($grant['reason']) . '</blockquote>');
        }
        $view->facts([
            'وضعیت' => (MassGiftsScreen::MARKS[(string) ($grant['status'] ?? '')] ?? '') . ' ' . Fmt::e(self::STATUS[(string) ($grant['status'] ?? '')] ?? ''),
            $running ? 'پیشرفت' : 'نتیجه' => self::tally($grant, $running),
            'سرورها' => $running && $parts !== [] ? 'روی ' . Fmt::number(count($parts)) . ' سرور: ' . implode('، ', array_map(static fn(array $part): string => Fmt::e((string) ($part['server']['name'] ?? '')) . ' ' . Fmt::number(self::reached($part)) . '/' . Fmt::number((int) ($part['total'] ?? 0)), $parts)) : null,
            'داده شده' => Fmt::date(is_string($grant['created_at'] ?? null) ? $grant['created_at'] : null, true) . (is_string($grant['reviewer'] ?? null) ? ' · ' . Fmt::e((string) ($grant['reviewer_name'] ?? $grant['reviewer'])) : ''),
        ]);
        $about = array_filter([Json::on($grant, 'include_unstarted') ? 'با سرویس‌های در انتظار اولین اتصال' : null, ($grant['notify'] ?? true) === false ? 'بدون پیام به مشتری‌ها' : null]);
        if ($about !== []) {
            $view->line(Fmt::e(implode(' · ', $about)));
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
            $view->row(Button::to('🔄 پیشبرد', self::ID, 'run', $id), Button::to('🚫 لغو هدیه', self::ID, 'stop', $id));
        } else {
            $view->row(Button::to('🔄 تازه‌سازی', self::ID, 'show', $id));
        }

        return $view->back(MassGiftsScreen::ID);
    }

    /**
     * How far it got, in the panel's words: running, the services checked of every active one; over, what came of it.
     *
     * @param array<string, mixed> $grant
     */
    private static function tally(array $grant, bool $running): string
    {
        $granted = (int) ($grant['granted'] ?? 0);
        $skipped = (int) ($grant['skipped'] ?? 0);
        $failed = (int) ($grant['failed'] ?? 0);
        $parts = $running
            ? [Fmt::number(self::reached($grant)) . ' از ' . Fmt::number((int) ($grant['total'] ?? 0)) . ' سرویس فعال بررسی شد', 'به ' . Fmt::number($granted) . ' سرویس اضافه شد']
            : [$granted > 0 ? 'به ' . Fmt::number($granted) . ' سرویس اضافه شد' : 'به سرویسی اضافه نشد'];
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
