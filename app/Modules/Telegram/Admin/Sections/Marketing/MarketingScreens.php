<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing;

use App\Modules\Telegram\Admin\Sections\Marketing\Cashback\CashbackRuleForm;
use App\Modules\Telegram\Admin\Sections\Marketing\Cashback\CashbackRuleScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Cashback\CashbackRulesScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Cashback\CashbackSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Discounts\DiscountCodeForm;
use App\Modules\Telegram\Admin\Sections\Marketing\Discounts\DiscountCodeScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Discounts\DiscountCodesScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Discounts\DiscountSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes\GiftBatchScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes\GiftCodeListScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes\GiftCodeScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes\GiftCodeSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes\GiftCodesForm;
use App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes\GiftCodesScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Lottery\LotteryDrawScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Lottery\LotteryDrawsScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Lottery\LotterySettingsScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Loyalty\LoyaltyTierForm;
use App\Modules\Telegram\Admin\Sections\Marketing\Loyalty\LoyaltyTierScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Loyalty\LoyaltyTiersScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Sales\SaleForm;
use App\Modules\Telegram\Admin\Sections\Marketing\Sales\SaleScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Sales\SalesScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Wheel\WheelPrizeForm;
use App\Modules\Telegram\Admin\Sections\Marketing\Wheel\WheelPrizeScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Wheel\WheelPrizesScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Wheel\WheelSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Wheel\WheelSpinScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Wheel\WheelSpinsScreen;

/** The management screens of discount codes, special sales, loyalty, cashback, gift codes, the wheel and the draw: their ids (Telegram\Admin\Ui\Screens) and classes. */
final class MarketingScreens
{
    /**
     * @var array<string, class-string<\App\Modules\Telegram\Admin\Ui\Screen>>
     */
    public const SCREENS = [
        DiscountCodesScreen::ID => DiscountCodesScreen::class,
        DiscountCodeScreen::ID => DiscountCodeScreen::class,
        DiscountCodeForm::ID => DiscountCodeForm::class,
        DiscountSettingsScreen::ID => DiscountSettingsScreen::class,
        SalesScreen::ID => SalesScreen::class,
        SaleScreen::ID => SaleScreen::class,
        SaleForm::ID => SaleForm::class,
        LoyaltyTiersScreen::ID => LoyaltyTiersScreen::class,
        LoyaltyTierScreen::ID => LoyaltyTierScreen::class,
        LoyaltyTierForm::ID => LoyaltyTierForm::class,
        CashbackRulesScreen::ID => CashbackRulesScreen::class,
        CashbackRuleScreen::ID => CashbackRuleScreen::class,
        CashbackRuleForm::ID => CashbackRuleForm::class,
        CashbackSettingsScreen::ID => CashbackSettingsScreen::class,
        GiftCodesScreen::ID => GiftCodesScreen::class,
        GiftBatchScreen::ID => GiftBatchScreen::class,
        GiftCodeListScreen::ID => GiftCodeListScreen::class,
        GiftCodeScreen::ID => GiftCodeScreen::class,
        GiftCodesForm::ID => GiftCodesForm::class,
        GiftCodeSettingsScreen::ID => GiftCodeSettingsScreen::class,
        WheelPrizesScreen::ID => WheelPrizesScreen::class,
        WheelPrizeScreen::ID => WheelPrizeScreen::class,
        WheelPrizeForm::ID => WheelPrizeForm::class,
        WheelSpinsScreen::ID => WheelSpinsScreen::class,
        WheelSpinScreen::ID => WheelSpinScreen::class,
        WheelSettingsScreen::ID => WheelSettingsScreen::class,
        LotteryDrawsScreen::ID => LotteryDrawsScreen::class,
        LotteryDrawScreen::ID => LotteryDrawScreen::class,
        LotterySettingsScreen::ID => LotterySettingsScreen::class,
    ];
}
