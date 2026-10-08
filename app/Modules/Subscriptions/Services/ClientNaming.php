<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Sequence;
use App\Modules\Bots\Services\Bots;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;

/**
 * What a customer's client is called on the panel, so its client list reads as people. A customer with a Telegram
 * username gets "<username>_<n>" (amir_1, amir_2 … across every server), n from a counter of their own; one without
 * gets "USER_<n>" from a single counter shared by all nameless purchases. The counters only go up, so the number of a
 * service that was deleted is never handed out again. A panel's names are its own whatever bot sold the service, so a
 * name is checked against every bot's services on that server. The client's comment, "<telegram id> | <username or
 * USER>" — "web#<user id> | USER" for a customer without Telegram (who signed up on the website), and the agent's bot
 * ("| @agent_bot") for a service an agent's bot sold — ties the row back to the account whatever the name says.
 */
final class ClientNaming
{
    /** Stands in for the username — in the name and in the comment — when the account has none. */
    private const ANONYMOUS = 'USER';

    /** Stands in for the Telegram id in the comment of a customer without Telegram, before their number in the shop. */
    private const WEB = 'web#';

    /** The `sequences` counter behind "USER_<n>". */
    private const ANONYMOUS_SEQUENCE = 'clients.anonymous';

    /** The `sequences` counter behind a customer's "<username>_<n>", followed by their user id. */
    private const CUSTOMER_SEQUENCE = 'clients.user.';

    public function __construct(
        private readonly Sequence $sequences,
        private readonly Bots $bots,
    ) {}

    /**
     * The next name for this customer on this server. Names are unique per panel and a username can move from one
     * Telegram account to another, so a name a service there already carries is skipped for the next number.
     */
    public function next(User $user, Server $server): string
    {
        $username = self::username($user);

        do {
            $name = $username !== null
                ? $username . '_' . $this->sequences->next(self::CUSTOMER_SEQUENCE . $user->id)
                : self::ANONYMOUS . '_' . $this->sequences->next(self::ANONYMOUS_SEQUENCE);
        } while (self::taken($server, $name));

        return $name;
    }

    /**
     * The name a service keeps on the server it moves to — unless a service there carries it already, when it gets the
     * customer's next name, as a purchase would.
     */
    public function nameOn(Subscription $subscription, Server $server): string
    {
        return self::taken($server, $subscription->remote_name) ? $this->next($subscription->user, $server) : $subscription->remote_name;
    }

    /**
     * "<telegram id> | <username>", or "… | USER" without one; "web#<user id> | USER" for a customer without Telegram —
     * and "| @<bot>" for a customer of an agent's bot.
     */
    public function comment(User $user): string
    {
        $comment = $user->telegram_id === null
            ? self::WEB . $user->id . ' | ' . self::ANONYMOUS
            : $user->telegram_id . ' | ' . (self::username($user) ?? self::ANONYMOUS);
        $shop = $user->shop();
        if ($shop->isMain()) {
            return $comment;
        }

        $bot = $this->bots->username($shop);

        return $bot !== '' ? $comment . ' | @' . $bot : $comment;
    }

    /** Whether a service on the server carries the name — any bot's: a panel's names are its own. */
    private static function taken(Server $server, string $name): bool
    {
        return $server->subscriptions()->where('remote_name', $name)->exists();
    }

    /** The bare Telegram username, null when the account shows none. */
    private static function username(User $user): ?string
    {
        return ($user->username ?? '') !== '' ? $user->username : null;
    }
}
