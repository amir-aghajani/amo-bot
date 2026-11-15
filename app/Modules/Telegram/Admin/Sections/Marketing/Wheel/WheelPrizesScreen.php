<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Wheel;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🎡 گردونه شانس» (the panel's «گردونه شانس › جوایز»): the wheel's slices in the admin's order — the order the wheel
 * shows; a list the panel answers whole —, each with what it gives and its chance, opening its own screen
 * (WheelPrizeScreen: switch, edit, move, delete); «➕ افزودن جایزه» (WheelPrizeForm), the spins (WheelSpinsScreen), the
 * rules (WheelSettingsScreen) and the panel's ⓘ. Over it the panel's warnings: the wheel off, or on with no button for it
 * on the bot's menu.
 *
 * Actions: `info`.
 */
final class WheelPrizesScreen extends ListScreen
{
    public const ID = 'wh';

    /** The program in a paragraph: the panel's ⓘ. */
    private const PROGRAM = 'مشتری در ربات دکمه «گردونه شانس» را می‌زند و گردونه را می‌چرخاند؛ پیام چند بار جابه‌جا می‌شود و روی یکی از جایزه‌ها می‌ایستد. جایزه همان لحظه داده می‌شود: شارژ کیف پول، حجم یا زمان روی سرویس فعال او، یا یک سرویس رایگان. هر مشتری بعد از هر چرخش باید تا نوبت بعدی صبر کند؛ اگر جایزه‌ای همان لحظه داده نشود، هزینه چرخش برمی‌گردد و آن چرخش حساب نمی‌شود.';

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
        $this->prizes = Words::all($admin, '/wheel/prizes', 'prizes');

        return parent::show($admin, $args);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🎡 گردونه شانس';
    }

    protected function path(): array
    {
        return Words::PATH;
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

    protected function empty(): string
    {
        return 'گردونه هنوز جایزه‌ای ندارد. جایزه‌ها را اضافه کنید — شارژ کیف پول، حجم، زمان، یک سرویس رایگان، یا «پوچ» — و به هر کدام وزنی بدهید؛ جایزه پروزن‌تر بیشتر می‌آید.';
    }

    protected function summary(array $meta): array
    {
        $lines = ['هر جایزه متنی که روی گردونه می‌آید، چیزی که می‌دهد و وزنی که شانسش را تعیین می‌کند؛ به همین ترتیب روی گردونه دیده می‌شوند.'];
        if ($this->here === null) {
            return $lines;
        }
        $this->on = (bool) (BotGroupSource::settings($this->here)['wheel_enabled'] ?? false);
        if (!$this->on) {
            $lines[] = '⛔️ گردونه شانس خاموش است: دکمه‌اش در منوی ربات نیست و کسی نمی‌تواند بچرخاند.';
        }
        $this->notice = Links::menuNotice($this->here, $this->on, 'wheel', 'گردونه شانس', 'چرخاندن گردونه در ربات');
        if ($this->notice !== null) {
            $lines[] = Fmt::e($this->notice[0]);
        }

        return $lines;
    }

    protected function parent(): string
    {
        return 'hm';
    }

    protected function item(array $row): Button
    {
        $enabled = (bool) ($row['enabled'] ?? false);
        $chance = self::chance($row, $this->prizes);

        return Button::to(($enabled ? '🎁 ' : '⏸ ') . (string) ($row['label'] ?? '') . ' · ' . ($chance === null ? 'غیرفعال' : Fmt::percent($chance)), WheelPrizeScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        if ($this->prizes !== []) {
            $view->note('شانس هر جایزه سهم وزن آن از جمع وزن جایزه‌های فعال است. جایزه‌ای که همان لحظه به مشتری نمی‌رسد — حجم یا زمان برای کسی که سرویس فعالی ندارد، سرویسی که سروری تحویلش نمی‌دهد — برای او کنار گذاشته می‌شود و شانسش بین بقیه پخش می‌شود.');
        }
        $view->row(Button::to('➕ افزودن جایزه', WheelPrizeForm::ID, 'new'), Button::to('🎡 چرخش‌ها', WheelSpinsScreen::ID))
            ->row($this->on ? null : Button::to('▶️ روشن کردن در تنظیمات گردونه', WheelSettingsScreen::ID), $this->notice[1] ?? null)
            ->row(Button::to('⚙️ تنظیمات گردونه', WheelSettingsScreen::ID), Button::to('❔ راهنما', self::ID, 'info'));
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        return $action === 'info' ? Words::help(self::ID, 'گردونه شانس', self::PROGRAM) : $this->show($admin, []);
    }

    /**
     * A slice's chance, its weight's share of the enabled slices' — a percent; null for one switched off.
     *
     * @param array<string, mixed> $prize
     * @param list<array<string, mixed>> $prizes
     */
    public static function chance(array $prize, array $prizes): ?float
    {
        $total = array_sum(array_map(static fn(array $one): int => (bool) ($one['enabled'] ?? false) ? (int) ($one['weight'] ?? 0) : 0, $prizes));

        return (bool) ($prize['enabled'] ?? false) && $total > 0 ? round((int) ($prize['weight'] ?? 0) / $total * 100, 1) : null;
    }
}
