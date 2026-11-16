<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Wheel;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🎡 گردونه شانس» (the panel's «گردونه شانس › جوایز»): whether the wheel turns — off, a tap turns it on through its rules
 * (WheelSettingsScreen, «⚙️ تنظیمات»); on with no button for it on the bot's menu, the panel's warning and the way to the
 * keyboards —, the odds of a spin (a prize, or nothing), and the slices in the admin's order — the order the wheel shows;
 * a list the panel answers whole —, each a button with its chip, label and chance, opening its own screen
 * (WheelPrizeScreen: switch, edit, move, delete); «➕ افزودن جایزه» (WheelPrizeForm), the spins (WheelSpinsScreen) and the
 * panel's ⓘ, folded.
 *
 * Actions: ListScreen's; `info` (an older window's «❔ راهنما»: the list, its help folded in it).
 */
final class WheelPrizesScreen extends ListScreen
{
    public const ID = 'wh';

    public const TITLE = Icons::WHEEL . ' ' . Marketing::WHEEL;

    /** What a slice switched on but never drawn says in place of its chance: a free service whose plan is gone. */
    private const PLAN_GONE = 'پلنش حذف شده';

    /** The program in a paragraph: the panel's ⓘ. */
    private const PROGRAM = 'مشتری در ربات دکمه «گردونه شانس» را می‌زند و گردونه را می‌چرخاند؛ پیام چند بار جابه‌جا می‌شود و روی یکی از جایزه‌ها می‌ایستد. جایزه همان لحظه داده می‌شود: شارژ کیف پول، حجم یا زمان روی سرویس فعال او، یا یک سرویس رایگان. هر مشتری بعد از هر چرخش باید تا نوبت بعدی صبر کند؛ اگر جایزه‌ای همان لحظه داده نشود، هزینه چرخش برمی‌گردد و آن چرخش حساب نمی‌شود.' . "\n\n"
        . 'شانس هر جایزه سهم وزن آن از جمع وزن جایزه‌های فعال است. جایزه‌ای که همان لحظه به مشتری نمی‌رسد — حجم یا زمان برای کسی که سرویس فعالی ندارد، سرویسی که سروری تحویلش نمی‌دهد — برای او کنار گذاشته می‌شود و شانسش بین بقیه پخش می‌شود.';

    /**
     * @var list<array<string, mixed>> Every slice, read as each update opens the list: a page's chances are shares of them all
     */
    private array $prizes = [];

    /** Whether the wheel is on, as summary() read it for extra(). */
    private bool $on = false;

    /** @var array{0: string, 1: ?Button}|null The menu's warning, as summary() read it for extra() */
    private ?array $notice = null;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $this->prizes = Marketing::all($admin, '/wheel/prizes', 'prizes');

        return parent::show($admin, $args);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function path(): array
    {
        return Marketing::PATH;
    }

    protected function endpoint(): string
    {
        return '/wheel/prizes';
    }

    protected function key(): string
    {
        return 'prizes';
    }

    protected function searchable(): bool
    {
        return false;
    }

    protected function noun(): string
    {
        return 'جایزه';
    }

    protected function empty(): string
    {
        return 'گردونه هنوز جایزه‌ای ندارد. جایزه‌ها را اضافه کنید — شارژ کیف پول، حجم، زمان، یک سرویس رایگان، یا «پوچ» — و به هر کدام وزنی بدهید؛ جایزه پروزن‌تر بیشتر می‌آید.';
    }

    protected function summary(array $meta): array
    {
        if ($this->here === null) {
            return [];
        }
        $this->on = (bool) (BotGroupSource::settings($this->here)['wheel_enabled'] ?? false);
        $this->notice = self::notice($this->here, $this->on);
        $lines = [Marketing::WHEEL . ': ' . Fmt::onOff($this->on) . ($this->on ? '' : ' · دکمه‌اش در منوی ربات نیست و کسی نمی‌چرخاند')];
        if ($this->notice !== null) {
            $lines[] = '⚠️ ' . Fmt::e($this->notice[0]);
        }
        $odds = self::odds($this->prizes);
        if ($odds !== null) {
            $lines[] = '<blockquote>شانس بردن در هر چرخش: <b>' . Fmt::percent($odds[0]) . '</b>' . "\n" . 'پوچ: <b>' . Fmt::percent($odds[1]) . '</b></blockquote>';
        }

        return $lines;
    }

