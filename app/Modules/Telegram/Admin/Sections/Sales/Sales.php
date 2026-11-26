<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Messages;

/**
 * What the sales screens share: the section's name and its lists' (under()), the panel's words for each state of an
 * order, a payment, a service and a bank SMS (the panel's lib/statuses.ts), each led by its chip — the one a row's
 * button starts with —, the icons of the actions they take, how a customer and whoever decided something are said, a
 * bar of what is used, and the question before a decision with more than one way to make it (with a note or without),
 * facts quoted in it.
 */
final class Sales
{
    /** The section, as the paths say it («مدیریت › فروش › …»). */
    public const TITLE = 'فروش';

    /** The section's lists, as their titles and the paths under them say them. */
    public const PAYMENTS = 'پرداخت‌ها';
    public const ORDERS = 'سفارش‌ها';
    public const SERVICES = 'اشتراک‌ها';
    public const DEPOSITS = 'واریزهای پیامکی';

    /** The customer's own screen (the Customers section's): a row's customer opens it. */
    public const CUSTOMER = 'usv';

    // The icons of what the sales screens do: one each, so no two buttons of a screen look alike.

    public const APPROVE = '✅';
    public const REJECT = '❌';
    public const CANCEL = '🚫';
    public const CHECK = '🔍';
    public const RETRY = '🔁';
    public const REMIND = '📨';
    public const REFUND = '↩️';
    public const ATTACH = '📎';
    public const FILE = '📄';
    public const NEXT = '⏭';
    public const MOVE = '🔀';
    public const HAND_OVER = '📤';
    public const EXTEND = '🎁';
    public const SYNC = '📥';
    public const CHAT = '💬';
    public const LINK = '🔗';

    public const ORDER_STATUS = [
        'pending' => '⏳ در انتظار پرداخت',
        'paid' => '🟡 پرداخت‌شده',
        'processing' => '🟡 در حال ساخت',
        'fulfilled' => '✅ تکمیل‌شده',
        'failed' => '🔴 ناموفق',
        'cancelled' => '🚫 لغوشده',
        'refunded' => '↩️ بازپرداخت‌شده',
    ];

    /** What an order was for, in the order the panel's «نوع» filter offers them. */
    public const ORDER_TYPE = [
        'purchase' => 'خرید',
        'renewal' => 'تمدید',
        'extra_traffic' => 'حجم اضافه',
        'extra_time' => 'زمان اضافه',
        'extra_devices' => 'کاربر اضافه',
        'wallet_topup' => 'شارژ کیف پول',
        'traffic' => 'خرید حجم نمایندگی',
    ];

    public const PAYMENT_STATUS = [
        'pending' => '⏳ در انتظار پرداخت',
        'awaiting_review' => '🧾 در انتظار بررسی',
        'paid' => '✅ پرداخت‌شده',
        'failed' => '🔴 ناموفق',
        'cancelled' => '🚫 لغوشده',
        'refunded' => '↩️ بازپرداخت‌شده',
    ];

    public const SUBSCRIPTION_STATUS = [
        'active' => Words::ACTIVE,
        'expired' => '⌛️ منقضی‌شده',
        'disabled' => Words::INACTIVE,
        'deleted' => '🗑 حذف‌شده',
    ];

    /** What came of a bank SMS the phone app forwarded. */
    public const DEPOSIT_STATUS = [
        'received' => '🟡 در حال بررسی',
        'matched' => '✅ تایید شده',
        'unmatched' => '❓ بدون پرداخت',
        'ambiguous' => '⚖️ چند پرداخت',
        'ignored' => '🙈 نادیده',
        'test' => '🧪 آزمایشی',
    ];

    /** Under an online payment held for support. */
    public const HELD = 'نگه داشته شده برای پشتیبانی';

    /** The chip of a payment held for support (paymentMark()), over its status's. */
    public const HELD_MARK = '✋';

    /** The longest note the panel takes with a decision (Input::NOTE_MAX). */
    public const NOTE_LIMIT = 'اختیاری؛ حداکثر ۳۰۰ نویسه';

    /** A decision with an optional note (choose()): with one — asked, then done —, or done at once. */
    public const WITH_NOTE = '✍️ بله، با توضیح';
    public const WITHOUT_NOTE = '✔️ بله، بدون توضیح';

    /** The cells of a bar (bar()). */
    private const BAR = 10;

    /** The price an order was made at, when not its plan's own. */
    private const PRICING = ['first_purchase' => 'قیمت خرید اول', 'agent' => 'قیمت نماینده', 'gift' => 'هدیه'];

