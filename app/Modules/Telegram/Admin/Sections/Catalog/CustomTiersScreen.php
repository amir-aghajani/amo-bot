<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Persian;

/**
 * One quantity's price tiers of «سرویس دلخواه» (the settings card's «قیمت هر گیگابایت» / «قیمت هر روز» rows): each
 * tier from how many units up and its price a unit — every unit at the rate of the tier the quantity falls into. A tier
 * added (one with the same start replaced), every tier typed anew a line each, or one taken off, asked first; each change
 * saved through the bot settings' route at once, the panel's checks answering it.
 *
 * Actions: `add` (quantity: asks), `all` (quantity: asks), `del` (quantity, from: asks), `gone` (quantity, from).
 */
final class CustomTiersScreen extends BaseScreen
{
    public const ID = 'cst';

    /** The most tiers a list holds (TierPrices::MOST). */
    private const MOST = 10;

    public function __construct(private readonly BotSettingsScreen $settings) {}

    public function allows(AdminSeat $seat): bool
    {
        return CustomServicesScreen::opens($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $quantity = self::quantity($args);
        $words = CustomPricing::QUANTITIES[$quantity];
        $tiers = CustomPricing::tiers(BotGroupSource::settings($admin), $quantity);
        $view = View::make($words['title'] . ': ' . $words['unitPrice'], ...CatalogFmt::under(Fmt::bare(CustomServicesScreen::TITLE)))
            ->total(count($tiers), 'پله')
            ->lines(...CustomPricing::tierLines($tiers, $quantity));
        if (count($tiers) < self::MOST) {
            $view->row(Button::to(Words::add('پله'), self::ID, 'add', $quantity)->success());
        }
        $view->row(Button::to(Words::edit('همه پله‌ها با هم'), self::ID, 'all', $quantity));
        foreach ($tiers as $tier) {
            $view->row(Button::to(Words::DELETE . ' پله از ' . Persian::number($tier['from']) . ' ' . $words['unit'], self::ID, 'del', $quantity, $tier['from'])->danger());
        }
        $view->help(Fmt::e($words['about'] . " هر پله: از چند {$words['unit']} به بالا، هر {$words['unit']} چند تومان. همه {$words['unit']}‌ها با قیمت پله‌ای حساب می‌شوند که عدد مشتری در آن است؛ حداکثر " . Persian::number(self::MOST) . ' پله.'));

        return CustomServicesScreen::backTo($view, CustomServicesScreen::opener($admin, self::ID, $args[1] ?? null));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $quantity = self::quantity($args);
        $unit = CustomPricing::QUANTITIES[$quantity]['unit'];
        switch ($action) {
            case 'add':
                return $admin->ask((new Prompt('پله تازه', self::ID, 'add', [$quantity], Fmt::e("دو عدد بنویسید: از چند {$unit} به بالا، و قیمت هر {$unit} به تومان. پله‌ای با همان شروع، جایگزین می‌شود."), Button::to(Words::CANCEL, self::ID, Nav::SHOW, $quantity)))
                    ->example('۵۰ ۲۵۰۰'));
            case 'all':
                $settings = BotGroupSource::settings($admin);
                $current = implode("\n", array_map(static fn(array $tier): string => $tier['from'] . ' ' . $tier['price'], CustomPricing::tiers($settings, $quantity)));

                return $admin->ask((new Prompt(
                    'همه پله‌ها',
                    self::ID,
                    'all',
                    [$quantity],
                    Fmt::e("هر پله در یک خط: از چند {$unit}، و قیمت هر {$unit} به تومان. همه پله‌های فعلی جای خود را به این‌ها می‌دهند."),
                    Button::to(Words::CANCEL, self::ID, Nav::SHOW, $quantity),
                ))->current($current === '' ? null : $current)->example("۱ ۳۰۰۰\n۵۰ ۲۵۰۰")->limits('حداکثر ' . Persian::number(self::MOST) . ' پله'));
            case 'del':
                $from = self::id($args, 1);

                return $admin->confirm(
                    'این پله حذف شود؟',
                    Button::to(Words::yes('حذف شود', CatalogFmt::emoji(Words::DELETE)), self::ID, 'gone', $quantity, $from),
                    Button::to(Words::CANCEL, self::ID, Nav::SHOW, $quantity),
                    Fmt::e('پله «از ' . Persian::number($from) . " {$unit}» از پله‌های قیمت برداشته می‌شود و قیمت سرویس‌های تازه بی آن حساب می‌شود."),
                    danger: true,
                );
            case 'gone':
                $from = self::id($args, 1);
                $settings = BotGroupSource::settings($admin);
                $tiers = array_values(array_filter(CustomPricing::tiers($settings, $quantity), static fn(array $tier): bool => $tier['from'] !== $from));
                $this->save($admin, $settings, $quantity, $tiers);
                $admin->notice('🗑 پله حذف شد');
                break;
        }

        return $this->show($admin, [$quantity]);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $quantity = self::quantity($args);
        $settings = BotGroupSource::settings($admin);
        if ($action === 'add') {
            $tier = self::tier($answer->latin());
            $tiers = array_values(array_filter(CustomPricing::tiers($settings, $quantity), static fn(array $kept): bool => $kept['from'] !== $tier['from']));
            $this->save($admin, $settings, $quantity, [...$tiers, $tier]);
            $admin->notice('✅ پله ذخیره شد');
        } elseif ($action === 'all') {
            $tiers = [];
            foreach (preg_split('/\R/u', $answer->latin()) ?: [] as $line) {
                if (trim($line) !== '') {
                    $tiers[] = self::tier($line);
                }
            }
            $this->save($admin, $settings, $quantity, $tiers);
            $admin->notice('✅ پله‌ها ذخیره شد');
        }

        return $this->show($admin, [$quantity]);
    }

    /**
     * «✏️ پله‌های حجم» · «✏️ پله‌های مدت»: a button to each quantity's tiers, whose way back is `$opener` (the page or the
     * settings card the row is on: CustomServicesScreen::opener()).
     *
     * @return list<Button>
     */
    public static function buttons(string $opener): array
    {
        return array_map(static fn(string $quantity): Button => Button::to(Words::edit('پله‌های ' . Fmt::bare(CustomPricing::QUANTITIES[$quantity]['title'])), self::ID, Nav::SHOW, $quantity, $opener), array_keys(CustomPricing::QUANTITIES));
    }

    /**
     * The quantity's tiers saved, the group's other fields as they are.
     *
     * @param array<string, mixed> $settings
     * @param list<array{from: int, price: int}> $tiers
     */
    private function save(Admin $admin, array $settings, string $quantity, array $tiers): void
    {
        usort($tiers, static fn(array $a, array $b): int => $a['from'] <=> $b['from']);
        $sent = array_map(static fn(array $tier): array => ['from' => $tier['from'], 'price' => (string) $tier['price']], $tiers);
        (new BotGroupSource('custom_services', $this->settings))->save($admin, $settings, "custom_{$quantity}_tiers", $sent);
    }

    /**
     * A tier typed: its start and its price, two whole numbers.
     *
     * @return array{from: int, price: int}
     */
    private static function tier(string $line): array
    {
        preg_match_all('/\d{1,3}(?:[,٬]\d{3})+(?!\d)|\d+/u', $line, $numbers);
        $numbers = array_map(static fn(string $number): int => (int) str_replace([',', '٬'], '', $number), $numbers[0]);
        if (count($numbers) !== 2) {
            throw new ApiRefusal(422, 'هر پله دو عدد است: شروعش و قیمت هر واحد؛ مثل «۵۰ ۲۵۰۰».');
        }

        return ['from' => $numbers[0], 'price' => $numbers[1]];
    }

    /** @param list<string> $args */
    private static function quantity(array $args): string
    {
        return isset(CustomPricing::QUANTITIES[$args[0] ?? '']) ? (string) $args[0] : 'traffic';
    }
}
