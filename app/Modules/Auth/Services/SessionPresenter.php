<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Principal;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;

/**
 * What a panel is told about its session (`GET /auth/me`, and every sign-in): who is signed in and whose shop the
 * panel shows — and how a shop is named, there, in the owner's shop picker and when the owner opens one: the main
 * bot's by that, an agent's by its bot's title (what Telegram shows its customers), else — a bot not handed over yet —
 * by its agent; whether it runs beside it (an agent's goes off when their agency ends).
 */
final class SessionPresenter
{
    public function __construct(private readonly Bots $bots) {}

    /** @return array{name: string, shop: array{id: int, name: string, username: string|null, status: string}} */
    public function present(Principal $principal): array
    {
        return ['name' => $principal->name, 'shop' => $this->shop($principal->shop)];
    }

    /** @return array{id: int, name: string, username: string|null, status: string} */
    public function shop(Bot $bot): array
    {
        $username = $this->bots->username($bot);
        $name = match (true) {
            $bot->isMain() => 'فروشگاه اصلی',
            ($bot->title ?? '') !== '' => (string) $bot->title,
            default => 'نماینده: ' . ($bot->agent?->name() ?? '#' . $bot->id),
        };

        return ['id' => $bot->id, 'name' => $name, 'username' => $username !== '' ? $username : null, 'status' => $bot->status()->value];
    }
}
