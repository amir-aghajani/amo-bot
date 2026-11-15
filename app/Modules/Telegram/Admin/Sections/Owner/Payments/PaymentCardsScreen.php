<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A card method's cards (the panel's CardsEditor): each its masked number, holder and bank, switched on or off, and
 * today's take against its daily cap — a button each to its own screen (PaymentCardScreen) —, and «➕ افزودن کارت» while
 * the method has fewer than the most it keeps.
 */
final class PaymentCardsScreen extends BaseScreen
{
    public const ID = 'pmc';

    /** The most cards a method keeps (PAYMENT_CARDS.MOST). */
    public const MOST = 20;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        if (self::id($args) > 0) {
            $admin->remember(self::ID, ['method' => self::id($args)]);
        }
        $method = Methods::find($admin, (int) ($admin->state(self::ID)['method'] ?? 0));
        if ($method === null || ($method['driver'] ?? null) !== 'manual') {
            return $admin->open(PaymentMethodsScreen::ID);
        }
        $cards = Json::rows($method, 'cards');
        $view = View::make('💳 کارت‌ها', 'مدیریت', 'فروشگاه', 'روش‌های پرداخت', (string) ($method['label'] ?? ''))
            ->line('به هر پرداخت یکی از کارت‌های فعال به تصادف نشان داده می‌شود، از میان کارت‌هایی که سقف روزانه‌شان جای آن مبلغ را دارد؛ همان پرداخت همیشه همان کارت را نشان می‌دهد.')
            ->line()
            ->lines(...PaymentWords::cards($cards, Json::on($method, 'to_support')));
        $buttons = [];
        foreach ($cards as $n => $card) {
            $buttons[] = Button::to((Json::on($card, 'enabled') ? '🟢 ' : '⏸ ') . 'کارت ' . Fmt::number($n + 1) . ' · ' . substr((string) ($card['number'] ?? ''), -4) . ' · ' . (string) ($card['holder'] ?? ''), PaymentCardScreen::ID, 'show', (int) ($card['id'] ?? 0));
        }
        $view->grid(1, ...$buttons);
        if (count($cards) < self::MOST) {
            $view->row(Button::to('➕ افزودن کارت', PaymentCardFormScreen::ID, 'new', (int) $method['id']));
        } else {
            $view->note('بیشتر از ' . Fmt::number(self::MOST) . ' کارت نمی‌شود.');
        }

        return $view->back(PaymentMethodScreen::ID, 'show', (int) $method['id']);
    }
}
