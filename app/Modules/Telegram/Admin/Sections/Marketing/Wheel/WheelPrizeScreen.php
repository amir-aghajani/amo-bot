<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Wheel;

use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One slice of the wheel (the panel's row): its summary card — state, what it gives, its weight, its chance and its place
 * on the wheel —, «✏️ ویرایش» (WheelPrizeForm), «🔼»/«🔽» moving it a place (the panel's drag: POST
 * /wheel/prizes/reorder), its switch and «🗑 حذف» — asked first, with the panel's words.
 *
 * Actions: `on` (id), `off` (id), `up` (id, place), `down` (id, place), `del` (id: asks), `gone` (id: deletes).
 */
final class WheelPrizeScreen extends BaseScreen
{
    public const ID = 'whv';

    public function show(Admin $admin, array $args): View
    {
        $prizes = Marketing::all($admin, '/wheel/prizes', 'prizes');
        $row = Marketing::find($prizes, self::id($args));

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
                $ids = array_map(static fn(array $prize): int => (int) ($prize['id'] ?? 0), Marketing::all($admin, '/wheel/prizes', 'prizes'));
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
                $row = Marketing::one($admin, '/wheel/prizes', 'prizes', $id);
                if ($row === null) {
                    return $admin->open(WheelPrizesScreen::ID);
                }

                return Marketing::deletion($admin, self::ID, $id, '«' . (string) ($row['label'] ?? '') . '» از گردونه حذف شود؟', 'چرخش‌هایی که روی این جایزه ایستاده‌اند همان‌طور در لیست چرخش‌ها می‌مانند. برای کنار گذاشتن موقت، غیرفعالش کنید.');
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

        return View::make(Icons::WHEEL . ' ' . (string) ($row['label'] ?? ''), ...Marketing::in(Marketing::WHEEL))
            ->card([
                'وضعیت' => Fmt::active($enabled),
                'جایزه' => Fmt::e(Marketing::prize($row)),
                'وزن' => Fmt::number((int) ($row['weight'] ?? 0)),
                'شانس هر چرخش' => match (true) {
                    $chance !== null => Fmt::percent($chance),
                    // On, but its plan gone: never drawn until another plan is picked for it.
                    $enabled => 'هیچ؛ پلن دیگری برایش انتخاب کنید',
                    default => 'روی گردونه نیست',
                },
                'جای روی گردونه' => Fmt::number($place + 1) . ' از ' . Fmt::number(count($prizes)),
            ])
            ->row($enabled ? null : Button::to(Words::ACTIVATE, self::ID, 'on', $id)->success(), Button::to(Words::EDIT, WheelPrizeForm::ID, 'edit', $id)->primary())
            ->row(
                $place > 0 ? Button::to(Words::UP . ' بالاتر', self::ID, 'up', $id, $place) : null,
                $place < count($prizes) - 1 ? Button::to(Words::DOWN . ' پایین‌تر', self::ID, 'down', $id, $place) : null,
            )
            ->row($enabled ? Button::to(Words::DEACTIVATE, self::ID, 'off', $id)->danger() : null, Button::to(Words::DELETE, self::ID, 'del', $id)->danger())
            ->back(WheelPrizesScreen::ID);
    }
}