    /** How an order support made by hand was paid. */
    private const MANUAL_PAYMENT = ['free' => 'رایگان', 'wallet' => 'از کیف پول مشتری', 'outside' => 'پرداخت خارج از فروشگاه'];

    /** How a renewal added its package. */
    private const RENEWAL_METHOD = ['extend' => 'تمدید پلن فعلی', 'reset_traffic' => 'ریست حجم + افزایش روز', 'reset_time' => 'ریست زمان + انتقال حجم'];

    /** Who approved a card payment a bank SMS named, and how it is said. */
    private const BANK_SMS_REVIEWER = 'پیامک بانک';

    /**
     * The path of a screen in the section: «مدیریت › فروش» and the sections `$more` under it.
     *
     * @return list<string>
     */
    public static function under(string ...$more): array
    {
        return [Words::ROOT, self::TITLE, ...array_values($more)];
    }

    /**
     * A word of `$words` for `$key` (an API value), the value itself when the panel has none.
     *
     * @param array<string, string> $words
     */
    public static function word(array $words, mixed $key): string
    {
        $key = is_scalar($key) ? (string) $key : '';

        return $words[$key] ?? $key;
    }

    /**
     * The chip a state's words start with (its emoji).
     *
     * @param array<string, string> $words
     */
    public static function mark(array $words, mixed $key): string
    {
        return strtok(self::word($words, $key), ' ') ?: '•';
    }

    /**
     * A payment's chip, as its row's button starts with it: ✋ held for support, else its status's.
     *
     * @param array<string, mixed> $payment
     */
    public static function paymentMark(array $payment): string
    {
        return Json::on($payment, 'held') ? self::HELD_MARK : self::mark(self::PAYMENT_STATUS, $payment['status'] ?? '');
    }

    /**
     * A payment as its row's button says it after its chip: the amount and the customer — what a row is looked for by
     * —, the number last.
     *
     * @param array<string, mixed> $payment
     */
    public static function paymentRow(array $payment): string
    {
        return Fmt::money(Json::text($payment, 'amount')) . ' · ' . Fmt::cut(self::customer(Json::part($payment, 'user')), 16) . ' · ' . Fmt::id((int) ($payment['id'] ?? 0));
    }

    /**
     * A customer in one line (plain text): the name, else the handle, else the Telegram id, the email or the number.
     *
     * @param array<string, mixed> $user
     */
    public static function customer(array $user): string
    {
        return Json::text($user, 'name')
            ?? (Json::text($user, 'username') !== null ? '@' . Json::text($user, 'username') : null)
            ?? Json::text($user, 'telegram_id')
            ?? Json::text($user, 'email')
            ?? '#' . (int) ($user['id'] ?? 0);
    }

    /**
     * A customer's facts as a row's card shows them: who, and their Telegram id (copyable) or their email.
     *
     * @param array<string, mixed> $user
     * @return array<string, string|null>
     */
    public static function customerFacts(array $user): array
    {
        $name = Json::text($user, 'name');
        $handle = Json::text($user, 'username');
        $who = $name !== null ? Fmt::e($name) . ($handle !== null ? ' ' . Fmt::ltr('@' . $handle) : '') : ($handle !== null ? Fmt::ltr('@' . $handle) : 'بدون نام');
        $telegram = Json::text($user, 'telegram_id');

        return [
            'مشتری' => $who,
            'شناسه تلگرام' => $telegram !== null ? Fmt::code($telegram) : null,
            'ایمیل' => $telegram === null ? (Json::text($user, 'email') !== null ? Fmt::ltr(Json::text($user, 'email')) : 'بدون ایمیل') : null,
        ];
    }

    /**
     * «👤 علی», the button to the customer's own screen, and — as a row's details offer it — «💬 گفتگو در تلگرام» to their
     * chat by their public handle (one without a handle is reached by the Telegram id shown: a link by id is refused
     * by Telegram where their privacy says so, and with it the whole screen).
     *
     * @param array<string, mixed> $user
     * @return list<Button>
     */
    public static function customerButtons(array $user): array
    {
        $handle = Json::text($user, 'username');
        $buttons = [Button::to(Icons::CUSTOMER . ' ' . self::customer($user), self::CUSTOMER, 'show', (int) ($user['id'] ?? 0))];
        if ($handle !== null) {
            $buttons[] = Button::url(self::CHAT . ' گفتگو در تلگرام', 'https://t.me/' . rawurlencode($handle));
        }

        return $buttons;
    }

