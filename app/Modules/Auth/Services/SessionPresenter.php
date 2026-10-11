<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Principal;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;

/**
 * A panel's session as `GET /auth/me` and the sign-ins answer it: who is signed in and the shop shown. A shop is named
 * «فروشگاه اصلی», an agent's by its bot's title, else (no bot handed over yet) by its agent.
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
