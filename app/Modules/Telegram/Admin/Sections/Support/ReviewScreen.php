<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One review (from the list's kept page, ReviewsScreen — the panel lists reviews, it has no route for one): its stars,
 * signature, text and where it was written, its standing, and what its status allows — «✅ تایید», «🚫 رد» (the panel's
 * `actions`) —, «🗑 حذف» asked first. A decision answers the review as the panel then has it.
 *
 * Actions: `ok` (id), `no` (id), `del` (id: asks), `gone` (id: deletes).
 */
final class ReviewScreen extends BaseScreen
{
    public const ID = 'rvv';

    private const STATUS = ['pending' => '⏳ در انتظار بررسی', 'approved' => '✅ تاییدشده (روی سایت)', 'rejected' => '🚫 ردشده'];

    public function show(Admin $admin, array $args): View
    {
        $row = $this->row($admin, self::id($args));

        return $row === null ? $admin->open(ReviewsScreen::ID) : $this->view($row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'ok':
                $row = (array) ($admin->api->post("/reviews/{$id}/approve")['review'] ?? []);
                $admin->notice('✅ تایید شد و روی سایت دیده می‌شود');

                return $this->view($this->kept($admin, $row));
            case 'no':
                $row = (array) ($admin->api->post("/reviews/{$id}/reject")['review'] ?? []);
                $admin->notice('🚫 رد شد');

                return $this->view($this->kept($admin, $row));
            case 'del':
                return $admin->confirm('این نظر برای همیشه حذف شود؟', Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id), Button::to('✖️ انصراف', self::ID, 'show', $id));
            case 'gone':
                $admin->api->delete("/reviews/{$id}");
                $admin->notice('🗑 حذف شد');

                return $admin->open(ReviewsScreen::ID);
        }

        return $this->show($admin, $args);
    }

    /** @param array<string, mixed> $row */
    private function view(array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : null;
        $view = View::make('⭐️ نظر ' . Fmt::id($id), 'مدیریت', 'نظرات')
            ->line(str_repeat('⭐', max(1, min(5, (int) ($row['rating'] ?? 0)))) . ' · ' . Fmt::e((string) ($row['name'] ?? '')))
            ->line('<blockquote>' . Fmt::e((string) ($row['body'] ?? '')) . '</blockquote>')
            ->facts([
                'کجا' => is_string($row['context'] ?? null) ? Fmt::e($row['context']) : null,
                'نویسنده' => $customer !== null ? Fmt::e((string) ($customer['name'] ?? '')) . ' ' . Fmt::id($customer['id'] ?? null) : 'مهمان',
                'نوشته شده' => Fmt::date(is_string($row['created_at'] ?? null) ? $row['created_at'] : null, true),
                'وضعیت' => Fmt::e(self::STATUS[(string) ($row['status'] ?? '')] ?? (string) ($row['status'] ?? '')),
                'تصمیم' => is_string($row['decided_at'] ?? null) ? Fmt::e((string) ($row['reviewer_name'] ?? $row['reviewer'] ?? '')) . ' · ' . Fmt::date($row['decided_at'], true) : null,
            ]);
        $actions = Json::part($row, 'actions');

        return $view->row(
            ($actions['approve'] ?? false) ? Button::to('✅ تایید', self::ID, 'ok', $id) : null,
            ($actions['reject'] ?? false) ? Button::to('🚫 رد', self::ID, 'no', $id) : null,
            Button::to('🗑 حذف', self::ID, 'del', $id),
        )->back(ReviewsScreen::ID);
    }

    /**
     * The review from the list's kept page; null when it is not there (the list moved on).
     *
     * @return array<string, mixed>|null
     */
    private function row(Admin $admin, int $id): ?array
    {
        $rows = (array) ($admin->state(ReviewsScreen::ID)['rows'] ?? []);
        $row = $rows[$id] ?? $rows[(string) $id] ?? null;

        return is_array($row) ? $row : null;
    }

    /**
     * The review as the panel answered it, kept for the list's page too.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function kept(Admin $admin, array $row): array
    {
        $rows = (array) ($admin->state(ReviewsScreen::ID)['rows'] ?? []);
        $rows[(int) ($row['id'] ?? 0)] = $row;
        $admin->remember(ReviewsScreen::ID, ['rows' => $rows]);

        return $row;
    }
}
