<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * «🤝 نماینده‌ها» (the panel's agents section): the agents, the newest first or in the order the panel's columns sort them
 * (joined, wallet balance, the bot's traffic, its sales — each both ways: «↕️», the list's state `sort`/`dir`),
 * filterable by level as the panel's pill — each a button, the row: how their selling stands as a chip (🟢 open, ⚠️ an
 * end or a short month near, ⏸ stopped), their name, level and the figure the list is sorted by (the bot's traffic at
 * first) —, opening their own screen (AgentScreen). The panel lists them alone: the page's rows are kept for it.
 *
 * Actions besides the list's: `sort` (the orders), `so` (key, asc|desc: the order picked).
 */
final class AgentsScreen extends ListScreen
{
    public const ID = 'agl';

    public const TITLE = Icons::AGENTS . ' نماینده‌ها';

    /** The panel's sorts (AgentSort), its default first: each order's two directions, as the screen's button names them. */
    private const SORTS = [
        'joined' => ['desc' => 'تازه‌ترین نماینده اول', 'asc' => 'قدیمی‌ترین نماینده اول'],
        'balance' => ['desc' => 'بیشترین موجودی اول', 'asc' => 'کمترین موجودی اول'],
        'traffic' => ['desc' => 'بیشترین حجم ربات اول', 'asc' => 'کمترین حجم ربات اول'],
        'sold' => ['desc' => 'بیشترین فروش اول', 'asc' => 'کمترین فروش اول'],
    ];

    /**
     * @var array<int|string, string> The levels by id — the level pill's choices —, read once for the update `$levelsOf`.
     */
    private array $levels = [];

    private ?Admin $levelsOf = null;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
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
        return AgencyScreen::path();
    }

    protected function endpoint(): string
    {
        return '/agency/agents';
    }

    protected function key(): string
    {
        return 'agents';
    }

    protected function filters(): array
    {
        $levels = $this->levels();

        return $levels === [] ? [] : ['level' => [self::ANY => 'همه سطح‌ها'] + $levels];
    }

    protected function fixed(): array
    {
        [$sort, $dir] = self::order($this->here?->state(self::ID) ?? []);

        return ['sort' => $sort, 'dir' => $dir];
    }

    protected function noun(): string
    {
        return 'نماینده';
    }

    protected function searchHint(): string
    {
        return 'نام، نام کاربری یا شناسه تلگرام';
    }

    protected function empty(): string
    {
        return 'نماینده‌ای نیست. با تایید اولین درخواست نمایندگی، نماینده این‌جا می‌آید.';
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return AgencyScreen::ID;
    }

    protected function item(array $row): Button
    {
        [$sort] = self::order($this->here?->state(self::ID) ?? []);
        $bot = is_array($row['bot'] ?? null) ? $row['bot'] : null;
        $standing = is_array($row['standing'] ?? null) ? $row['standing'] : null;
        $chip = match (true) {
            is_array($standing['suspension'] ?? null) => '⏸',
            AgentScreen::concern($row) !== null => '⚠️',
            default => '🟢',
        };
        $figure = match ($sort) {
            'balance' => Fmt::plain(Fmt::money((string) ($row['balance'] ?? '0'))),
            'sold' => Fmt::plain(Fmt::number((int) ($row['counts']['sold'] ?? 0))) . ' فروش',
            default => $bot !== null ? Fmt::plain(Fmt::bytes((int) ($bot['traffic_balance'] ?? 0))) : 'بدون ربات',
        };

        return Button::to(
            $chip . ' ' . Fmt::cut(Words::user($row['user'] ?? null), 20) . ' · ' . Fmt::cut((string) ($row['level']['name'] ?? ''), 12) . ' · ' . $figure,
            AgentScreen::ID,
            'show',
            (int) ($row['id'] ?? 0),
        );
    }

    protected function tools(Admin $admin): array
    {
        [$sort, $dir] = self::order($admin->state(self::ID));

        return [Button::to('↕️ ' . self::SORTS[$sort][$dir], self::ID, 'sort')];
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'so' && isset(self::SORTS[$args[0] ?? ''])) {
            $admin->remember(self::ID, ['sort' => $args[0], 'dir' => ($args[1] ?? '') === 'asc' ? 'asc' : 'desc', 'page' => 1]);
        } elseif ($action === 'sort') {
            [$sort, $dir] = self::order($admin->state(self::ID));
            $view = View::make('↕️ ترتیب نماینده‌ها', ...[...AgencyScreen::path(), Fmt::bare(self::TITLE)]);
            foreach (self::SORTS as $key => $labels) {
                $view->row(
                    Button::to(($key === $sort && $dir === 'desc' ? UiWords::PICKED : '') . $labels['desc'], self::ID, 'so', $key, 'desc'),
                    Button::to(($key === $sort && $dir === 'asc' ? UiWords::PICKED : '') . $labels['asc'], self::ID, 'so', $key, 'asc'),
                );
            }

            return $view->back(self::ID);
        }

        return $this->show($admin, []);
    }

    /**
     * The order the list is in (`sort`, `dir`), the panel's default when none was picked.
     *
     * @param array<string, mixed> $state
     * @return array{0: string, 1: string}
     */
    private static function order(array $state): array
    {
        $sort = is_string($state['sort'] ?? null) && isset(self::SORTS[$state['sort']]) ? $state['sort'] : 'joined';

        return [$sort, ($state['dir'] ?? null) === 'asc' ? 'asc' : 'desc'];
    }

    /**
     * The levels by id, read once an update (the list asks its filters more than once).
     *
     * @return array<int|string, string>
     */
    private function levels(): array
    {
        $admin = $this->here;
        if ($admin === null) {
            return [];
        }
        if ($this->levelsOf !== $admin) {
            $this->levels = [];
            foreach ((array) ($admin->api->get('/agency/levels')['levels'] ?? []) as $level) {
                if (is_array($level)) {
                    $this->levels[(string) ($level['id'] ?? '')] = (string) ($level['name'] ?? '');
                }
            }
            $this->levelsOf = $admin;
        }

        return $this->levels;
    }
}
