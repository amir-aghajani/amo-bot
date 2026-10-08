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
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Messages;

/**
 * An agent's prepaid traffic, line by line (`traffic_transactions`) — the one writer of the ledger, whose last line is
 * what their bot may still sell (Bot::trafficBalance()), written the ledgers' one way (Core\Database\Ledger). The agent
 * buys it from the main bot at their level's price per GB (a traffic order, add()) — and loses it again when that
 * order is refunded (takeBack()); every purchase and renewal their bot delivers takes the plan's traffic out of it
 * (draw(), once per order — a delivery that is retried does not draw twice) and gets it back when the delivery failed
 * (giveBack()); the traffic the shop gives one of their bot's services from its subscriptions screen comes out of it
 * too, and back when that fails (extending()); the shop may set it right from the panel (adjust()). The balance never
 * goes below zero: a delivery the pool cannot cover fails with TrafficShortException, and waits for the agent's next
 * purchase and the retry; an extension it cannot cover is refused.
 */
final class TrafficPool
{
    public const LINE_PURCHASE = 'خرید %s · سفارش #%d';
    public const LINE_SALE = 'فروش · سفارش #%d';
    public const LINE_RENEWAL = 'تمدید · سفارش #%d';
    public const LINE_GIVEN_BACK = 'بازگشت حجم سفارش ناموفق #%d';
    public const LINE_TAKEN_BACK = 'برگشت خرید حجم · سفارش #%d';
    /** The service's name on its panel — after the kind's own word in the ledgers («افزایش حجم», «بازگشت حجم»). */
    public const LINE_EXTENSION = 'سرویس %s';
    public const LINE_EXTENSION_GIVEN_BACK = 'افزایش ناموفق · سرویس %s';
    /** An adjustment the shop wrote no note for. */
    public const LINE_ADJUSTED = 'اصلاح توسط پشتیبانی';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Reviewers $reviewers,
    ) {}

    /** What an order of an agent's bot takes out of the pool: its plan's traffic (a renewal's: its plan's, else its service's). */
    public static function bytesFor(Order $order): int
    {
        $planId = $order->plan_id ?? Subscription::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereKey($order->subscription_id)->value('plan_id');
        $plan = $planId === null ? null : Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->find($planId);

        return $plan?->trafficBytes() ?? 0;
    }

    /** Traffic bought from the main bot — the traffic order that bought it delivered —, in the caller's transaction when there is one. */
    public function add(Bot $bot, int $bytes, ?Order $order = null): void
    {
        $description = $order !== null ? sprintf(self::LINE_PURCHASE, Messages::bytes($bytes), $order->id) : null;

        $this->write($bot, static fn(): array => [TrafficTransactionType::Purchase, $bytes, $order, $description]);
    }

    /**
     * A refunded traffic order takes back what it added — in the caller's transaction. False, with nothing written, when
     * the pool no longer has that much: its bot sold it since.
     */
    public function takeBack(Order $order, Bot $bot): bool
    {
        $bytes = (int) $order->traffic_bytes;

        return $this->write($bot, static fn(int $balance): ?array => $balance < $bytes ? null : [TrafficTransactionType::Refund, -$bytes, $order, sprintf(self::LINE_TAKEN_BACK, $order->id)]) !== null;
    }

    /**
     * The shop sets the pool right — more (`$bytes` > 0) or less — with the reason the agent reads, under the name of
     * whoever did it.
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
     * Take what the order delivers out of its bot's pool — once: an order whose traffic is out already (a delivery
     * retried after its process died) draws nothing more.
     *
     * @throws TrafficShortException when the pool does not have it
     */
    public function draw(Order $order, Bot $bot): void
    {
        $bytes = self::bytesFor($order);
        if ($bytes <= 0) {
            throw new TrafficShortException(TrafficShortException::UNLIMITED);
        }

        $this->write($bot, static function (int $balance) use ($order, $bytes): ?array {
            if (self::netFor($order) < 0) {
                return null;
            }
            if ($balance < $bytes) {
                throw new TrafficShortException(sprintf(TrafficShortException::SHORT, Messages::bytes($bytes), Messages::bytes(max(0, $balance))));
            }

            return $order->type === OrderType::Renewal
                ? [TrafficTransactionType::Renewal, -$bytes, $order, sprintf(self::LINE_RENEWAL, $order->id)]
                : [TrafficTransactionType::Sale, -$bytes, $order, sprintf(self::LINE_SALE, $order->id)];
        });
    }

    /** The order's delivery failed: what it drew goes back to the pool — once, and nothing for one that drew nothing. */
    public function giveBack(Order $order, Bot $bot): void
    {
        $this->write($bot, static function () use ($order): ?array {
            $net = self::netFor($order);

            return $net < 0 ? [TrafficTransactionType::Refund, -$net, $order, sprintf(self::LINE_GIVEN_BACK, $order->id)] : null;
        });
    }

    /**
     * `$work` — the shop giving one of the bot's services `$bytes` on top of what it has (the subscriptions screen's
     * «افزایش زمان و حجم») — with that traffic out of the bot's pool: drawn first, a line of its own under whoever gave
     * it, and given back when the work fails, as a failed delivery's is.
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

    /** The pool's lines, newest first — who wrote one as its reader may see it (Reviewers: the agent reads «پشتیبانی» for the owner). */
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

    /** What the delivery lines of an order — drawn, given back — add up to: below zero while its traffic is out of the pool. */
    private static function netFor(Order $order): int
    {
        return (int) TrafficTransaction::query()
            ->where('order_id', $order->id)
            ->whereIn('type', [TrafficTransactionType::Sale->value, TrafficTransactionType::Renewal->value, TrafficTransactionType::Refund->value])
            ->sum('bytes');
    }

    /**
     * The bot's next line, as `$line` makes it from the balance now: [type, bytes, order, description], or null for no line.
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
