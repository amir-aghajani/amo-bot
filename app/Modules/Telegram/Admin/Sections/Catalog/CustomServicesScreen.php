<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Messages;
use App\Support\Persian;

/**
 * «🛠 سرویس دلخواه» (the owner's panel page, the main shop alone): whether it sells, the devices of a built service and the
 * servers it is built on in its card — why it does not sell, over it —, then each quantity's price tiers with the bounds a
 * customer builds within and the buttons offered; the switch at a tap, and ways to each quantity's tiers
 * (CustomTiersScreen), its settings card (CustomSettingsScreen, reached from here alone) and the price calculator
 * (CustomCalculatorScreen); how it works folded away.
 *
 * Actions: `tog` (1|0: the switch set).
 */
final class CustomServicesScreen extends BaseScreen
{
    public const ID = 'cs';

    public const TITLE = Icons::CUSTOM . ' سرویس دلخواه';

    /** The program in a paragraph (the panel's ⓘ). */
    private const PROGRAM = 'مشتری در ربات زیر پلن‌ها «ساخت سرویس دلخواه» را می‌زند (یا در وب‌سایت می‌سازد)، حجم و مدت را از دکمه‌ها انتخاب می‌کند یا عددش را می‌نویسد، سرور را انتخاب می‌کند و مثل هر خرید دیگری پرداخت می‌کند. قیمت همه حجم با پله‌ای حساب می‌شود که حجم در آن است — مثلا ۶۰ گیگابایت با پله‌های «از ۱: ۳٬۰۰۰» و «از ۵۰: ۲٬۵۰۰» می‌شود ۶۰ × ۲٬۵۰۰ —، مدت هم همین‌طور، و دو قیمت با هم جمع می‌شوند. تخفیف خودکار مشتری و کد تخفیف مثل خرید بدون پلن حساب می‌شوند. سرویس ساخته‌شده پلن ندارد.';

    public function __construct(private readonly BotSettingsScreen $settings) {}

    public function allows(AdminSeat $seat): bool
    {
        return self::opens($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $settings = BotGroupSource::settings($admin);
        $enabled = (bool) ($settings['custom_enabled'] ?? false);
        $names = array_column(PlanEditScreen::options($admin)['servers'], 'name', 'id');
        $servers = array_map(static fn(mixed $id): string => (string) ($names[(int) $id] ?? 'سرور ' . Persian::digits((string) $id)), (array) ($settings['custom_servers'] ?? []));
        $untiered = array_filter(array_keys(CustomPricing::QUANTITIES), static fn(string $quantity): bool => CustomPricing::tiers($settings, $quantity) === []);

        $view = View::make(self::TITLE, ...CatalogFmt::under());
        if ($enabled && $servers === []) {
            $view->line('⚠️ سروری انتخاب نشده؛ تا یکی از سرورهای انتخاب‌شده مشتری تازه نگیرد، سرویس دلخواه فروخته نمی‌شود.');
        }
        if ($enabled && $untiered !== []) {
            $view->line('⚠️ ' . Fmt::e(implode(' و ', array_map(static fn(string $quantity): string => Fmt::bare(CustomPricing::QUANTITIES[$quantity]['title']), $untiered))) . ' پله قیمت ندارد؛ تا هر دو پله نداشته باشند، سرویس دلخواه فروخته نمی‌شود.');
        }
        $view->card([
            'وضعیت' => Fmt::onOff($enabled),
            'تعداد کاربر هم‌زمان' => Messages::devices((int) ($settings['custom_devices'] ?? 0)),
            'سرورها' => $servers === [] ? 'هیچ‌کدام' : Fmt::e(implode('، ', $servers)),
        ]);
        foreach (CustomPricing::QUANTITIES as $quantity => $words) {
            [$least, $most] = CustomPricing::bounds($settings, $quantity);
            $view->section($words['title'])
                ->lines(...CustomPricing::tierLines(CustomPricing::tiers($settings, $quantity), $quantity))
                ->line('مشتری بین ' . Persian::number($least) . ' تا ' . Persian::number($most) . ' ' . $words['unit'] . ' می‌سازد؛ دکمه‌ها: ' . Fmt::e(CustomPricing::buttons($settings, $quantity)));
        }

        return $view->row($enabled ? Button::to(Words::TURN_OFF, self::ID, 'tog', 0)->danger() : Button::to(Words::TURN_ON, self::ID, 'tog', 1)->success())
            ->row(...CustomTiersScreen::buttons(self::ID))
            ->row(Button::to(CustomSettingsScreen::TITLE, CustomSettingsScreen::ID), Button::to(CustomCalculatorScreen::TITLE, CustomCalculatorScreen::ID, Nav::SHOW, self::ID))
            ->help(Fmt::e(self::PROGRAM))
            ->back(CatalogFmt::HUB);
    }

    /**
     * Where `$screen` (the tiers, the calculator) goes back to: the page that opened it — this one, or the settings card
     * (CustomSettingsScreen) — named by `$opener` when it opens, kept while its own actions run.
     */
    public static function opener(Admin $admin, string $screen, ?string $opener): string
    {
        if (in_array($opener, [self::ID, CustomSettingsScreen::ID], true)) {
            $admin->remember($screen, ['from' => $opener]);
        }

        return ($admin->state($screen)['from'] ?? null) === CustomSettingsScreen::ID ? CustomSettingsScreen::ID : self::ID;
    }

    /** `$view`'s way back to `$opener` (opener()): the settings card as it stands, or this page. */
    public static function backTo(View $view, string $opener): View
    {
        return $opener === CustomSettingsScreen::ID ? $view->back($opener, SettingsScreen::VIEW) : $view->back(self::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'tog' && in_array($args[0] ?? null, ['1', '0'], true)) {
            $on = $args[0] === '1';
            $settings = BotGroupSource::settings($admin);
            if ((bool) ($settings['custom_enabled'] ?? false) === $on) {
                $admin->notice('سرویس دلخواه همین حالا ' . ($on ? 'روشن' : 'خاموش') . ' است');
            } else {
                $this->source()->save($admin, $settings, 'custom_enabled', $on);
                $admin->notice($on ? '▶️ سرویس دلخواه روشن شد' : '⏸ سرویس دلخواه خاموش شد');
            }
        }

        return $this->show($admin, []);
    }

    /** Whether `$seat` manages «سرویس دلخواه»: the main shop's alone, as the panel's page (`mainShop`). */
    public static function opens(AdminSeat $seat): bool
    {
        return $seat->configures() && $seat->shop()->isMain();
    }

    private function source(): BotGroupSource
    {
        return new BotGroupSource('custom_services', $this->settings);
    }
}
