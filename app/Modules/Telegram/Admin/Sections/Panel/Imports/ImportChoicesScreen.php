<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Imports;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «چه چیزهایی منتقل شود» of an import's review: its switches in three parts — the customers, the services and plans, the
 * codes and the rest —, each with what it brings, counted from the survey; a switch that goes with another, off, held
 * and saying so. A switch flipped is saved at once (PUT …/choices, every choice as it stands); the API turns off what
 * goes with a switch turned off, and turned on again it gets back what it was, as the panel's review keeps it.
 *
 * Actions: `t` (n, 1|0: switch n turned on or off — a stale or second tap changes nothing).
 */
final class ImportChoicesScreen extends BaseScreen
{
    public const ID = 'ppic';

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $import = ImportPanelsScreen::reviewed($admin);
        if ($import === null) {
            return $admin->open(ImportsScreen::ID);
        }
        $choices = Words::choices($import);
        $view = View::make('✅ چه چیزهایی منتقل شود', ...ImportsScreen::under())
            ->line('هر دکمه یک بخش است و حالتش را نشان می‌دهد: 🟢 منتقل می‌شود، ⏸ نه، 🔒 تا بخشی که به آن وابسته است روشن نشود. با یک ضربه عوض می‌شود.');
        $buttons = [];
        $about = ['آنچه از قبل با همین انتقال یا انتقال قبلی آمده، دوباره نمی‌آید؛ موجودی هر مشتری فقط یک بار اضافه می‌شود.'];
        $n = 0;
        // Many switches: each button its chip and the switch (DESIGN §3), what each brings — counted — in the folded help.
        foreach (Words::parts((array) $import['survey']) as [$title, $words, $switches]) {
            $about[] = '<b>' . Fmt::e($title) . '</b>: ' . Fmt::e($words);
            foreach ($switches as $switch) {
                $held = isset($switch['needs']) && ($choices[$switch['needs'][0]] ?? false) !== true;
                $on = !$held && ($choices[$switch['name']] ?? false) === true;
                $about[] = '• <b>' . Fmt::e($switch['label']) . '</b>: ' . Fmt::e($held && isset($switch['needs']) ? $switch['needs'][1] : $switch['hint']);
                $buttons[] = Button::to(($held ? '🔒 ' : ($on ? '🟢 ' : '⏸ ')) . $switch['label'], self::ID, 't', $n, $on ? 0 : 1);
                $n++;
            }
        }

        return $view->tiles(...$buttons)->help(implode("\n", $about))->back(ImportsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $import = ImportPanelsScreen::reviewed($admin);
        if ($action !== 't' || $import === null) {
            return $this->show($admin, []);
        }
        $switches = array_merge(...array_map(static fn(array $part): array => $part[2], Words::parts((array) $import['survey'])));
        $switch = $switches[self::id($args)] ?? null;
        if ($switch === null) {
            return $this->show($admin, []);
        }
        $choices = Words::choices($import);
        if (isset($switch['needs']) && ($choices[$switch['needs'][0]] ?? false) !== true) {
            $admin->alert($switch['needs'][1]);

            return $this->show($admin, []);
        }
        $want = isset($args[1]) ? $args[1] === '1' : ($choices[$switch['name']] ?? false) !== true;
        if (($choices[$switch['name']] ?? false) === $want) {
            return $this->show($admin, []);
        }
        $choices[$switch['name']] = $want;
        // What goes with it: kept as it was while it is off, given back when it is on again.
        $id = (int) ($import['id'] ?? 0);
        $state = $admin->state(self::ID);
        $kept = ($state['import'] ?? null) === $id ? Json::part($state, 'kept') : [];
        foreach ($switches as $other) {
            if (($other['needs'][0] ?? null) !== $switch['name']) {
                continue;
            }
            if (!$want) {
                $kept[$other['name']] = ($choices[$other['name']] ?? false) === true;
            } elseif (array_key_exists($other['name'], $kept)) {
                $choices[$other['name']] = $kept[$other['name']] === true;
                unset($kept[$other['name']]);
            }
        }
        $admin->api->put('/imports/' . $id . '/choices', ['choices' => Words::shown($choices)]);
        $admin->remember(self::ID, ['import' => $id, 'kept' => $kept]);
        $admin->notice('✅ «' . $switch['label'] . '» ذخیره شد');

        return $this->show($admin, []);
    }
}
