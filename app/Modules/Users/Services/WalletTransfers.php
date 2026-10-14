<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Database\Ledger;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Bots\CurrentBot;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransfer;
use App\Support\Input;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Text;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * «انتقال موجودی»: money a customer sends from their wallet to another customer of their shop, found by their numeric
 * Telegram id — in the bot or on the website, once they confirmed whom and how much, while the shop lets them
 * (WalletTransferSettings). No fee: the recipient gets what the sender gave. One rule for both doors:
 * - the amount is whole Toman within the shop's bounds, at most the sender's own balance — never an agent's credit —,
 *   and with what they sent today on the shop's clock (LocalTime) within the daily cap, when the shop sets one;
 * - the recipient is a customer of the same shop (CustomerLookup), not the sender, not banned;
 * - a customer is rate-limited: their lookups with every other (CustomerLookup), TRANSFERS sent per TRANSFER_WINDOW.
 * Refusals are 422s worded for the customer: the recipient under `telegram_id`, the money under `amount`, the shop
 * offering none under `status`.
 *
 * One transaction moves it: both customers' rows locked in id order (as Core\Database\Ledger::merge() locks two), the
 * daily cap and the balance read under them, the sender's debit and the recipient's credit (WalletService, each line
 * naming the other customer), the transfer's row (what the daily cap sums, and what a panel tells each line's other
 * customer by) and the report group's line (ShopReports::walletTransferred(), «شارژ کیف پول») — tried again on a deadlock
 * (Core\Database\Ledger::ATTEMPTS: two customers' transfers at once lock the same index gaps). After the commit both are
 * told (CustomerNotifier): the recipient, and the sender among their website's notices — in their chat too when they
 * sent it from the website, the bot having answered them in place. A website request's Idempotency-Key is kept with
 * it: the same request again is answered by the transfer it made, never a second.
 */
final class WalletTransfers
{
    /** Transfers a customer may send per TRANSFER_WINDOW seconds. */
    public const TRANSFERS = 10;
    public const TRANSFER_WINDOW = 3600;

    public const OFF = 'انتقال موجودی در این فروشگاه فعال نیست.';
    public const TO_THEMSELVES = 'موجودی را نمی‌توانید به حساب خودتان منتقل کنید.';
    public const BANNED = 'این حساب مسدود است و موجودی به آن منتقل نمی‌شود.';
    public const BELOW = 'کمترین مبلغ انتقال %s است.';
    public const ABOVE = 'بیشترین مبلغ هر انتقال %s است.';
    public const TOO_LITTLE = 'برای انتقال، دست‌کم %s موجودی در کیف پول لازم است.';
    public const SHORT = 'موجودی کیف پول شما (%s) برای این انتقال کافی نیست.';
    /** After TOO_LITTLE or SHORT for an agent: their credit pays for what they buy, and is never sent. */
    public const NOT_CREDIT = ' اعتبار نمایندگی منتقل نمی‌شود.';
    public const DAILY = 'سقف انتقال روزانه %s است و امروز تا %s دیگر می‌توانید منتقل کنید.';
    public const DAILY_SPENT = 'سقف انتقال روزانه شما (%s) امروز پر شده است؛ فردا دوباره امتحان کنید.';
    public const KEY_REUSED = 'این Idempotency-Key پیش‌تر برای انتقال دیگری به کار رفته است.';

    /** The two ledger lines, each naming the other customer (User::shownAs()). */
    public const LINE_SENT = 'انتقال موجودی به %s';
    public const LINE_RECEIVED = 'دریافت موجودی از %s';

    /** The refusal past the limit; TooManyAttemptsException::wait() adds the wait. */
    private const TOO_MANY_TRANSFERS = 'انتقال‌های زیادی انجام داده‌اید';

    /** The most of a ledger line the other customer takes, well under the description's 255 characters. */
    private const PARTY_MAX = 120;

    public function __construct(
        private readonly WalletTransferSettings $settings,
        private readonly WalletService $wallet,
        private readonly CustomerLookup $lookup,
        private readonly CustomerNotifier $notifier,
        private readonly ShopReports $reports,
        private readonly RateLimiter $limiter,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether the shop lets its customers send money now: the wallet's button and the website's `transfer`. */
    public function offered(): bool
    {
        return $this->settings->enabled();
    }

    /** @return array{string, string} The least and the most one transfer moves, in Toman. */
    public function bounds(): array
    {
        [$min, $max] = $this->settings->bounds();

        return [Money::normalize($min), Money::normalize($max)];
    }

    /** The shop's daily cap, in Toman; null when it sets none. */
    public function dailyCap(): ?string
    {
        $cap = $this->settings->dailyCap();

        return $cap === 0 ? null : Money::normalize($cap);
    }

    /** What the daily cap leaves `$from` to send today, on the shop's clock; null when the shop sets no cap. */
    public function leftToday(User $from): ?string
    {
        $cap = $this->dailyCap();

        return $cap === null ? null : Money::atLeastZero(Money::subtract($cap, $this->sentToday($from)));
    }

    /** The most `$from` may send now: the shop's most, their own balance (never an agent's credit), today's cap left. */
    public function most(User $from): string
    {
        [, $most] = $this->bounds();
        foreach ([Money::atLeastZero($from->balance()), $this->leftToday($from)] as $limit) {
            if ($limit !== null && Money::compare($limit, $most) < 0) {
                $most = $limit;
            }
        }

        return $most;
    }

    /** Whether `$from` may send `$amount` now, as far as can be told before it is done: at least the least, at most most(). */
    public function allows(User $from, int|string $amount): bool
    {
        [$min] = $this->bounds();

        return Money::compare($amount, $min) >= 0 && Money::compare($amount, $this->most($from)) <= 0;
    }

    /**
     * Refuse unless `$from` may send someone the least now — asked before whom to (recipient() asks it before any id is
     * looked up), and again at each amount the bot reads.
     *
     * @throws ValidationException 422 on `status` while the shop offers none; on `amount` when today's cap or their
     *                             balance leaves them less than the least
     */
    public function check(User $from): void
    {
        $this->refuseOff();
        [$min] = $this->bounds();
        $left = $this->leftToday($from);
        if ($left !== null && Money::compare($left, $min) < 0) {
            throw ValidationException::on('amount', sprintf(self::DAILY_SPENT, Money::format((string) $this->dailyCap())));
        }
        if (Money::compare($from->balance(), $min) < 0) {
            throw ValidationException::on('amount', sprintf(self::TOO_LITTLE, Money::format($min)) . self::notCredit($from));
        }
    }

    /**
     * The customer of `$from`'s shop with Telegram id `$telegramId` (as typed: Persian digits are fine) who may receive
     * their money — what the bot and the website show to confirm —, looked up only while `$from` may send someone the
     * least (check()): a customer with nothing to send learns no customer's name. Counted with their every lookup
     * (CustomerLookup).
     *
     * @throws ValidationException 422 on `status` while the shop offers none, on `amount` while they may send nothing
     *                             (check()), or on `telegram_id`
     * @throws TooManyAttemptsException 429 they looked up CustomerLookup::LOOKUPS in the window
     */
    public function recipient(User $from, mixed $telegramId): User
    {
        $this->check($from);
        $to = $this->lookup->find($from->shop(), $telegramId, $from->id);
        self::receives($from, $to);

        return $to;
    }

    /**
     * The website's door, which confirms nothing first: the request its Idempotency-Key `$key` made before is answered
     * by the transfer it made, whatever changed since — never a second —, one sent with another recipient or amount
     * refused; otherwise recipient(), then transfer().
     *
     * @throws ValidationException 422 on `status` while the shop offers none, or on `telegram_id`, `amount` or
     *                             `idempotency_key`
     * @throws TooManyAttemptsException 429
     */
    public function transferById(User $from, mixed $telegramId, mixed $amount, string $key): WalletTransfer
    {
        $earlier = $this->earlier($from, $key);
        if ($earlier !== null) {
            return self::same($earlier, Input::telegramIdOf($telegramId), Input::amountOf($amount));
        }

        return $this->transfer($from, $this->recipient($from, $telegramId), $amount, $key);
    }

    /**
     * Send `$amount` (whole Toman, as typed or as the API writes it) from `$from`'s wallet to `$to`'s by the rules above,
     * in one transaction; then both told. `$key` is the website request's Idempotency-Key — null from the bot, which
     * answered the sender in place. Returns the transfer, its `sender` and `recipient` as they were locked; with a key,
     * the one it made before when another request with it got there first.
     *
     * @throws ValidationException 422 on `status` while the shop offers none, or on `telegram_id`, `amount` or
     *                             `idempotency_key`
     * @throws TooManyAttemptsException 429 the sender sent TRANSFERS in the window
     */
    public function transfer(User $from, User $to, mixed $amount, ?string $key = null): WalletTransfer
    {
        $this->refuseOff();
        self::receives($from, $to);
        $amount = $this->amountOf($amount);
        $this->limiter->enforce([['wallet|transfer|' . $from->id, self::TRANSFERS, self::TRANSFER_WINDOW]], self::TOO_MANY_TRANSFERS);

        [$transfer, $made] = $this->db->transaction(function () use ($from, $to, $amount, $key): array {
            // In id order: two customers sending each other money at once wait in turn, never on each other.
            $locked = User::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereKey([$from->id, $to->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sender = $locked->get($from->id) ?? throw (new ModelNotFoundException())->setModel(User::class, [$from->id]);
            $recipient = $locked->get($to->id) ?? throw ValidationException::on('telegram_id', CustomerLookup::UNKNOWN);
            self::receives($sender, $recipient);

            // Another request with the key got here first, its transfer committed before the lock was ours.
            $earlier = $key === null ? null : $this->earlier($sender, $key, lock: true);
            if ($earlier !== null) {
                return [self::same($earlier, $recipient->telegram_id, $amount), false];
            }

            $this->refuseOverDailyCap($sender, $amount);
            try {
                $debit = $this->wallet->debit($sender, $amount, self::line(self::LINE_SENT, $recipient));
            } catch (InsufficientBalanceException $e) {
                throw ValidationException::on('amount', sprintf(self::SHORT, Money::format(Money::atLeastZero($e->balance))) . self::notCredit($sender));
            }
            $credit = $this->wallet->credit($recipient, $amount, self::line(self::LINE_RECEIVED, $sender));

            $transfer = WalletTransfer::query()->forceCreate([
                'bot_id' => $sender->bot_id,
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'amount' => $amount,
                'debit_id' => $debit->id,
                'credit_id' => $credit->id,
                'request_key' => $key,
            ]);
            $transfer->setRelation('sender', $sender)->setRelation('recipient', $recipient);
            $this->reports->walletTransferred($transfer);

            return [$transfer, true];
        }, Ledger::ATTEMPTS);
        if (!$made) {
            return $transfer;
        }

        $this->logger->info('User {from} sent {amount} from their wallet to user {to} (wallet transfer #{id})', [
            'from' => $transfer->sender_id,
            'to' => $transfer->recipient_id,
            'amount' => $transfer->amount,
            'id' => $transfer->id,
        ]);
        $this->notifier->walletReceived($transfer);
        $this->notifier->walletSent($transfer, answered: $key === null);

        return $transfer;
    }

    /**
     * The amount as the rules take it: whole Toman within the shop's bounds.
     *
     * @throws ValidationException 422 on `amount`
     */
    private function amountOf(mixed $amount): string
    {
        $whole = Input::amountOf($amount);
        if ($whole === null || $whole <= 0) {
            throw ValidationException::on('amount', Validation::notWholeToman('مبلغ'));
        }
        [$min, $max] = $this->bounds();
        if (Money::compare($whole, $min) < 0) {
            throw ValidationException::on('amount', sprintf(self::BELOW, Money::format($min)));
        }
        if (Money::compare($whole, $max) > 0) {
            throw ValidationException::on('amount', sprintf(self::ABOVE, Money::format($max)));
        }

        return Money::normalize($whole);
    }

    /**
     * Refuse what would take the sender past the daily cap — read under their row's lock, so two transfers at once are
     * summed one after the other.
     *
     * @throws ValidationException 422 on `amount`
     */
    private function refuseOverDailyCap(User $sender, string $amount): void
    {
        $cap = $this->dailyCap();
        if ($cap === null) {
            return;
        }

        $left = Money::subtract($cap, $this->sentToday($sender, lock: true));
        if (Money::compare($amount, $left) > 0) {
            [$min] = $this->bounds();
            throw ValidationException::on('amount', Money::compare($left, $min) < 0
                ? sprintf(self::DAILY_SPENT, Money::format($cap))
                : sprintf(self::DAILY, Money::format($cap), Money::format($left)));
        }
    }

    /** What `$from` sent since today began on the shop's clock (a locking read under their row's lock, for the cap). */
    private function sentToday(User $from, bool $lock = false): string
    {
        $today = WalletTransfer::query()
            ->withoutGlobalScope(CurrentBot::SCOPE)
            ->where('sender_id', $from->id)
            ->where('created_at', '>=', LocalTime::midnight());

        return Money::normalize((string) ($lock ? $today->lockForUpdate() : $today)->sum('amount'));
    }

    /** The transfer `$from` made with the website's key `$key`, if any. */
    private function earlier(User $from, string $key, bool $lock = false): ?WalletTransfer
    {
        $made = WalletTransfer::query()->withoutGlobalScope(CurrentBot::SCOPE)->with('recipient')->where('sender_id', $from->id)->where('request_key', $key);

        return ($lock ? $made->lockForUpdate() : $made)->first();
    }

    /**
     * The transfer a key made, answering the same request again: to the same Telegram id, the same amount.
     *
     * @throws ValidationException 422 on `idempotency_key`: the key was sent with another recipient or amount
     */
    private static function same(WalletTransfer $earlier, ?int $telegramId, int|string|null $amount): WalletTransfer
    {
        if ($telegramId === null || $telegramId !== $earlier->recipient->telegram_id || $amount === null || Money::compare($amount, $earlier->amount) !== 0) {
            throw ValidationException::on('idempotency_key', self::KEY_REUSED);
        }

        return $earlier;
    }

    /**
     * Refuse a recipient money may not go to: a customer of another shop, the sender, a banned account.
     *
     * @throws ValidationException 422 on `telegram_id`
     */
    private static function receives(User $from, User $to): void
    {
        $refusal = match (true) {
            (int) $to->bot_id !== (int) $from->bot_id => CustomerLookup::UNKNOWN,
            (int) $to->id === (int) $from->id => self::TO_THEMSELVES,
            $to->isBanned() => self::BANNED,
            default => null,
        };
        if ($refusal !== null) {
            throw ValidationException::on('telegram_id', $refusal);
        }
    }

    /** @throws ValidationException 422 while the shop does not let its customers send money */
    private function refuseOff(): void
    {
        if (!$this->offered()) {
            throw ValidationException::on('status', self::OFF);
        }
    }

    /** A ledger line naming the other customer: «انتقال موجودی به Sara Ahmadi (@sara)». */
    private static function line(string $line, User $other): string
    {
        return sprintf($line, Text::fit($other->shownAs(), self::PARTY_MAX));
    }

    /** NOT_CREDIT for an agent with credit, whose refusal would otherwise read as if the credit were short. */
    private static function notCredit(User $user): string
    {
        return Money::isPositive($user->credit()) ? self::NOT_CREDIT : '';
    }
}
