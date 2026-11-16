<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One agency level: its name, its price per GB (what 100 GB costs its agents) and how many agents are on it; «✏️
 * ویرایش» (AgencyLevelFormScreen), moved up or down the bot's order, and «🗑 حذف» — asked first, and only while no agent
 * is on it (the panel's words say what to do otherwise).
 *
 * Actions: `up` (id), `down` (id), `del` (id: asks), `gone` (id: deletes).
 */
final class AgencyLevelScreen extends BaseScreen
{
    public const ID = 'agkv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        [$levels, $index] = $this->find($admin, self::id($args));
        if ($index === null) {
            return $admin->open(AgencyLevelsScreen::ID);
        }
        $level = $levels[$index];
        $id = (int) ($level['id'] ?? 0);
        $price = (int) round((float) ($level['price_per_gb'] ?? 0));

        return View::make('🏷 ' . (string) ($level['name'] ?? ''), ...[...AgencyScreen::path(), 'سطح‌ها'])
            ->card([
                'نام سطح' => Fmt::e((string) ($level['name'] ?? '')),
                'قیمت هر گیگابایت' => Fmt::money((string) $price),
                '۱۰۰ گیگابایت' => Fmt::money((string) ($price * 100)),
                'نماینده‌ها' => Fmt::number((int) ($level['counts']['agents'] ?? 0)),
                'جای آن' => Fmt::number($index + 1) . ' از ' . Fmt::number(count($levels)),
            ])
            ->row(Button::to(Words::EDIT, AgencyLevelFormScreen::ID, 'edit', $id)->primary())
            ->row(
                $index > 0 ? Button::to(Words::UP . ' بالاتر', self::ID, 'up', $id) : null,
                $index < count($levels) - 1 ? Button::to(Words::DOWN . ' پایین‌تر', self::ID, 'down', $id) : null,
            )
            ->row(Button::to(Words::DELETE . ' سطح', self::ID, 'del', $id)->danger())
            ->back(AgencyLevelsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        [$levels, $index] = $this->find($admin, $id);
        if ($index === null) {
            return $admin->open(AgencyLevelsScreen::ID);
        }
        $level = $levels[$index];
        switch ($action) {
            case 'up':
            case 'down':
                $other = $index + ($action === 'up' ? -1 : 1);
                if (isset($levels[$other])) {
                    [$levels[$index], $levels[$other]] = [$levels[$other], $levels[$index]];
                    $admin->api->post('/agency/levels/reorder', ['ids' => array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $levels)]);
                    $admin->notice('✅ جابه‌جا شد');
                }
                break;
            case 'del':
                $agents = (int) ($level['counts']['agents'] ?? 0);
                if ($agents > 0) {
                    $admin->alert(($agents === 1 ? 'یک نماینده روی «' . (string) ($level['name'] ?? '') . '» است؛ اول او را' : Fmt::number($agents) . ' نماینده روی «' . (string) ($level['name'] ?? '') . '» هستند؛ اول آن‌ها را') . ' به سطح دیگری ببرید، بعد سطح را حذف کنید.');
                    break;
                }

                return $admin->confirm(
                    '«' . (string) ($level['name'] ?? '') . '» حذف شود؟',
                    Button::to(Words::yes('حذف شود', '🗑'), self::ID, 'gone', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                    'هیچ نماینده‌ای روی این سطح نیست.',
                );
            case 'gone':
                $admin->api->delete("/agency/levels/{$id}");
                $admin->notice('🗑 سطح حذف شد');

                return $admin->open(AgencyLevelsScreen::ID);
        }

        return $this->show($admin, [(string) $id]);
    }

    /**
     * The levels in order, and where `$id` is among them (null: not there).
     *
     * @return array{0: list<array<string, mixed>>, 1: int|null}
     */
    private function find(Admin $admin, int $id): array
    {
        $levels = Json::rows($admin->api->get('/agency/levels'), 'levels');
        foreach ($levels as $index => $level) {
            if ((int) ($level['id'] ?? 0) === $id) {
                return [$levels, $index];
            }
        }

        return [$levels, null];
    }
}