    /**
     * Who decided something (a row's `reviewer`), as the panel says it: their name where the API read it, a Persian word
     * as written, an admin kept by a number in words with the number apart («کاربر #۱۲»), else the identifier left to
     * right; a card payment a bank SMS approved says so.
     */
    public static function reviewer(string $name, ?string $person): string
    {
        if ($name === self::BANK_SMS_REVIEWER) {
            return 'تایید با پیامک بانک';
        }
        if ($person !== null && $person !== '') {
            return 'توسط ' . Fmt::e($person);
        }
        if (preg_match('/\p{Arabic}/u', $name) === 1) {
            return 'توسط ' . Fmt::e($name);
        }
        foreach (['/^user#(\d+)$/' => 'کاربر #', '/^agent#(\d+)$/' => 'نماینده #', '/^tg:(\d+)$/' => 'شناسه تلگرام '] as $pattern => $word) {
            if (preg_match($pattern, $name, $match) === 1) {
                return 'توسط ' . $word . Fmt::ltr($match[1]);
            }
        }

        return 'توسط ' . Fmt::ltr($name);
    }

    /**
     * What an order brings when it is an amount (extra traffic, time or devices, an agent's traffic): its label and the
     * amount; null for any other order.
     *
     * @param array<string, mixed> $order
     * @return array{0: string, 1: string}|null
     */
    public static function brings(array $order): ?array
    {
        $bytes = isset($order['traffic_bytes']) && is_numeric($order['traffic_bytes']) ? (int) $order['traffic_bytes'] : null;
        $days = isset($order['days']) && is_numeric($order['days']) ? (int) $order['days'] : null;
        $devices = isset($order['devices']) && is_numeric($order['devices']) ? (int) $order['devices'] : null;

        return match (Json::text($order, 'type')) {
            'extra_traffic' => $bytes === null ? null : ['حجم اضافه', Fmt::bytes($bytes)],
            'extra_time' => $days === null ? null : ['زمان اضافه', $days > 0 ? Fmt::number($days) . ' روز' : 'نامحدود'],
            'extra_devices' => $devices === null ? null : ['کاربر اضافه', Messages::devices($devices)],
            'traffic' => $bytes === null ? null : ['حجم', Fmt::bytes($bytes)],
            default => null,
        };
    }

    /**
     * The price an order was made at, or — one support made by hand — how it was paid; null for its plan's own price.
     *
     * @param array<string, mixed> $order
     */
    public static function pricing(array $order): ?string
    {
        $manual = Json::part($order, 'manual');
        if ($manual !== []) {
            return 'سفارش دستی · ' . self::word(self::MANUAL_PAYMENT, $manual['payment'] ?? '');
        }

        return self::PRICING[(string) ($order['pricing'] ?? '')] ?? null;
    }

    /**
     * How a renewal added its package; null for any other order.
     *
     * @param array<string, mixed> $order
     */
    public static function renewal(array $order): ?string
    {
        $method = Json::text($order, 'renewal_method');

        return $method === null ? null : self::word(self::RENEWAL_METHOD, $method);
    }

    /**
     * The discounts an order took (HTML): the total, each line's share and the code; null for none.
     *
     * @param array<string, mixed> $order
     */
    public static function discounts(array $order): ?string
    {
        if (!is_numeric($order['discount_amount'] ?? null) || (float) $order['discount_amount'] <= 0) {
            return null;
        }
        $lines = [];
        foreach (Json::rows($order, 'discounts') as $line) {
            $label = Json::text($line, 'source') === 'personal' ? 'تخفیف اختصاصی ' . Fmt::percent(is_numeric($line['percent'] ?? null) ? $line['percent'] : 0) : (string) ($line['label'] ?? '');
            $lines[] = Fmt::e($label) . ': ' . Fmt::money(Json::text($line, 'amount'));
        }
        $code = Json::text(Json::part($order, 'discount_code'), 'code');

        return '<b>' . Fmt::money((string) $order['discount_amount']) . '</b>' . ($lines === [] ? '' : ' (' . implode('، ', $lines) . ')') . ($code !== null ? ' · ' . Fmt::code($code) : '');
    }

    /**
     * The question before a decision that may go more than one way (with a note for the customer, or without —
     * WITH_NOTE, WITHOUT_NOTE —; a move with its previous server or without): `$question`, what it does (HTML), where it
     * is asked from, then each way — two a row while their labels are short —, «✖️ انصراف» (a way labelled so) last on
     * its own. A way not given its own colour is red for a decision that destroys (`$danger`), else green. A typed note
     * is never the decision by itself: a tap here chose to write one.
     */
    public static function choose(Admin $admin, string $question, string $detail, bool $danger, ?Button ...$ways): View
    {
        $view = View::make('❓ ' . $question, ...$admin->trail())->transient();
        if ($detail !== '') {
            $view->line($detail);
        }
        $doing = [];
        $cancel = null;
        foreach ($ways as $way) {
            if ($way === null) {
                continue;
            }
            if ($way->label === Words::CANCEL) {
                $cancel = $way;

                continue;
            }
            $doing[] = $way->style === null ? ($danger ? $way->danger() : $way->success()) : $way;
        }

        return $view->tiles(...$doing)->row($cancel);
    }

