<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

/**
 * The management screens of orders, payments, bank-SMS deposits and services — and an agent's own account, the traffic
 * their bot sells —: their ids (Telegram\Admin\Ui\Screens) and classes.
 */
final class SalesScreens
{
    /**
     * @var array<string, class-string<\App\Modules\Telegram\Admin\Ui\Screen>>
     */
    public const SCREENS = [
        OrdersScreen::ID => OrdersScreen::class,
        OrderScreen::ID => OrderScreen::class,
        OrderFilters::ID => OrderFilters::class,
        PaymentsScreen::ID => PaymentsScreen::class,
        PaymentScreen::ID => PaymentScreen::class,
        PaymentFilters::ID => PaymentFilters::class,
        ReceiptsScreen::ID => ReceiptsScreen::class,
        DepositsScreen::ID => DepositsScreen::class,
        DepositScreen::ID => DepositScreen::class,
        SubscriptionsScreen::ID => SubscriptionsScreen::class,
        SubscriptionScreen::ID => SubscriptionScreen::class,
        SubscriptionFilters::ID => SubscriptionFilters::class,
        ExtendScreen::ID => ExtendScreen::class,
        MoveScreen::ID => MoveScreen::class,
        AgentAccountScreen::ID => AgentAccountScreen::class,
    ];
}
