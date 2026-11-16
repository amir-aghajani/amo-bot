<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Messages;
use App\Support\Persian;

/**
 * What the customer screens share: a customer's page read from the panel (GET /users/{id}), how one is named — the
 * panel's one line: the name, else the handle, else the account — and marked, the customer the screens last opened, the
 * admin's groups, the path the screens are under, why a seat may only look at an account (an admin's of the bot, or an
 * agent's: the shop's owner alone changes them), and the Idempotency-Key of a change written in steps.
 */
final class Customer
{
    /** Why the shop's admins only look at a bot admin's account (UserActions refuses them every change of it). */
    public const ADMIN_LOCK = '🔒 حساب یکی از مدیران فروشگاه است؛ فقط مالک فروشگاه آن را تغییر می‌دهد.';

    /** …and an agent's. */
    public const AGENT_LOCK = '🔒 حساب نماینده است؛ فقط مالک فروشگاه آن را تغییر می‌دهد.';

    /** A banned customer's mark: their row's, the ban's button. */
    public const BANNED_MARK = '⛔️';

    /** A banned customer's state in words. */
    public const BANNED = self::BANNED_MARK . ' مسدود';

    /** The bot admin role in words. */
    public const BOT_ADMIN = Icons::BOT_ADMINS . ' مدیر ربات';

    /** An agent's role in words. */
    public const AGENT = Icons::AGENTS . ' نماینده';

    /** The longest name a list's row shows, its fact beside it kept whole. */
    private const NAME_MOST = 24;

    /**
     * The sections the customer screens are under, then `$more`: «مدیریت › فروش › کاربران › …».
     *
     * @return list<string>
     */
    public static function under(string ...$more): array
    {
        return [Words::ROOT, Sales::TITLE, Fmt::bare(UsersScreen::TITLE), ...array_values($more)];
    }

    /**
     * The customer's page as the panel answers it — `user` (the users table's row), `referral`, `agency`, `account`,
     * `loyalty` —, the customer remembered as the one the screens are on.
     *
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public static function read(Admin $admin, int $id): array
    {
        $detail = $admin->api->get('/users/' . $id);
        self::opened($admin, self::row($detail));

        return $detail;
    }

    /**
     * The customer the screens are on from now: their page's «⬅️ بازگشت» finds them, and their name names them.
     *
     * @param array<string, mixed> $user
     */
    public static function opened(Admin $admin, array $user): void
    {
        if (is_int($user['id'] ?? null)) {
            $admin->remember(CustomerScreen::ID, ['id' => $user['id'], 'name' => self::name($user)]);
        }
    }

    /**
     * The customer `$args` names first, else the one the screens were last on; 0 for none.
     *
     * @param list<string> $args
     */
    public static function current(Admin $admin, array $args = []): int
    {
        if (isset($args[0]) && ctype_digit($args[0]) && (int) $args[0] > 0) {
            return (int) $args[0];
        }
        $id = $admin->state(CustomerScreen::ID)['id'] ?? 0;

        return is_int($id) ? $id : 0;
    }

    /** What names customer `$id` on a screen's path: their name as last read, else their number. */
    public static function known(Admin $admin, int $id): string
    {
        $state = $admin->state(CustomerScreen::ID);

        return ($state['id'] ?? null) === $id && is_string($state['name'] ?? null) ? $state['name'] : Fmt::id($id);
    }

    /**
     * The users table's row of a page the panel answered.
     *
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public static function row(array $detail): array
    {
        return Json::part($detail, 'user');
    }

    /**
     * The panel's one line for a customer — the name, else @handle, else their Telegram id, email or number —, isolated
     * so a Latin name keeps its order in a Persian line (plain text).
     *
     * @param array<string, mixed> $user
     */
    public static function name(array $user): string
    {
        return self::isolate(self::bare($user));
    }