    /**
     * Facts as a quote in a question's words (what it is asked of): «label: value» a line — the value HTML, left as it
     * is (a summary card's would be bold) —, a null one left out.
     *
     * @param array<string, string|null> $facts
     */
    public static function quote(array $facts): string
    {
        $lines = [];
        foreach ($facts as $label => $value) {
            if ($value !== null) {
                $lines[] = Fmt::e((string) $label) . ': ' . $value;
            }
        }

        return '<blockquote>' . implode("\n", $lines) . '</blockquote>';
    }

    /** A bar of `$part` of `$whole` used: «▰▰▰▱▱▱▱▱▱▱»; empty for no whole. */
    public static function bar(int $part, int $whole): string
    {
        if ($whole <= 0) {
            return '';
        }
        $filled = max(0, min(self::BAR, intdiv(max(0, $part) * self::BAR, $whole)));

        return str_repeat('▰', $filled) . str_repeat('▱', self::BAR - $filled);
    }

    /**
     * The servers a services screen picks from — to narrow the list by, or to move a service to —, read as the seat's
     * panel reads them: the owner's seat every server with its running services, an agent's the shop's as the plan form
     * offers them. Each with why nothing can be moved onto it now (null when it can).
     *
     * @return list<array{id: int, name: string, reason: string|null, active: int|null}>
     * @throws ApiRefusal
     */
    public static function servers(Admin $admin): array
    {
        $owner = $admin->seat->isOwner();
        $servers = Json::rows($admin->api->get($owner ? '/servers' : '/plans/options'), 'servers');

        return array_map(static fn(array $server): array => [
            'id' => (int) ($server['id'] ?? 0),
            'name' => (string) ($server['name'] ?? ''),
            'reason' => Json::text($server, 'unsellable_reason'),
            'active' => $owner && is_numeric(Json::part($server, 'counts')['active_subscriptions'] ?? null) ? (int) Json::part($server, 'counts')['active_subscriptions'] : null,
        ], $servers);
    }

    /**
     * A server's name by its id, as servers() offers it; its number when it is not among them.
     *
     * @throws ApiRefusal
     */
    public static function serverName(Admin $admin, int $id): string
    {
        foreach (self::servers($admin) as $server) {
            if ($server['id'] === $id) {
                return $server['name'];
            }
        }

        return Fmt::id($id);
    }

    /**
     * The servers a service (or several) may be moved to, as a picker's buttons — each to `$screen`'s `$action` with
     * `$args` and the server's id, its running services beside it —, and the lines of those that cannot take one now,
     * with the server's reason; the servers in `$skip` (where they are already) left out.
     *
     * @param list<int> $skip
     * @param list<int|string> $args
     * @return array{0: list<Button>, 1: list<string>}
     * @throws ApiRefusal
     */
    public static function targets(Admin $admin, array $skip, string $screen, string $action, array $args): array
    {
        $choices = [];
        $closed = [];
        foreach (self::servers($admin) as $server) {
            if (in_array($server['id'], $skip, true)) {
                continue;
            }
            if ($server['reason'] !== null) {
                $closed[] = '⛔️ ' . Fmt::e($server['name']) . ': ' . Fmt::e($server['reason']);

                continue;
            }
            $choices[] = Button::to(Icons::SERVERS . ' ' . $server['name'] . ($server['active'] !== null ? ' · ' . Fmt::number($server['active']) . ' فعال' : ''), $screen, $action, ...[...$args, $server['id']]);
        }

        return [$choices, $closed];
    }

    /** How long until a moment the panel wrote: «۳ روز مانده», «۵ ساعت مانده» within a day, «تمام شده» once past. */
    public static function left(string $iso): string
    {
        $moment = Fmt::moment($iso);
        $seconds = $moment === null ? 0 : $moment->getTimestamp() - now()->getTimestamp();

        return match (true) {
            $seconds <= 0 => 'تمام شده',
            $seconds < 86_400 => Fmt::number((int) ceil($seconds / 3_600)) . ' ساعت مانده',
            default => Fmt::number((int) ceil($seconds / 86_400)) . ' روز مانده',
        };
    }

    /**
     * A moment the panel wrote, with the hour; null for none (a fact left out).
     *
     * @param array<string, mixed> $row
     */
    public static function when(array $row, string $key): ?string
    {
        $moment = Json::text($row, $key);

        return $moment === null ? null : Fmt::date($moment, true);
    }
}
