<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Broadcasts;

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
 * One broadcast run (from the list's kept page, BroadcastsScreen — the panel lists runs, it has no route for one): what
 * went and how — a copy or a forward, pinned, with buttons —, to whom, how far it got and what became of them, its state,
 * who sent it and when; and what its state allows (the panel's `actions`): «⏸ توقف», «▶️ ادامه», «📌 لغو پین» and «🚫 لغو
 * ارسال», the last two asked first with the panel's words.
 *
 * Actions: `pause`, `resume`, `cancel` (asks), `cancelled`, `unpin` (asks), `unpinned`, `r` (read again) — each with the id.
 */
final class BroadcastScreen extends BaseScreen
{
    public const ID = 'brv';

    /** What a message is, in the panel's words. */
    private const CONTENT = [
        'text' => '📝 متن', 'photo' => '🖼 عکس', 'video' => '🎬 ویدیو', 'animation' => '🎞 گیف', 'document' => '📎 فایل',
        'audio' => '🎵 آهنگ', 'voice' => '🎤 پیام صوتی', 'video_note' => '⏺ ویدیو مسیج', 'sticker' => '🙂 استیکر', 'poll' => '📊 نظرسنجی',
    ];

    private const STATUS = ['sending' => 'در حال ارسال', 'paused' => 'متوقف‌شده', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده'];

    public function show(Admin $admin, array $args): View
    {
        $row = $this->row($admin, self::id($args));

        return $row === null ? $admin->open(BroadcastsScreen::ID) : $this->view($row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'pause':
            case 'resume':
            case 'cancelled':
                $verb = $action === 'cancelled' ? 'cancel' : $action;
                $row = (array) ($admin->api->post("/broadcasts/{$id}/{$verb}")['broadcast'] ?? []);
                $admin->notice(['pause' => '⏸ ارسال متوقف شد', 'resume' => '▶️ ارسال ادامه پیدا کرد', 'cancel' => '🚫 ارسال لغو شد'][$verb]);

                return $this->view($this->kept($admin, $row));
            case 'cancel':
                return $admin->confirm(
                    'ارسال ' . Fmt::plain(Fmt::id($id)) . ' لغو شود؟',
                    Button::to(Words::yes('لغو شود', '🚫'), self::ID, 'cancelled', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                    'بقیه گیرنده‌ها پیام را نمی‌گیرند؛ کسانی که گرفته‌اند همان را دارند. این کار برگشت ندارد.',
                );
            case 'unpin':
                $row = $this->row($admin, $id);

                return $admin->confirm(
                    'پین ارسال ' . Fmt::plain(Fmt::id($id)) . ' برداشته شود؟',
                    Button::to(Words::yes('برداشته شود', '📌'), self::ID, 'unpinned', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                    'پیام این ارسال از حالت پین در ' . Fmt::number((int) ($row['pinned'] ?? 0)) . ' گفتگو درمی‌آید؛ خود پیام‌ها می‌مانند.',
                    danger: true,
                );
            case 'unpinned':
                $run = (array) ($admin->api->post("/broadcasts/{$id}/unpin")['broadcast'] ?? []);
                $admin->notice('📌 لغو پین شروع شد؛ ربات پیام‌ها را یکی‌یکی از حالت پین درمی‌آورد');
                $admin->open(BroadcastsScreen::ID, 'page', [1]);

                return $this->view($this->kept($admin, $run));
            case 'r':
                $admin->open(BroadcastsScreen::ID);

                return $this->show($admin, $args);
        }

        return $this->show($admin, $args);
    }

    /**
     * What a run sent, in a few words: its kind and the start of its words — an unpin, whose pins it takes off.
     *
     * @param array<string, mixed> $row
     */
    public static function what(array $row, int $most): string
    {
        if (($row['kind'] ?? null) === 'unpin') {
            return '📌 لغو پین ارسال ' . Fmt::plain(Fmt::id(is_int($row['source_id'] ?? null) ? $row['source_id'] : null));
        }
        $content = self::CONTENT[(string) ($row['content'] ?? '')] ?? '💬 پیام';
        $excerpt = trim((string) ($row['excerpt'] ?? ''));

        return $content . ($excerpt === '' ? '' : ' «' . Fmt::cut($excerpt, $most) . '»');
    }

    /** @param array<string, mixed> $row */
    private function view(array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $unpin = ($row['kind'] ?? null) === 'unpin';
        $total = (int) ($row['total'] ?? 0);
        $sent = (int) ($row['sent'] ?? 0);
        $blocked = (int) ($row['blocked'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);
        $reached = min($sent + $blocked + $failed, $total);
        $parts = $unpin
            ? array_filter([Fmt::number($sent) . ' برداشته شد', $failed > 0 ? Fmt::number($failed) . ' نشد' : null])
            : array_filter([Fmt::number($sent) . ' رسید', $blocked > 0 ? Fmt::number($blocked) . ' مسدود' : null, $failed > 0 ? Fmt::number($failed) . ' نرسید' : null]);
        $buttons = array_sum(array_map(static fn(mixed $line): int => is_array($line) ? count($line) : 0, (array) ($row['buttons'] ?? [])));
        $admin = is_array($row['admin'] ?? null) ? $row['admin'] : null;
        $by = match (true) {
            $admin !== null => Fmt::e((string) ($admin['name'] ?? '')) . (is_string($admin['username'] ?? null) ? ' ' . Fmt::ltr('@' . $admin['username']) : ''),
            is_string($row['reviewer'] ?? null) => Fmt::e((string) ($row['reviewer_name'] ?? $row['reviewer'])),
            default => null,
        };
        $audience = is_array($row['audience'] ?? null) ? $row['audience'] : null;
        $mode = $row['mode'] ?? null;

        $view = View::make(Icons::BROADCAST . ' ارسال ' . Fmt::plain(Fmt::id($id)), ...[...BotScreens::PATH, Fmt::bare(BroadcastsScreen::TITLE)])
            ->card([
                'وضعیت' => (BroadcastsScreen::MARKS[(string) ($row['status'] ?? '')] ?? '') . ' ' . Fmt::e(self::STATUS[(string) ($row['status'] ?? '')] ?? (string) ($row['status'] ?? '')),
                'پیام' => Fmt::e(self::what($row, 120)),
                'شیوه' => $mode === null ? null : ($mode === 'forward' ? 'فوروارد' : 'کپی') . (Json::on($row, 'pin') ? ' · 📌 پین' : '') . ($buttons > 0 ? ' · ' . Fmt::number($buttons) . ' دکمه' : ''),
                'مخاطب' => $unpin ? 'همان گیرنده‌ها' : ($audience !== null ? Fmt::e((string) ($audience['label'] ?? '')) : Fmt::NONE),
                'پیشرفت' => self::bar($reached, $total) . ' ' . Fmt::number($reached) . ' از ' . Fmt::number($total),
                'نتیجه' => implode(' · ', $parts),
                'فرستنده' => $by,
                'شروع' => Fmt::date(is_string($row['created_at'] ?? null) ? $row['created_at'] : null, true),
                'پایان' => is_string($row['finished_at'] ?? null) ? Fmt::date($row['finished_at'], true) : null,
            ]);
        $actions = Json::part($row, 'actions');
        $view->row(
            Json::on($actions, 'resume') ? Button::to('▶️ ادامه', self::ID, 'resume', $id)->success() : null,
            Json::on($actions, 'pause') ? Button::to('⏸ توقف', self::ID, 'pause', $id) : null,
            Json::on($actions, 'unpin') ? Button::to('📌 لغو پین', self::ID, 'unpin', $id) : null,
        );
        if (Json::on($actions, 'cancel')) {
            $view->row(Button::to('🚫 لغو ارسال', self::ID, 'cancel', $id)->danger());
        }

        return $view->back(BroadcastsScreen::ID)->refresh(self::ID, 'r', $id);
    }

    /** A bar of ten for how far it got: "▰▰▰▱▱▱▱▱▱▱". */
    private static function bar(int $done, int $total): string
    {
        $filled = $total > 0 ? (int) round(10 * $done / $total) : 0;

        return str_repeat('▰', $filled) . str_repeat('▱', 10 - $filled);
    }

    /**
     * The run from the list's kept page; null when it is not there (the list moved on).
     *
     * @return array<string, mixed>|null
     */
    private function row(Admin $admin, int $id): ?array
    {
        $rows = (array) ($admin->state(BroadcastsScreen::ID)['rows'] ?? []);
        $row = $rows[$id] ?? $rows[(string) $id] ?? null;

        return is_array($row) ? $row : null;
    }

    /**
     * The run as the panel answered it, kept for the list's page too.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function kept(Admin $admin, array $row): array
    {
        $rows = (array) ($admin->state(BroadcastsScreen::ID)['rows'] ?? []);
        $rows[(int) ($row['id'] ?? 0)] = $row;
        $admin->remember(BroadcastsScreen::ID, ['rows' => $rows]);

        return $row;
    }
}
