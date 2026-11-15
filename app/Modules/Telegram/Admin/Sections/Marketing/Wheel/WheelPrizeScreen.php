<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Wheel;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One slice of the wheel (the panel's row): what it gives, its weight, its chance and its place on the wheel, with its
 * switch, «✏️ ویرایش» (WheelPrizeForm), «🔼 بالاتر»/«🔽 پایین‌تر» moving it a place (the panel's drag: POST
 * /wheel/prizes/reorder) and «🗑 حذف» — asked first, with the panel's words.
 *
 * Actions: `on` (id), `off` (id), `up` (id, place), `down` (id, place), `del` (id: asks), `gone` (id: deletes).
 */
final class WheelPrizeScreen extends BaseScreen
{
    public const ID = 'whv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $prizes = Words::all($admin, '/wheel/prizes', 'prizes');
        $row = self::find($prizes, self::id($args));

        return $row === null ? $admin->open(WheelPrizesScreen::ID) : $this->view($row, $prizes);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'on':
            case 'off':
                $admin->api->patch("/wheel/prizes/{$id}", ['enabled' => $action === 'on']);
                $admin->notice($action === 'on' ? '✅ جایزه فعال شد' : '⏸ جایزه غیرفعال شد');
                break;
            case 'up':
            case 'down':
                $ids = array_map(static fn(array $prize): int => (int) ($prize['id'] ?? 0), Words::all($admin, '/wheel/prizes', 'prizes'));
                $at = array_search($id, $ids, true);
                $to = $at === false ? false : ($action === 'up' ? $at - 1 : $at + 1);
                // The button names the place it moves the slice from: a second tap, or one under a window the order
                // changed since, finds it elsewhere and moves nothing.
                $from = isset($args[1]) ? self::id($args, 1) : $at;
                if ($at !== false && $from === $at && $to >= 0 && $to < count($ids)) {
                    [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
                    $admin->api->post('/wheel/prizes/reorder', ['ids' => $ids]);
                    $admin->notice($action === 'up' ? '🔼 جایزه یک جا بالاتر رفت' : '🔽 جایزه یک جا پایین‌تر رفت');
                }
                break;
            case 'del':
                $row = Words::one($admin, '/wheel/prizes', 'prizes', $id);
                if ($row === null) {
                    return $admin->open(WheelPrizesScreen::ID);
                }

                return $admin->confirm(
                    '«' . (string) ($row['label'] ?? '') . '» از گردونه حذف شود؟',
                    Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    'چرخش‌هایی که روی این جایزه ایستاده‌اند همان‌طور در لیست چرخش‌ها می‌مانند. برای کنار گذاشتن موقت، کافی است آن را غیرفعال کنید.',
                );
            case 'gone':
                $admin->api->delete("/wheel/prizes/{$id}");
                $admin->notice('🗑 جایزه حذف شد');

                return $admin->open(WheelPrizesScreen::ID);
        }

        return $this->show($admin, [(string) $id]);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $prizes
     */
    private function view(array $row, array $prizes): View
    {
        $id = (int) ($row['id'] ?? 0);
        $enabled = (bool) ($row['enabled'] ?? false);
        $place = (int) array_search($id, array_map(static fn(array $prize): int => (int) ($prize['id'] ?? 0), $prizes), true);
        $chance = WheelPrizesScreen::chance($row, $prizes);

        return View::make('🎁 ' . (string) ($row['label'] ?? ''), ...[...Words::PATH, 'گردونه شانس'])
            ->facts([
                'جایزه' => Fmt::e(Words::prize($row)),
                'وزن' => Fmt::number((int) ($row['weight'] ?? 0)),
                'شانس' => $chance === null ? Fmt::NONE : Fmt::percent($chance),
                'جای روی گردونه' => Fmt::number($place + 1) . ' از ' . Fmt::number(count($prizes)),
                'وضعیت' => Words::onOff($enabled),
            ])
            ->row($enabled ? Button::to('⏸ غیرفعال کردن', self::ID, 'off', $id) : Button::to('▶️ فعال کردن', self::ID, 'on', $id), Button::to('✏️ ویرایش', WheelPrizeForm::ID, 'edit', $id))
            ->row(
                $place > 0 ? Button::to('🔼 بالاتر', self::ID, 'up', $id, $place) : null,
                $place < count($prizes) - 1 ? Button::to('🔽 پایین‌تر', self::ID, 'down', $id, $place) : null,
            )
            ->row(Button::to('🗑 حذف', self::ID, 'del', $id))
            ->back(WheelPrizesScreen::ID);
    }

    /**
     * @param list<array<string, mixed>> $prizes
     * @return array<string, mixed>|null
     */
    private static function find(array $prizes, int $id): ?array
    {
        foreach ($prizes as $prize) {
            if ((int) ($prize['id'] ?? 0) === $id) {
                return $prize;
            }
        }

        return null;
    }
}
