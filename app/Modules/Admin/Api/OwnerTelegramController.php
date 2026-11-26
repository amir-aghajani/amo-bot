<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «مدیریت از ربات» (the owner's, «تنظیمات پنل › ورود به پنل»): the Telegram accounts that are the owner in the main bot's
 * private chat (OwnerLinks), a one-time link that makes one more — the panel's password asked first —, and an account
 * let go. The owner's alone: one of the main shop's admins, working the bot's management with the owner's seat, is
 * refused all of it (an account they linked would be the owner, past their role and its limits).
 */
final class OwnerTelegramController extends ApiController
{
    public const NO_USERNAME = 'یوزرنیم ربات اصلی هنوز معلوم نیست؛ اول در «تنظیمات پنل › ربات تلگرام» توکن ربات را بررسی کنید.';

    public function __construct(
        private readonly OwnerLinks $links,
        private readonly AdminAccount $account,
    ) {}

    /** GET /settings/owner-telegram */
    public function index(Request $request, Response $response): Response
    {
        self::theOwner($request);

        return $this->json($response, $this->present());
    }

    /**
     * POST /settings/owner-telegram/link — {current_password}: a new one-time link (the last one ends). From the panel the
     * password is typed again and judged with the login's failures (AdminAccount::confirm()), so a session alone — one
     * stolen — makes no account the owner. From the bot (its door's seat) the one asking is a linked account already.
     *
     * @throws ValidationException 422: the password blank or wrong (`current_password`), or the main bot's username not
     *                             known yet (`link`)
     * @throws TooManyAttemptsException 429 while this address, or the account, must wait
     * @throws ActorRefusedException 403 to one of the shop's admins
     */
    public function link(Request $request, Response $response): Response
    {
        self::theOwner($request);
        if (!$request->getAttribute(BotAdminDoor::SEAT) instanceof AdminSeat) {
            $unproven = $this->account->confirm($request, Input::password($this->input($request), 'current_password'));
            if ($unproven !== null) {
                throw ValidationException::on('current_password', $unproven);
            }
        }
        $link = $this->links->link() ?? throw ValidationException::on('link', self::NO_USERNAME);

        return $this->json($response, ['link' => ['url' => $link['url'], 'expires_at' => $link['expires_at']->toIso8601String()]] + $this->present());
    }

    /**
     * DELETE /settings/owner-telegram/{telegram_id}
     *
     * @param array<string, string> $args
     * @throws ActorRefusedException 403 to one of the shop's admins
     */
    public function unlink(Request $request, Response $response, array $args): Response
    {
        self::theOwner($request);
        $this->links->unlink((int) $args['telegram_id']);

        return $this->json($response, $this->present());
    }

    /**
     * Refuses anyone but the owner themselves: one of the main shop's admins working the bot's management with the
     * owner's seat.
     *
     * @throws ActorRefusedException 403
     */
    private static function theOwner(Request $request): void
    {
        if (!Principal::of($request)->isTheOwner()) {
            throw ActorRefusedException::ownerOnly();
        }
    }

    /** @return array{accounts: list<array<string, mixed>>, link_open_until: string|null} */
    private function present(): array
    {
        $ids = $this->links->ids();
        $users = User::query()->withoutGlobalScopes()->where('bot_id', Bot::MAIN)->whereIn('telegram_id', $ids)->get()->keyBy('telegram_id');

        return [
            'accounts' => array_map(static function (int $id) use ($users): array {
                $user = $users->get($id);

                return [
                    'telegram_id' => $id,
                    'name' => $user instanceof User ? $user->name() : null,
                    // An account that shows no handle is null, never an empty one.
                    'username' => $user instanceof User ? $user->handle() : null,
                ];
            }, $ids),
            'link_open_until' => $this->links->openUntil()?->toIso8601String(),
        ];
    }
}
