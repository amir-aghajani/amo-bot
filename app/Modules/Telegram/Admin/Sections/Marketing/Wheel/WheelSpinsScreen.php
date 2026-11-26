<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Wheel;

use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🎡 چرخش‌ها» (the panel's «گردونه شانس › چرخش‌ها»): every spin, newest first, searched by customer — or one customer's
 * (`u`, as a customer's page or a spin opens it; the panel's ?user=) —, each a button with whether its prize was given,
 * who spun and the slice, opening its own screen (WheelSpinScreen). The panel has no route for one spin: the page's rows
 * are kept for it.
 *
 * Actions: ListScreen's; `u` (customer id), `nou` (every customer).
 */
final class WheelSpinsScreen extends ListScreen
{
    public const ID = 'whn';

    /** The customer the list is narrowed to, read from the state as each update opens it; 0 for every one. */
    private int $user = 0;

    public function show(Admin $admin, array $args): View
    {
        $this->user = (int) ($admin->state(self::ID)['user'] ?? 0);

        return parent::show($admin, $args);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return Icons::WHEEL . ' چرخش‌ها';
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::WHEEL);
    }

    protected function endpoint(): string
    {
        return '/wheel/spins';
    }

    protected function key(): string
    {
        return 'spins';
    }

    protected function fixed(): array
    {
        return $this->user > 0 ? ['user' => $this->user] : [];
    }

    protected function searchHint(): string
    {
        return 'نام، نام کاربری یا شناسه تلگرام مشتری';
    }

    protected function noun(): string
    {
        return 'چرخش';
    }

    protected function empty(): string
    {
        return 'چرخشی نیست. هر بار که مشتری‌ای در ربات گردونه را بچرخاند، جایزه‌اش این‌جا می‌آید.';
    }

    protected function narrowedBy(): array
    {
        return $this->user > 0 ? ['مشتری ' . Fmt::plain(Fmt::id($this->user))] : [];
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return WheelPrizesScreen::ID;
    }

    protected function item(array $row): Button
    {
        return Button::to(((bool) ($row['given'] ?? false) ? '✅ ' : '❌ ') . Marketing::customer($row['customer'] ?? null) . ' · ' . (string) ($row['label'] ?? ''), WheelSpinScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->help('هر بار که مشتری‌ای گردونه را چرخانده، تازه‌ترین اول: ✅ جایزه‌اش داده شد، ❌ داده نشد (چرخشی که جایزه‌اش نرسید حساب نمی‌شود و هزینه‌اش برمی‌گردد).');
    }

    protected function tools(Admin $admin): array
    {
        return [$this->user > 0 ? Button::to('✖️ همه مشتری‌ها', self::ID, 'nou') : null];
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'u' || $action === 'nou') {
            $admin->remember(self::ID, ['user' => $action === 'u' ? self::id($args) : 0, 'page' => 1, 'search' => '']);
        }

        return $this->show($admin, []);
    }
}
