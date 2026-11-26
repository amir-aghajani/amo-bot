<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Sections\Customers\Customer;
use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * One review (from the list's kept page, ReviewsScreen — the panel lists reviews, it has no route for one): its stars,
 * signature, writer, where and when, its standing in one card, its words under it; what its status allows at a tap —
 * «✅ تایید» green, «🚫 رد» red (the panel's `actions`) —, the next one waiting on the page a tap away (the moderation
 * queue, one review after another), the writer's page, and «🗑 حذف» asked first. A decision answers the review as the
 * panel then has it. A review the seat wrote itself (Customer::own()) offers none of the three: another admin decides it.
 *
 * Actions: `ok` (id), `no` (id), `del` (id: asks), `gone` (id: deletes).
 */
final class ReviewScreen extends KeptRowScreen
{
    public const ID = 'rvv';

    private const STATUS = ['pending' => '🟡 در انتظار بررسی', 'approved' => '✅ تاییدشده، روی سایت', 'rejected' => UiWords::REJECTED];

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'ok':
                $row = (array) ($admin->api->post("/reviews/{$id}/approve")['review'] ?? []);
                $admin->notice('✅ تایید شد و روی سایت دیده می‌شود');

                return $this->view($admin, $this->keep($admin, $row));
            case 'no':
                $row = (array) ($admin->api->post("/reviews/{$id}/reject")['review'] ?? []);
                $admin->notice('🚫 رد شد');

                return $this->view($admin, $this->keep($admin, $row));
            case 'del':
                return $admin->confirm(
                    'این نظر برای همیشه حذف شود؟',
                    Button::to(UiWords::yes('حذف شود'), self::ID, 'gone', $id),
                    Button::to(UiWords::CANCEL, self::ID, Nav::SHOW, $id),
                    'نظر ' . Fmt::id($id) . ' از فهرست و، اگر تاییدشده باشد، از سایت برداشته می‌شود و برنمی‌گردد.',
                    danger: true,
                );
            case 'gone':
                $admin->api->delete("/reviews/{$id}");
                $admin->notice('🗑 حذف شد');

                return $admin->open(ReviewsScreen::ID);
        }

        return $this->show($admin, $args);
    }

    protected function list(): string
    {
        return ReviewsScreen::ID;
    }

    protected function view(Admin $admin, array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : null;
        $status = (string) ($row['status'] ?? '');
        $reviewer = Json::text($row, 'reviewer');
        $decided = is_string($row['decided_at'] ?? null) ? $row['decided_at'] : null;
        $view = View::make(Icons::REVIEWS . ' نظر ' . Fmt::id($id), UiWords::ROOT, Fmt::bare(ReviewsScreen::TITLE))
            ->card([
                'امتیاز' => Words::stars($row['rating'] ?? null),
                'امضا' => Fmt::e((string) ($row['name'] ?? '')),
                'نویسنده' => $customer !== null ? Words::person($customer) . ' · ' . Fmt::id(is_int($customer['id'] ?? null) ? $customer['id'] : null) : 'مهمان',
                'کجا' => is_string($row['context'] ?? null) && $row['context'] !== '' ? Fmt::e($row['context']) : null,
                'نوشته شده' => Fmt::date(is_string($row['created_at'] ?? null) ? $row['created_at'] : null, true),
                'وضعیت' => Fmt::e(self::STATUS[$status] ?? $status),
                'تصمیم' => $decided !== null ? Fmt::date($decided, true) . ($reviewer !== null ? ' · ' . Sales::reviewer($reviewer, Json::text($row, 'reviewer_name')) : '') : null,
            ])
            ->section('💬 متن نظر')
            ->line('<blockquote>' . Fmt::e((string) ($row['body'] ?? '')) . '</blockquote>');
        // One of the shop's admins decides nothing about a review they wrote (Reviews: ActorRefusedException::ownReview()).
        $own = $customer !== null && Customer::own($admin, is_int($customer['id'] ?? null) ? $customer['id'] : 0);
        $actions = $own ? [] : Json::part($row, 'actions');
        $next = $this->next($admin, $id);
        if ($own) {
            $view->note('این نظر خود شماست؛ تایید، رد یا حذفش با مدیر دیگری است.');
        }

        return $view
            ->row(
                ($actions['approve'] ?? false) === true ? Button::to('✅ تایید', self::ID, 'ok', $id)->success() : null,
                ($actions['reject'] ?? false) === true ? Button::to('🚫 رد', self::ID, 'no', $id)->danger() : null,
            )
            ->row($next !== null ? Button::to('⏭ نظر بعدی در انتظار', self::ID, Nav::SHOW, $next) : null)
            ->row(Links::customer($admin, $customer, 'صفحه نویسنده'))
            ->row($own ? null : Button::to(UiWords::DELETE, self::ID, 'del', $id)->danger())
            ->back(ReviewsScreen::ID);
    }

    /**
     * The review waiting after `$id` on the list's kept page — from the page's top again when none follows it —; null
     * when no other waits there.
     */
    private function next(Admin $admin, int $id): ?int
    {
        $ids = [];
        $waiting = [];
        foreach ((array) ($admin->state(ReviewsScreen::ID)['rows'] ?? []) as $row) {
            $other = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
            if ($other > 0) {
                $ids[] = $other;
                $waiting[$other] = ($row['status'] ?? null) === 'pending';
            }
        }
        $at = array_search($id, $ids, true);
        foreach ($at === false ? $ids : [...array_slice($ids, $at + 1), ...array_slice($ids, 0, $at)] as $other) {
            if ($other !== $id && $waiting[$other]) {
                return $other;
            }
        }

        return null;
    }

    /**
     * The review as the panel answered it, kept for the list's page too.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function keep(Admin $admin, array $row): array
    {
        $rows = (array) ($admin->state(ReviewsScreen::ID)['rows'] ?? []);
        $rows[(int) ($row['id'] ?? 0)] = $row;
        $admin->remember(ReviewsScreen::ID, ['rows' => $rows]);

        return $row;
    }
}
