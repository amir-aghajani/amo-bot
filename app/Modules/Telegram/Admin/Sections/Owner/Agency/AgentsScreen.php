<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;

/**
 * «🤝 نماینده‌ها» (the panel's agents section): the agents, the newest first, filterable by level as the panel's pill —
 * each with their bot's traffic and whether its selling needs an eye —, opening their own screen (AgentScreen). The
 * panel lists them alone: the page's rows are kept for it.
 */
final class AgentsScreen extends ListScreen
{
    public const ID = 'agl';

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
        return '🤝 نماینده‌ها';
    }

    protected function path(): array
    {
        return ['مدیریت', 'نمایندگان'];
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
        $bot = is_array($row['bot'] ?? null) ? $row['bot'] : null;
        $mark = AgentScreen::concern($row) !== null ? '⚠️ ' : '';

        return Button::to(
            $mark . Words::user($row['user'] ?? null) . ' · ' . (string) ($row['level']['name'] ?? '') . ($bot !== null ? ' · ' . Fmt::plain(Fmt::bytes((int) ($bot['traffic_balance'] ?? 0))) : ''),
            AgentScreen::ID,
            'show',
            (int) ($row['id'] ?? 0),
        );
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
