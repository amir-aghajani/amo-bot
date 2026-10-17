<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Sequence;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\ReadableCode;
use App\Modules\Bots\Services\Bots;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\DTO\ClientNameRule;
use App\Modules\Providers\Enums\NameProblem;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Subscriptions\DTO\ClientNamePattern;
use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Persian;

/**
 * What a customer's client is called on the panel, so the panel's client list reads as people: the shop's pattern
 * (ClientNameSettings; by default "{username}_{seq}" — amir_1, amir_2 … across every server — and a free test's
 * "test_{username}_{seq}"), or for a purchase the name its buyer asked for (requested(): drawn at random, or typed).
 *
 * A variable is worked out only when the pattern holds it, so a counter moves only for a pattern that uses it: {seq}
 * is the customer's own counter, one shared by every customer without a username; {n} the shop's. Counters only go
 * up, so a deleted service's number is never reused. A panel's names are unique across every bot's services on it, so
 * a name already taken there is skipped for the next — and one only a client made on the panel by hand has, once the
 * panel refuses it (ProvisioningService::named()). Every name is one the server's panel takes (its connector's
 * Capabilities::$names): the shop's own are made to fit its rule, a typed one it would refuse is refused at the
 * checkout, and one a service carries elsewhere goes there only as it is allowed.
 *
 * The client's comment ties it back to the account: "<telegram id> | <username or USER>", "web#<user id> | USER" for a
 * customer without Telegram, plus "| @<agent bot>" for a service an agent's bot sold.
 */
final class ClientNaming
{
    /** Why a buyer's own name is refused: its way not offered (%s: the way's label), no name, or a service has it. */
    public const NOT_OFFERED = 'نام %s برای سرویس در این فروشگاه فعال نیست.';
    public const NOT_A_NAME = 'نام کاربری سرویس باید بین ۳ تا ۳۲ کاراکتر و فقط از حروف و رقم انگلیسی، _ و - باشد.';
    public const TAKEN = 'این نام روی این سرور گرفته شده است.';

    /** Why a typed name the server's panel would refuse is refused: its length there, two separators side by side, or else. */
    public const PANEL_LENGTH = 'نام کاربری سرویس روی این سرور باید بین %s تا %s کاراکتر باشد.';
    public const PANEL_RUNS = 'روی این سرور دو علامت _ یا - پشت سر هم در نام کاربری سرویس نمی‌آید.';
    public const PANEL_REFUSES = 'سرور این سرویس این نام را نمی‌پذیرد؛ نام دیگری بنویسید.';

    /** A name a buyer types: OWN_MIN to OWN_MAX letters, digits, `_` and `-`. */
    private const OWN_MIN = 3;
    private const OWN_MAX = 32;
    private const OWN = '/^[A-Za-z0-9_-]{' . self::OWN_MIN . ',' . self::OWN_MAX . '}$/';

    /** Stands in for the username, in the name and the comment, when the account has none. */
    private const ANONYMOUS = 'USER';

    /** Stands in for the Telegram id in the comment of a customer without Telegram, before their user id. */
    private const WEB = 'web#';

    /** Stands in for {tg_id} for a customer without Telegram, before their user id. */
    private const WEB_ID = 'w';

    /** The `sequences` counter behind {seq} for every customer without a username. */
    private const ANONYMOUS_SEQUENCE = 'clients.anonymous';

    /** The `sequences` counter behind a customer's own {seq}, followed by their user id. */
    private const CUSTOMER_SEQUENCE = 'clients.user.';

    /** The `sequences` counter behind {n}, followed by the shop's bot id. */
    private const SHOP_SEQUENCE = 'clients.shop.';

    /** The characters of {random}, and of a random name. */
    private const RANDOM_LENGTH = 6;

    /** Names tried before giving up — each try draws its counter or random part anew. */
    private const TRIES = 100;

