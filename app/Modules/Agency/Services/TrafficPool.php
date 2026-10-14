<?php

declare(strict_types=1);

namespace App\Modules\Agency\Services;

use App\Core\Database\Ledger;
use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\Enums\TrafficTransactionType;
use App\Modules\Agency\Exceptions\TrafficShortException;
use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Bots\Models\Bot;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Messages;

/**
 * An agent's prepaid traffic: the one writer of `traffic_transactions` (through Core\Database\Ledger), whose last line
 * is what their bot may still sell. Bought from the main bot (add(), taken back by a refund); drawn by every delivery
 * of their bot, once per order, and given back if it fails or is refunded (draw(), giveBack()); drawn for an extension a
 * shop gives one of their services (extending()) and for a free test account their bot makes (drawTest()); set right by
 * the owner (adjust()). The balance never goes below zero: what it cannot cover fails with TrafficShortException.
 */
final class TrafficPool
{
    public const LINE_PURCHASE = 'خرید %s · سفارش #%d';
    public const LINE_SALE = 'فروش · سفارش #%d';
    public const LINE_RENEWAL = 'تمدید · سفارش #%d';
    public const LINE_EXTRA = 'حجم اضافه · سفارش #%d';
    public const LINE_GIVEN_BACK = 'بازگشت حجم سفارش ناموفق #%d';
    public const LINE_REFUNDED = 'بازگشت حجم سفارش بازپرداخت‌شده #%d';
    public const LINE_TAKEN_BACK = 'برگشت خرید حجم · سفارش #%d';
    /** The service's panel name; the panels put the line's kind («افزایش حجم», «بازگشت حجم») before it. */
    public const LINE_EXTENSION = 'سرویس %s';
    public const LINE_EXTENSION_GIVEN_BACK = 'افزایش ناموفق · سرویس %s';
    /** A free test account the agent's bot made: the service's panel name. */
    public const LINE_TEST = 'اکانت تست · سرویس %s';
    /** An adjustment the shop wrote no note for. */
    public const LINE_ADJUSTED = 'اصلاح توسط پشتیبانی';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Reviewers $reviewers,
    ) {}

    /**
     * What an order of an agent's bot draws: extra traffic's own bytes, else the traffic of the package it gives, as its
     * delivery reads it (ProvisioningService): the order's own (Order::package(): its plan's, or one built), a renewal
     * of none its service's plan's.
     */
    public static function bytesFor(Order $order): int
    {
        if ($order->type === OrderType::ExtraTraffic) {
            return (int) $order->traffic_bytes;
        }

        return ($order->package() ?? $order->subscription?->plan?->package())->bytes ?? 0;
    }

    /** Adds traffic the agent bought (its traffic order delivered), in the caller's transaction when there is one. */
    public function add(Bot $bot, int $bytes, ?Order $order = null): void
    {
        $description = $order !== null ? sprintf(self::LINE_PURCHASE, Messages::bytes($bytes), $order->id) : null;

        $this->write($bot, static fn(): array => [TrafficTransactionType::Purchase, $bytes, $order, $description]);
    }

    /** Takes back what a refunded traffic order added, in the caller's transaction; false when it was sold since. */
    public function takeBack(Order $order, Bot $bot): bool
    {
        $bytes = (int) $order->traffic_bytes;

        return $this->write($bot, static fn(int $balance): ?array => $balance < $bytes ? null : [TrafficTransactionType::Refund, -$bytes, $order, sprintf(self::LINE_TAKEN_BACK, $order->id)]) !== null;
    }

    /**
     * Adds (or, negative, removes) `$bytes` with the reason the agent reads, under the actor's name.
     *
     * @throws ValidationException 422 on `gb` when it would take the pool below zero
     */
    public function adjust(Bot $bot, Actor $actor, int $bytes, ?string $description): void
    {
        $this->write($bot, static function (int $balance) use ($bytes, $description): array {
            if ($balance + $bytes < 0) {
                throw ValidationException::on('gb', 'حجم باقی‌مانده نماینده از این کمتر است.');
            }

            return [TrafficTransactionType::Adjust, $bytes, null, $description ?? self::LINE_ADJUSTED];
        }, $actor->reviewer);
    }

    /**
     * Draws the order's traffic from its bot's pool — once: an order whose traffic is already out (a delivery resumed
     * after its process died) draws nothing more.
     *
     * @throws TrafficShortException when the pool does not have it (Bot::trafficRefusal())
     */
    public function draw(Order $order, Bot $bot): void
    {
        $bytes = self::bytesFor($order);
        $this->write($bot, static function (int $balance) use ($order, $bytes): ?array {
            if (self::netFor($order) < 0) {
                return null;
            }
            $refusal = Bot::trafficRefusal($bytes, $balance);
            if ($refusal !== null) {
                throw new TrafficShortException($refusal);
            }

            return match ($order->type) {
                OrderType::Renewal => [TrafficTransactionType::Renewal, -$bytes, $order, sprintf(self::LINE_RENEWAL, $order->id)],
                OrderType::ExtraTraffic => [TrafficTransactionType::Sale, -$bytes, $order, sprintf(self::LINE_EXTRA, $order->id)],
                default => [TrafficTransactionType::Sale, -$bytes, $order, sprintf(self::LINE_SALE, $order->id)],
            };
        });
    }

    /** Gives back what a failed — or `$refunded` — delivery drew (once; nothing when it drew nothing). */
    public function giveBack(Order $order, Bot $bot, bool $refunded = false): void
    {
        $this->write($bot, static function () use ($order, $refunded): ?array {
            $net = self::netFor($order);

            return $net < 0 ? [TrafficTransactionType::Refund, -$net, $order, sprintf($refunded ? self::LINE_REFUNDED : self::LINE_GIVEN_BACK, $order->id)] : null;
        });
    }

    /**
     * Runs `$work` — giving one of the bot's services `$bytes` more — with that traffic drawn from the pool first and
     * given back if the work throws.
     *
     * @template T
     * @param \Closure(): T $work
     * @param-immediately-invoked-callable $work
     * @return T
     * @throws TrafficShortException when the pool does not have it — before `$work` runs
     */
    public function extending(Subscription $subscription, Actor $actor, int $bytes, \Closure $work): mixed
    {
        $bot = $subscription->shop();
        $this->write($bot, static function (int $balance) use ($subscription, $bytes): array {
            if ($balance < $bytes) {
                throw new TrafficShortException(sprintf(TrafficShortException::EXTENSION, Messages::bytes($bytes), Messages::bytes(max(0, $balance))));
            }

            return [TrafficTransactionType::Extension, -$bytes, null, sprintf(self::LINE_EXTENSION, $subscription->remote_name)];
        }, $actor->reviewer);

        try {
            return $work();
        } catch (\Throwable $e) {
            $this->write($bot, static fn(): array => [TrafficTransactionType::Refund, $bytes, null, sprintf(self::LINE_EXTENSION_GIVEN_BACK, $subscription->remote_name)], $actor->reviewer);

            throw $e;
        }
    }

    /**
     * Draws a free test account's traffic for the service `$name` the bot just made — in the transaction that records the
     * test, so a test that falls through draws nothing.
     *
     * @throws TrafficShortException when the pool does not have it
     */
    public function drawTest(Bot $bot, int $bytes, string $name): void
    {
        $this->write($bot, static function (int $balance) use ($bytes, $name): array {
            if ($balance < $bytes) {
                throw new TrafficShortException(sprintf(TrafficShortException::TEST, Messages::bytes($bytes), Messages::bytes(max(0, $balance))));
            }

            return [TrafficTransactionType::Sale, -$bytes, null, sprintf(self::LINE_TEST, $name)];
        });
    }

    /** The pool's lines, newest first, each reviewer as its reader may see it (Reviewers). */
    public function ledger(Bot $bot, PageRequest $list): Page
    {
        return Page::fetch(TrafficTransaction::query()->where('bot_id', $bot->id)->orderByDesc('id'), $list, fn(TrafficTransaction $line): array => [
            'id' => $line->id,
            'type' => $line->type->value,
            'bytes' => $line->bytes,
            'balance_after' => $line->balance_after,
            'description' => $line->description,
            'order_id' => $line->order_id,
            'reviewer' => $this->reviewers->present($line->reviewer),
            'created_at' => $line->created_at?->toIso8601String(),
        ]);
    }

    /** The sum of an order's delivery lines: below zero while its traffic is out of the pool. */
    private static function netFor(Order $order): int
    {
        return (int) TrafficTransaction::query()
            ->where('order_id', $order->id)
            ->whereIn('type', [TrafficTransactionType::Sale->value, TrafficTransactionType::Renewal->value, TrafficTransactionType::Refund->value])
            ->sum('bytes');
    }

    /**
     * Appends the bot's next line, which `$line` builds from the current balance ([type, bytes, order, description], or
     * null for none), under the ledger's lock.
     *
     * @param \Closure(int): (array{TrafficTransactionType, int, Order|null, string|null}|null) $line
     */
    private function write(Bot $bot, \Closure $line, ?string $reviewer = null): ?TrafficTransaction
    {
        return $this->ledger->append($bot, 'traffic_transactions', 'bot_id', static function (string $last) use ($bot, $line, $reviewer): ?TrafficTransaction {
            $balance = (int) $last;
            $move = $line($balance);
            if ($move === null) {
                return null;
            }
            [$type, $bytes, $order, $description] = $move;

            return TrafficTransaction::query()->create([
                'bot_id' => $bot->id,
                'type' => $type,
                'bytes' => $bytes,
                'balance_after' => $balance + $bytes,
                'description' => $description !== null ? mb_substr($description, 0, 255) : null,
                'order_id' => $order?->id,
                'reviewer' => $reviewer,
            ]);
        });
    }
}
