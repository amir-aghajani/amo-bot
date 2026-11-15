<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

/** The management screens of the customers and their groups: their ids (Telegram\Admin\Ui\Screens) and classes. */
final class CustomerScreens
{
    /**
     * @var array<string, class-string<\App\Modules\Telegram\Admin\Ui\Screen>>
     */
    public const SCREENS = [
        UsersScreen::ID => UsersScreen::class,
        UsersFilterScreen::ID => UsersFilterScreen::class,
        CustomerScreen::ID => CustomerScreen::class,
        CustomerServicesScreen::ID => CustomerServicesScreen::class,
        CustomerOrdersScreen::ID => CustomerOrdersScreen::class,
        CustomerPaymentsScreen::ID => CustomerPaymentsScreen::class,
        CustomerTicketsScreen::ID => CustomerTicketsScreen::class,
        CustomerWalletScreen::ID => CustomerWalletScreen::class,
        CustomerGroupsScreen::ID => CustomerGroupsScreen::class,
        CustomerAccountScreen::ID => CustomerAccountScreen::class,
        CustomerMessageScreen::ID => CustomerMessageScreen::class,
        ManualOrderScreen::ID => ManualOrderScreen::class,
        GroupsScreen::ID => GroupsScreen::class,
        GroupScreen::ID => GroupScreen::class,
    ];
}