    public function __construct(
        private readonly Sequence $sequences,
        private readonly Bots $bots,
        private readonly ClientNameSettings $settings,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * The next name for this customer on this server, by the shop's pattern — a `$test` account's by the test pattern. A
     * name a service there already carries (a username can move between Telegram accounts) is skipped for the next.
     *
     * @throws NoServerAvailableException when no name the pattern makes is free there
     */
    public function next(User $user, Server $server, bool $test = false): string
    {
        $pattern = $test ? $this->settings->testPattern() : $this->settings->pattern();
        $max = min(ClientNamePattern::NAME_MAX, $this->rule($server)->max ?? ClientNamePattern::NAME_MAX);

        return $this->free($server, fn(): string => $this->render($pattern, $user, $max), "نام آزادی برای کلاینت روی «{$server->name}» پیدا نشد؛ الگوی نام کاربری سرویس را در تنظیمات ربات بررسی کنید.");
    }

    /**
     * The name a purchase's service gets on `$server`: the one its buyer asked for (Order::$client_name) while the
     * server's panel takes it, no service there carries it and the panel — asked last, through `$onPanel` — has no client
     * by it either (one made by hand); else the pattern's next. Of two orders asking one name delivered at the same
     * moment, the panel refuses the second's client, and ProvisioningService::named() names it by the pattern then.
     *
     * @param \Closure(string): bool $onPanel Whether the panel has a client by that name
     * @throws NoServerAvailableException
     */
    public function forPurchase(Order $order, Server $server, \Closure $onPanel): string
    {
        $asked = $order->client_name;
        if ($asked !== null && $this->rule($server)->allows($asked) && !self::taken($server, $asked) && !$onPanel($asked)) {
            return $asked;
        }

        return $this->next($order->user, $server);
    }

    /**
     * The name a buyer asks for their new service on `$server`: null for the shop's pattern; a random name — drawn now,
     * or worked out from `$seed` (a request's Idempotency-Key, so two sent with one key at once ask the same name); or
     * the one they typed, while the server's panel takes it as typed and no service on the server carries it. A way the
     * shop does not offer is refused.
     *
     * @throws ValidationException 422 on `name_mode` for a way not offered; on `client_name` for a typed name refused
     * @throws NoServerAvailableException when no random name is free there
     */
    public function requested(ClientNameMode $mode, ?string $typed, Server $server, ?string $seed = null): ?string
    {
        if (!in_array($mode, $this->settings->modes(), true)) {
            throw ValidationException::on('name_mode', sprintf(self::NOT_OFFERED, $mode->label()));
        }

        return match ($mode) {
            ClientNameMode::Sequential => null,
            ClientNameMode::Random => $this->randomOn($server, $seed),
            ClientNameMode::Custom => $this->typed((string) $typed, $server),
        };
    }

    /**
     * The name support typed for a service it makes for a customer (Orders\Services\ManualOrders), whichever ways the
     * shop offers its buyers: held to a buyer's typed name's rules — one, the server's panel takes it as typed, and no
     * service on the server carries it.
     *
     * @throws ValidationException 422 on `client_name`
     */
    public function chosen(string $typed, Server $server): string
    {
        return $this->typed($typed, $server);
    }

    /**
     * The name a service keeps on the server it moves to — its own, unless a service there carries it or that server's
     * panel would refuse it (a name its old panel took), then the customer's next (a test's by the test pattern). A
     * client made there by hand under it makes the panel refuse it: ProvisioningService::named() takes the next then.
     *
     * @throws NoServerAvailableException
     */
    public function nameOn(Subscription $subscription, Server $server): string
    {
        $own = $subscription->remote_name;

        return $this->rule($server)->allows($own) && !self::taken($server, $own) ? $own : $this->next($subscription->user, $server, $subscription->is_test);
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

    /**
     * The pattern's name for this customer, `$max` characters at most: a long username gives way first, never the part
     * that tells the names apart.
     */
    private function render(ClientNamePattern $pattern, User $user, int $max): string
    {
        $username = self::username($user);
        $values = array_map(self::once(...), [
            'username' => static fn(): string => $username ?? self::ANONYMOUS,
            'seq' => fn(): string => (string) $this->sequences->next($username !== null ? self::CUSTOMER_SEQUENCE . $user->id : self::ANONYMOUS_SEQUENCE),
            'tg_id' => static fn(): string => $user->telegram_id !== null ? (string) $user->telegram_id : self::WEB_ID . $user->id,
            'user_id' => static fn(): string => (string) $user->id,
            'n' => fn(): string => (string) $this->sequences->next(self::SHOP_SEQUENCE . $user->shop()->id),
            'random' => static fn(): string => self::random(),
            'date' => static fn(): string => ClientNamePattern::date(now()),
        ]);
        $name = $pattern->render($values);

        $over = strlen($name) - $max;
        if ($over > 0 && $username !== null && $pattern->holds('username')) {
            $short = substr($username, 0, max(1, strlen($username) - (int) ceil($over / substr_count($pattern->pattern, '{username}'))));
            $name = $pattern->render(['username' => static fn(): string => $short] + $values);
        }

        return substr($name, 0, $max);
    }

    /**
     * A random name no service on the server carries — each try's drawn, or worked out from `$seed` and the try —, made
     * to fit the server's panel.
     *
     * @throws NoServerAvailableException
     */
    private function randomOn(Server $server, ?string $seed): string
    {
        return $this->free($server, static fn(int $try): string => self::random($seed === null ? null : $seed . '|' . $try), "نام تصادفی آزادی روی «{$server->name}» پیدا نشد.");
    }

    /**
     * The first name `$draw` makes for a try — TRIES at most, each made to fit the server's panel — that no service on
     * the server carries.
     *
     * @param \Closure(int): string $draw
     * @throws NoServerAvailableException `$none` once every try was taken
     */
    private function free(Server $server, \Closure $draw, string $none): string
    {
        $rule = $this->rule($server);
        for ($try = 0; $try < self::TRIES; $try++) {
            $name = $rule->fit($draw($try));
            if (!self::taken($server, $name)) {
                return $name;
            }
        }

        throw new NoServerAvailableException($none);
    }

    /**
     * A name the buyer typed — trimmed, its Persian or Arabic digits Latin (a Persian keyboard types those) —, while it is
     * one, the server's panel takes it as it is, and no service on the server carries it.
     *
     * @throws ValidationException 422 on `client_name`
     */
    private function typed(string $typed, Server $server): string
    {
        $name = Persian::latinDigits(trim($typed));
        if (preg_match(self::OWN, $name) !== 1) {
            throw ValidationException::on('client_name', self::NOT_A_NAME);
        }
        $rule = $this->rule($server);
        $refused = match ($rule->problem($name)) {
            null => null,
            NameProblem::Length => sprintf(self::PANEL_LENGTH, Persian::number(max(self::OWN_MIN, $rule->min)), Persian::number(min(self::OWN_MAX, $rule->max ?? self::OWN_MAX))),
            NameProblem::Runs => self::PANEL_RUNS,
            NameProblem::Characters => self::PANEL_REFUSES,
        };
        if ($refused !== null) {
            throw ValidationException::on('client_name', $refused);
        }
        if (self::taken($server, $name)) {
            throw ValidationException::on('client_name', self::TAKEN);
        }

        return $name;
    }

    /** What the server's panel takes as a client's name. */
    private function rule(Server $server): ClientNameRule
    {
        return $this->providers->capabilities($server->driver)->names;
    }

    /** RANDOM_LENGTH lowercase letters and digits without look-alikes (ReadableCode's): drawn, or the same for one `$seed`. */
    private static function random(?string $seed = null): string
    {
        if ($seed === null) {
            return strtolower(ReadableCode::make(self::RANDOM_LENGTH));
        }
        $bytes = hash('sha256', $seed, true);
        $name = '';
        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $name .= ReadableCode::ALPHABET[ord($bytes[$i]) % strlen(ReadableCode::ALPHABET)];
        }

        return strtolower($name);
    }

    /**
     * `$value` worked out once, however often the name is rendered.
     *
     * @param \Closure(): string $value
     * @return \Closure(): string
     */
    private static function once(\Closure $value): \Closure
    {
        $kept = null;

        return static function () use ($value, &$kept): string {
            return $kept ??= $value();
        };
    }

    /** Whether any bot's service on the server carries the name. */
    private static function taken(Server $server, string $name): bool
    {
        return $server->subscriptions()->where('remote_name', $name)->exists();
    }

    /** The bare Telegram username, of the characters a name takes; null when the account shows none. */
    private static function username(User $user): ?string
    {
        $username = (string) preg_replace('/[^A-Za-z0-9_]/', '', $user->handle() ?? '');

        return $username !== '' ? $username : null;
    }
}