    /**
     * A customer as a list's button: their mark (⛔️ banned, 🛡 a bot admin), who, and the one fact the list is ordered
     * by — `$fact` one of UsersScreen::SORTS' keys: when last seen, orders, active services; else their wallet.
     *
     * @param array<string, mixed> $user
     */
    public static function label(array $user, string $fact = 'balance'): string
    {
        $mark = match (true) {
            ($user['status'] ?? null) === 'banned' => self::BANNED_MARK,
            ($user['role'] ?? null) === 'admin' => Icons::BOT_ADMINS,
            default => Icons::CUSTOMER,
        };
        $counts = Json::part($user, 'counts');
        $seen = self::text($user, 'last_seen_at');
        $value = match ($fact) {
            'last_seen' => $seen === null ? 'بدون بازدید' : Fmt::plain(Fmt::date($seen)),
            'orders' => Persian::number(self::int($counts, 'orders')) . ' سفارش',
            'services' => Persian::number(self::int($counts, 'active_subscriptions')) . ' سرویس فعال',
            default => Messages::balance(self::money($user['balance'] ?? null)),
        };

        return $mark . ' ' . self::isolate(Fmt::cut(self::bare($user), self::NAME_MOST)) . ' · ' . $value;
    }

    /**
     * Why `$admin`'s seat may only look at this customer's account: one of the shop's admins over a bot admin's account or
     * an agent's (the panel's rule for them, UserActions); null when it may change it.
     *
     * @param array<string, mixed> $detail
     */
    public static function lock(Admin $admin, array $detail): ?string
    {
        if (!$admin->seat->isStaff()) {
            return null;
        }

        return match (true) {
            (self::row($detail)['role'] ?? null) === 'admin' => self::ADMIN_LOCK,
            is_array($detail['agency'] ?? null) => self::AGENT_LOCK,
            default => null,
        };
    }

    /** Whether the customer is the seat's own account: one of the shop's admins never adjusts their own wallet nor orders for themselves. */
    public static function own(Admin $admin, int $id): bool
    {
        return $admin->seat->isStaff() && $admin->seat->user->id === $id;
    }

    /**
     * The admin's customer groups in their order (GET /customer-groups: every one at once).
     *
     * @return list<array<string, mixed>>
     * @throws ApiRefusal
     */
    public static function groups(Admin $admin): array
    {
        return Json::rows($admin->api->get('/customer-groups'), 'groups');
    }

    /**
     * The ids of rows the panel answered (groups), in their order.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    public static function ids(array $rows): array
    {
        return array_map(static fn(array $row): int => self::int($row, 'id'), $rows);
    }

    /**
     * How many customers a group holds.
     *
     * @param array<string, mixed> $group
     */
    public static function members(array $group): int
    {
        return self::int(Json::part($group, 'counts'), 'users');
    }

    /**
     * The Idempotency-Key a change written in steps goes under (a wallet's, an order by hand): its draft's `nonce` and the
     * very values sent — sent twice, one change; the draft's values changed, another.
     *
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $body
     */
    public static function requestKey(array $draft, array $body): string
    {
        return 'bot-' . (string) ($draft['nonce'] ?? '') . '-' . substr(sha1((string) json_encode($body)), 0, 16);
    }

    /** Plain text kept in its own direction inside a Persian line: a Latin name, a handle, an email. */
    public static function isolate(string $text): string
    {
        return "\u{2068}" . $text . "\u{2069}";
    }

    /** The panel's decimal string for an amount, "0" for none. */
    public static function money(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '0';
    }

    /**
     * The customer's wallet balance in an answer of the panel's that carries their row (`user`: their page, their
     * wallet), as its decimal string.
     *
     * @param array<string, mixed> $answer
     */
    public static function balance(array $answer): string
    {
        return self::money(self::row($answer)['balance'] ?? null);
    }

    /** A plan's or a service's traffic in gigabytes: "۳۰ گیگابایت", «نامحدود» for 0. */
    public static function gb(int|float $gb): string
    {
        return $gb > 0 ? Persian::number($gb) . ' گیگابایت' : Messages::traffic(0);
    }

    /**
     * A value the panel answered as text, or null (Json::text()).
     *
     * @param array<string, mixed> $row
     */
    public static function text(array $row, string $key): ?string
    {
        return Json::text($row, $key);
    }

    /**
     * A whole number the panel answered, or 0.
     *
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        return is_int($row[$key] ?? null) ? $row[$key] : 0;
    }

    /**
     * The panel's one line for a customer, not isolated.
     *
     * @param array<string, mixed> $user
     */
    private static function bare(array $user): string
    {
        return match (true) {
            is_string($user['name'] ?? null) && $user['name'] !== '' => $user['name'],
            is_string($user['username'] ?? null) && $user['username'] !== '' => '@' . $user['username'],
            is_int($user['telegram_id'] ?? null) => (string) $user['telegram_id'],
            is_string($user['email'] ?? null) && $user['email'] !== '' => $user['email'],
            default => '#' . (int) ($user['id'] ?? 0),
        };
    }
}
