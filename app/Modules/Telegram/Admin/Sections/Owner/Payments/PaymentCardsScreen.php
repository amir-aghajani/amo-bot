<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * A card method's cards (the panel's CardsEditor): how many and how many on, why none is offered now, and each card a
 * button that is its row — its chip (🟢 on, ⏸ off, ⚠️ its daily cap taken), the number's last digits, its holder and
 * how much of its cap today took — to its own screen (PaymentCardScreen); «➕ افزودن کارت» while the method has fewer
 * than the most it keeps; how a card is picked folded away.
 */
final class PaymentCardsScreen extends BaseScreen
{
    public const ID = 'pmc';

    public const TITLE = '💳 کارت‌ها';

    /** The most cards a method keeps (PAYMENT_CARDS.MOST). */
    public const MOST = 20;

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
        $toSupport = Json::on($method, 'to_support');
        $on = array_filter($cards, static fn(array $card): bool => Json::on($card, 'enabled'));
        $view = View::make(self::TITLE, ...PaymentWords::under((string) ($method['label'] ?? '')))
            ->total(count($cards), 'کارت', Fmt::number(count($on)) . ' فعال');
        $why = PaymentWords::unoffered($method);
        if ($why !== null) {
            $view->line('⚠️ ' . Fmt::e($why));
        } elseif ($toSupport) {
            $view->line('ℹ️ پرداخت کارت به کارت به پیوی پشتیبانی می‌رود؛ کارت‌ها به مشتری نشان داده نمی‌شوند.');
        }
        foreach ($cards as $card) {
            $view->row(Button::to(self::label($card, $toSupport), PaymentCardScreen::ID, Nav::SHOW, (int) ($card['id'] ?? 0)));
        }
        if (count($cards) < self::MOST) {
            $view->row(Button::to(Words::add('کارت'), PaymentCardFormScreen::ID, 'new', (int) $method['id'])->success());
        } else {
            $view->note('بیشتر از ' . Fmt::number(self::MOST) . ' کارت نمی‌شود.');
        }

        return $view->help('به هر پرداخت یکی از کارت‌های فعال به تصادف نشان داده می‌شود، از میان کارت‌هایی که سقف روزانه‌شان جای آن مبلغ را دارد؛ همان پرداخت همیشه همان کارت را نشان می‌دهد. کارتی که سقف روزانه‌اش پر شده تا فردا نشان داده نمی‌شود.')
            ->back(PaymentMethodScreen::ID, Nav::SHOW, (int) $method['id'])
            // The method named: a window kept from before refreshes its own method's cards, whichever the chat opened since.
            ->refresh(self::ID, Nav::SHOW, (int) $method['id']);
    }

    /**
     * A card's row as its button: its chip, the number's last four digits, its holder, and how much of its cap today took.
     *
     * @param array<string, mixed> $card
     */
    private static function label(array $card, bool $toSupport): string
    {
        $cap = is_scalar($card['daily_cap'] ?? null) ? (float) $card['daily_cap'] : 0.0;
        $take = !$toSupport && Json::on($card, 'enabled') && $cap > 0
            ? ' · امروز ' . Fmt::percent(min(100, (int) round((float) ($card['today'] ?? 0) * 100 / $cap))) . ' سقف'
            : '';

        return PaymentWords::dot($card, $toSupport) . " \u{2068}" . substr((string) ($card['number'] ?? ''), -4) . "\u{2069} · " . Fmt::cut((string) ($card['holder'] ?? ''), 18) . $take;
    }
}
