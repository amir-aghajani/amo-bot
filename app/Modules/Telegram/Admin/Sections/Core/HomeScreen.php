<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Core;

use App\Modules\Auth\Services\SessionPresenter;
use App\Modules\Telegram\Admin\SeatKind;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «⚙️ مدیریت فروشگاه»: the management's home — whose shop, as what, what waits on a human now (the panel's queues),
 * and the sections the seat may open, as the panel's sidebar has them. The owner works any shop from here («🏪»).
 */
final class HomeScreen extends BaseScreen
{
    /** What each queue is called in the line of what waits. */
    private const WAITING = [
        'payments_to_review' => 'رسید',
        'stuck_orders' => 'سفارش گیرکرده',
        'open_tickets' => 'تیکت',
        'pending_reviews' => 'نظر',
    ];

    public function __construct(
        private readonly Screens $screens,
        private readonly SessionPresenter $shops,
    ) {}

    public function show(Admin $admin, array $args): View
    {
        $seat = $admin->seat;
        $shop = $this->shops->shop($seat->shop());
        $role = match ($seat->kind) {
            SeatKind::Owner => '👑 مالک',
            SeatKind::Agent => '🤝 نماینده',
            SeatKind::Staff => '🛡 مدیر ربات',
        };
        $view = View::make('⚙️ مدیریت فروشگاه')
            ->line('🏪 ' . Fmt::e((string) $shop['name']) . ' · ' . $role);

        $entries = Screens::homeEntries();
        $queues = $this->screens->queues($admin, $entries);
        $waiting = [];
        foreach (self::WAITING as $queue => $word) {
            if (($queues[$queue] ?? 0) > 0) {
                $waiting[] = Fmt::number($queues[$queue]) . ' ' . $word;
            }
        }
        $view->line($waiting === [] ? '✅ کاری در انتظار نیست.' : '🔔 در انتظار: ' . implode(' · ', $waiting));
        if ($seat->isStaff()) {
            $view->note('کارهای روزانه فروشگاه؛ آنچه مالک بیشتر سپرده هم این‌جاست.');
        }

        $view->grid(2, ...$this->screens->buttons($seat, $entries, $queues));
        if ($seat->isOwner()) {
            $view->row(Button::to('🏪 فروشگاه: ' . $shop['name'], 'shop'));
        }

        return $view->row(Button::to('🔄 تازه‌سازی', Screens::HOME));
    }
}