    protected function parent(): string
    {
        return Marketing::MENU;
    }

    protected function item(array $row): Button
    {
        $enabled = (bool) ($row['enabled'] ?? false);
        $chance = self::chance($row, $this->prizes);
        $odds = match (true) {
            $chance !== null => 'شانس ' . Fmt::percent($chance),
            $enabled => self::PLAN_GONE,
            default => 'غیرفعال',
        };

        return Button::to(Marketing::dot($enabled) . ' ' . (string) ($row['label'] ?? '') . ' · ' . $odds, WheelPrizeScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->row(Button::to(Words::add('جایزه'), WheelPrizeForm::ID, 'new')->success(), Button::to(Icons::WHEEL . ' چرخش‌ها', WheelSpinsScreen::ID))
            ->row(...WheelSettingsScreen::buttons($this->on, Marketing::WHEEL))
            ->row($this->notice[1] ?? null)
            ->help(Fmt::e(self::PROGRAM));
    }

    /**
     * A slice's chance in a spin, as the wheel draws it (LuckyWheel::spin()): its weight's share of the drawn slices' — a
     * percent; null for one never drawn (drawn()).
     *
     * @param array<string, mixed> $prize
     * @param list<array<string, mixed>> $prizes
     */
    public static function chance(array $prize, array $prizes): ?float
    {
        $total = self::weight($prizes);

        return self::drawn($prize) && $total > 0 ? round(self::weighs($prize) / $total * 100, 1) : null;
    }

    /**
     * The panel's warning while the wheel is on and the bot's menu has no button for it: its words and the way to the
     * keyboards (Links::menuNotice()); null for none.
     *
     * @return array{0: string, 1: ?Button}|null
     * @throws ApiRefusal
     */
    public static function notice(Admin $admin, bool $on): ?array
    {
        return Links::menuNotice($admin, $on, 'wheel', Marketing::WHEEL, 'چرخاندن گردونه در ربات');
    }

    /**
     * Whether the wheel may land on a slice: switched on, and — a free service — its plan still there (a slice whose plan
     * was deleted is never drawn; its share goes to the rest).
     *
     * @param array<string, mixed> $prize
     */
    private static function drawn(array $prize): bool
    {
        return (bool) ($prize['enabled'] ?? false) && (($prize['kind'] ?? null) !== 'plan' || is_array($prize['plan'] ?? null));
    }

    /**
     * A spin's odds over the drawn slices: of a prize and of nothing, percents; null for no slice on the wheel.
     *
     * @param list<array<string, mixed>> $prizes
     * @return array{0: float, 1: float}|null
     */
    private static function odds(array $prizes): ?array
    {
        $total = self::weight($prizes);
        if ($total === 0) {
            return null;
        }
        $nothing = round(self::weight($prizes, static fn(array $one): bool => ($one['kind'] ?? null) === 'nothing') / $total * 100, 1);

        return [round(100 - $nothing, 1), $nothing];
    }

    /**
     * The drawn slices' weight — of those `$counts` takes, when given.
     *
     * @param list<array<string, mixed>> $prizes
     * @param (\Closure(array<string, mixed>): bool)|null $counts
     */
    private static function weight(array $prizes, ?\Closure $counts = null): int
    {
        return array_sum(array_map(static fn(array $one): int => self::drawn($one) && ($counts === null || $counts($one)) ? self::weighs($one) : 0, $prizes));
    }

    /**
     * A slice's weight as the wheel draws by it: at least one.
     *
     * @param array<string, mixed> $prize
     */
    private static function weighs(array $prize): int
    {
        return max(1, (int) ($prize['weight'] ?? 0));
    }
}
