<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

/** The management screens of plans, categories, test accounts and custom services: their ids (Telegram\Admin\Ui\Screens) and classes. */
final class CatalogScreens
{
    /**
     * @var array<string, class-string<\App\Modules\Telegram\Admin\Ui\Screen>>
     */
    public const SCREENS = [
        PlansScreen::ID => PlansScreen::class,
        PlanScreen::ID => PlanScreen::class,
        PlanEditScreen::ID => PlanEditScreen::class,
        PlanServersScreen::ID => PlanServersScreen::class,
        PlanExtrasScreen::ID => PlanExtrasScreen::class,
        PlanPricesScreen::ID => PlanPricesScreen::class,
        CategoriesScreen::ID => CategoriesScreen::class,
        CategoryScreen::ID => CategoryScreen::class,
        TestsScreen::ID => TestsScreen::class,
        TestScreen::ID => TestScreen::class,
        TestServersScreen::ID => TestServersScreen::class,
        TestServerScreen::ID => TestServerScreen::class,
        TestSettingsScreen::ID => TestSettingsScreen::class,
        CustomServicesScreen::ID => CustomServicesScreen::class,
        CustomSettingsScreen::ID => CustomSettingsScreen::class,
        CustomTiersScreen::ID => CustomTiersScreen::class,
        CustomCalculatorScreen::ID => CustomCalculatorScreen::class,
    ];
}
