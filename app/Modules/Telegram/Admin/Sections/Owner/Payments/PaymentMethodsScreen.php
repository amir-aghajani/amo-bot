<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Catalog\CatalogFmt;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🏦 روش‌های پرداخت» (the owner's and an agent's, the panel's page): the ways to pay in checkout order — the built-in
 * wallet and the methods added —, each a button that is its row (its chip, name and how it settles or its cards) to its
 * own screen (PaymentMethodScreen); over them only what the buttons cannot say: how many are on, and why a method
 * switched on is offered to nobody now. «➕ افزودن روش پرداخت» (PaymentDriversScreen) and «⚡️ تایید خودکار واریز» with
 * its state (DepositSettingsScreen); how the list works folded away.
 */
final class PaymentMethodsScreen extends BaseScreen
{
    public const ID = 'pm';

    public const TITLE = PaymentWords::TITLE;


    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $methods = Methods::all($admin);
        $on = array_filter($methods, static fn(array $method): bool => Json::on($method, 'enabled'));
        $view = View::make(self::TITLE, ...CatalogFmt::under())
            ->total(count($methods), 'روش', Fmt::number(count($on)) . ' فعال');
        $buttons = [];
        foreach ($methods as $method) {
            $why = PaymentWords::unoffered($method);
            if ($why !== null) {
                $view->line('⚠️ ' . Fmt::e((string) ($method['label'] ?? '')) . ': ' . Fmt::e($why));
            }
            $buttons[] = Button::to(self::label($method, $why !== null), PaymentMethodScreen::ID, Nav::SHOW, (int) ($method['id'] ?? 0));
        }

        return $view->grid(1, ...$buttons)
            ->row(Button::to(Words::add('روش پرداخت'), PaymentDriversScreen::ID)->success())
            ->row(Button::to(DepositSettingsScreen::TITLE . self::deposits($admin), DepositSettingsScreen::ID))
            ->help('ترتیب همین لیست، ترتیب دکمه‌ها در صفحه پرداخت است؛ با 🔼 و 🔽 در صفحه هر روش جابه‌جا می‌شود. هر روش فقط برای مبلغ‌های میان کمترین و بیشترین مبلغش پیشنهاد می‌شود، و یک روش کارت به کارت چند کارت دارد. کیف پول همیشه هست و حذف نمی‌شود.')
            ->back(CatalogFmt::HUB)
            ->refresh(self::ID);
    }

    /**
     * A method's row as its button: its chip (⚠️ for one on that nobody is offered), name, and how it settles — a card
     * method its cards, a gateway its driver.
     *
     * @param array<string, mixed> $method
     */
    private static function label(array $method, bool $unoffered): string
    {
        $how = match (true) {
            ($method['driver'] ?? null) === 'manual' => Fmt::number(count(Json::rows($method, 'cards'))) . ' کارت',
            ($method['kind'] ?? null) === 'online' => (string) ($method['driver_label'] ?? ''),
            default => PaymentWords::kind($method),
        };

        return CatalogFmt::dot(Json::on($method, 'enabled'), $unoffered) . ' ' . Fmt::cut((string) ($method['label'] ?? ''), 24) . ' · ' . Fmt::cut(Fmt::plain($how), 16);
    }

    /** «⚡️ تایید خودکار واریز»'s state beside its button: « · 🟢 روشن» / « · ⏸ خاموش»; nothing when the panel would not say. */
    private static function deposits(Admin $admin): string
    {
        try {
            return ' · ' . Fmt::onOff(Json::on($admin->api->get('/deposits/settings'), 'enabled'));
        } catch (ApiRefusal) {
            return '';
        }
    }
}
