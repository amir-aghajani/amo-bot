<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Lease;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerLookup;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * «انتقال سرویس»: a service handed to another customer of its shop, found by their numeric Telegram id — free, by the
 * customer themselves (the bot, the website; while the shop lets them, ServiceTransferSettings) or by support (the
 * panels, the website's admin API; always). One rule for every door:
 * - the service may change hands at all (transferable()): no free test, and its client still on its panel;
 * - the recipient is a customer of the service's shop, not its owner, not banned — and not the acting admin;
 * - a customer gives only their own service, never while an order of it they made is open (Order::OPEN: waiting for
 *   its payment or its receipt's review, being delivered, or failed and waiting for support: refuseOpenOrder()), and is
 *   rate-limited: their lookups of recipients with every other (CustomerLookup, money's too), TRANSFERS services given
 *   per TRANSFER_WINDOW. Support is refused only its own service (as every service operation).
 * Refusals are 422s worded for the reader: the service under `status`, the recipient under `telegram_id`.
 *
 * It changes owner as a service claimed by its link does (ProvisioningService::newOwner(), then relabel()): holding the
 * service as a change on its panel does (its lease: one under way, a delivery that began as the giver's, ends first —
 * else BUSY), in one transaction, under the service's row lock, the new owner's columns and the report group's line
 * (ShopReports::serviceTransferred(), «خریدها»: who holds which service). After the commit the previous owner's unpaid
 * orders of it are cancelled (cancelLeftOrders()); the new owner is told it is theirs — and, where its panel can change
 * the link, that «تغییر لینک» cuts off the previous owner, who keeps it (CustomerNotifier::serviceReceived()) — and,
 * when support did it, the previous one that it left them, with the orders cancelled (serviceTransferred()): a customer
 * who gave it away is answered where they did it, as for every change of their own. Then its client on the panel takes
 * the new owner's Telegram id and comment (relabel()).
 */
final class ServiceTransfers
{
    /** Services a customer may give per TRANSFER_WINDOW seconds. */
    public const TRANSFERS = 5;
    public const TRANSFER_WINDOW = 3600;

    /** How long a transfer holds the service (its lease): its own reads and writes, no call to the panel. */
    private const HOLD_SECONDS = 30;

    public const OFF = 'انتقال سرویس در این فروشگاه فعال نیست.';
    public const TEST = 'اکانت تست به حساب دیگری منتقل نمی‌شود.';
    public const GONE = 'کلاینت این سرویس دیگر روی سرور نیست؛ این سرویس منتقل نمی‌شود.';
    public const OPEN_ORDER = 'سفارشی از این سرویس هنوز باز است (در انتظار پرداخت یا تحویل)؛ پس از تحویل آن، یا وقتی سفارش پرداخت‌نشده لغو شد، دوباره امتحان کنید.';
    /** An order of it whose delivery failed: it waits for support's retry or refund, never for time. */
    public const FAILED_ORDER = 'تحویل سفارشی از این سرویس ناموفق بوده و منتظر پشتیبانی است؛ برای رسیدگی به آن با پشتیبانی تماس بگیرید و بعد دوباره امتحان کنید.';
    public const MOVED_ON = 'این سرویس همین حالا به حساب دیگری منتقل شد.';
    /** A change of it under way on its panel (a delivery, a grant, a move): it hands on once that is through. */
    public const BUSY = 'این سرویس همین حالا روی سرور در حال تغییر است؛ چند لحظه بعد دوباره امتحان کنید.';
    public const SAME = 'این سرویس همین حالا مال همین حساب است.';
    public const BANNED = 'این حساب مسدود است و سرویسی به آن منتقل نمی‌شود.';
    public const TO_THEMSELVES = 'سرویس را به حساب خودتان نمی‌توانید منتقل کنید؛ این کار با مدیر دیگری است.';

    /** `orders.notes` of an unpaid order of the previous owner's, cancelled as the service changed hands. */
    public const NOTE_CANCELLED = 'سرویس این سفارش به حساب دیگری منتقل شد';

    /** The refusal past the limit; TooManyAttemptsException::wait() adds the wait. */
    private const TOO_MANY_TRANSFERS = 'سرویس‌های زیادی را منتقل کرده‌اید';

    public function __construct(
        private readonly ServiceTransferSettings $settings,
        private readonly ProvisioningService $provisioning,
        private readonly CustomerNotifier $notifier,
        private readonly ShopReports $reports,
        private readonly PaymentService $payments,
        private readonly CustomerLookup $lookup,
        private readonly RateLimiter $limiter,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether the shop lets customers transfer their services now: the bot's button and the website's flag. */
    public function offered(): bool
    {
        return $this->settings->enabled();
    }

    /** Whether the service may change hands at all, whoever asks: no free test, and its client still on its panel. */
    public static function transferable(Subscription $service): bool
    {
        return !$service->is_test && $service->status !== SubscriptionStatus::Deleted;
    }

    /** Whether its customer is offered «انتقال سرویس» for the service: the shop lets them, and it may change hands. */
    public function offeredFor(Subscription $service): bool
    {
        return $this->offered() && self::transferable($service);
    }

    /**
     * Refuse unless `$by` may hand the service to someone now — asked before whom to (the bot's first step).
     *
     * @throws ValidationException 422 on `status`
     * @throws ActorRefusedException 403 a shop admin's own service
     */
    public function check(Subscription $service, Actor $by): void
    {
        if ($by->isCustomer()) {
            if (!$this->offered()) {
                throw ValidationException::on('status', self::OFF);
            }
            if (!$by->is($service->user_id)) {
                throw ValidationException::on('status', self::MOVED_ON);
            }
        } elseif ($by->is($service->user_id)) {
            throw ActorRefusedException::ownService();
        }

        if ($service->is_test) {
            throw ValidationException::on('status', self::TEST);
        }
        if ($service->status === SubscriptionStatus::Deleted) {
            throw ValidationException::on('status', self::GONE);
        }
        self::refuseOpenOrder($service, $by);
    }

    /**
     * The customer of the service's shop with Telegram id `$telegramId` (as typed: Persian digits are fine) who may receive
     * it from `$by` — what the bot and the website show to confirm. A customer's lookups are counted (CustomerLookup).
     *
     * @throws ValidationException 422 on `status` (the service) or `telegram_id` (the recipient)
     * @throws ActorRefusedException 403 a shop admin's own service
     * @throws TooManyAttemptsException 429 a customer looked up CustomerLookup::LOOKUPS in the window
     */
    public function recipient(Subscription $service, mixed $telegramId, Actor $by): User
    {
        $this->check($service, $by);
        $recipient = $this->lookup->find($service->shop(), $telegramId, $by->isCustomer() ? $by->userId : null);
        self::receives($service, $recipient, $by);

        return $recipient;
    }

    /**
     * Hand the service to the customer with Telegram id `$telegramId` (recipient(), then transfer()): the doors that take
     * the id and confirm nothing first (the APIs). Returns the new owner.
     *
     * @throws ValidationException|ActorRefusedException|TooManyAttemptsException
     * @throws ModelNotFoundException when the service is gone meanwhile
     */
    public function transferById(Subscription $service, mixed $telegramId, Actor $by): User
    {
        $recipient = $this->recipient($service, $telegramId, $by);
        $this->transfer($service, $recipient, $by);

        return $recipient;
    }

    /**
     * Hand the service to `$to`, by the rules above: the new owner's columns with the report group's line in one
     * transaction, the service held meanwhile; then the previous owner's unpaid orders of it cancelled, the customers
     * told and the client on its panel relabelled. The service passed in is left as it is now, its `user` the new owner.
     *
     * @throws ValidationException 422 on `status` (BUSY: a change of it under way on its panel) or `telegram_id`
     * @throws ActorRefusedException 403 a shop admin's own service
     * @throws TooManyAttemptsException 429 a customer gave TRANSFERS in the window
     * @throws ModelNotFoundException when the service is gone meanwhile
     */
    public function transfer(Subscription $service, User $to, Actor $by): void
    {
        $this->check($service, $by);
        self::receives($service, $to, $by);
        if ($by->isCustomer()) {
            $this->limiter->enforce([['transfer|give|' . $by->userId, self::TRANSFERS, self::TRANSFER_WINDOW]], self::TOO_MANY_TRANSFERS);
        }

        $from = $service->user;
        // Held as every change of it on its panel holds it: one under way — a delivery that found it the giver's as it
        // began — ends before it changes hands, and one that begins after finds it the new owner's.
        $lease = Lease::take($service, self::HOLD_SECONDS) ?? throw ValidationException::on('status', self::BUSY);
        try {
            $this->db->transaction(function () use ($service, $from, $to, $by): void {
                $row = $service->newModelQuery()->whereKey($service->id)->lockForUpdate()->first()
                    ?? throw (new ModelNotFoundException())->setModel(Subscription::class, [$service->id]);
                // Changed since it was read: given away by another tap, or gone from its panel.
                if ((int) $row->user_id !== (int) $from->id) {
                    throw ValidationException::on('status', self::MOVED_ON);
                }
                if ($row->status === SubscriptionStatus::Deleted) {
                    throw ValidationException::on('status', self::GONE);
                }
                // Renewals and extras are ordered under the same lock (AutoRenewal::claim(), OrderService::holding()): one
                // opened at this moment is seen.
                self::refuseOpenOrder($service, $by);

                $values = ProvisioningService::newOwner($to);
                $service->newModelQuery()->whereKey($service->id)->update($values);
                $service->forceFill($values)->syncOriginal();
                $service->setRelation('user', $to);
                $this->reports->serviceTransferred($service, $from, $by);
            });
        } finally {
            $lease->release();
        }

        $this->logger->info('Subscription {id} ({name}) went from user {from} to user {to}, by {actor}', [
            'id' => $service->id,
            'name' => $service->remote_name,
            'from' => $from->id,
            'to' => $to->id,
            'actor' => $by->label(),
        ]);
        $cancelled = $this->cancelLeftOrders($service, $from);
        // A customer who gave it away was answered where they did it (the bot, the website), as for every change of their own.
        if (!$by->isCustomer()) {
            $this->notifier->serviceTransferred($service, $from, $cancelled);
        }
        $this->notifier->serviceReceived($service, $this->provisioning->rotates($service));
        $this->provisioning->relabel($service);
    }

    /**
     * Refuse a recipient the service may not go to: a customer of another shop, its owner, a banned account — or the
     * acting admin themselves.
     *
     * @throws ValidationException 422 on `telegram_id`
     */
    private static function receives(Subscription $service, User $to, Actor $by): void
    {
        $refusal = match (true) {
            (int) $to->bot_id !== (int) $service->bot_id => CustomerLookup::UNKNOWN,
            (int) $to->id === (int) $service->user_id => self::SAME,
            $to->isBanned() => self::BANNED,
            !$by->isCustomer() && $by->is($to->id) => self::TO_THEMSELVES,
            default => null,
        };
        if ($refusal !== null) {
            throw ValidationException::on('telegram_id', $refusal);
        }
    }

    /**
     * Cancel, quietly, what the previous owner left unpaid of the service — a renewal or an extra that can no longer be
     * paid or delivered (Order::serviceChangedHands()), and whose renewal would hold back the new owner's automatic
     * renewal — as an order unpaid in time is (PaymentService::expire(), with NOTE_CANCELLED), at once. One whose money may
     * be with support — its receipt in review, its card transfer taken in support's private chat, its online payment held
     * for support — or whose online gateway's page still takes money is left to fail at its delivery for support to give it
     * back. Only support's transfer leaves any (a customer's waits for their open orders), and after its commit, as
     * expire() discards uploaded receipts after its own: the previous owner orders nothing more for a service no longer
     * theirs (OrderService).
     *
     * @return list<int> the ids of the orders cancelled
     */
    private function cancelLeftOrders(Subscription $service, User $from): array
    {
        return CurrentBot::run($service->shop(), function () use ($service, $from): array {
            $now = now();
            $cancelled = [];
            foreach (Order::payable()->where('subscription_id', $service->id)->where('user_id', $from->id)->oldest('id')->get() as $order) {
                if ($this->payments->expire($order, $now, self::NOTE_CANCELLED)) {
                    $cancelled[] = $order->id;
                }
            }

            return $cancelled;
        });
    }

    /**
     * Refuse a customer while an order of the service they made is not finished with (Order::OPEN): what they would pay
     * for, or have paid for, on a service no longer theirs. One waiting for its payment or being delivered ends by itself
     * (OPEN_ORDER); a failed one waits for support (FAILED_ORDER) — once the service changed hands it could never be
     * delivered, and a failed renewal would hold the new owner's renewals (OrderService::renewalUnderWay()). Support is
     * not refused, and a previous owner's order left over from support's transfer holds nobody.
     *
     * @throws ValidationException 422 on `status`
     */
    private static function refuseOpenOrder(Subscription $service, Actor $by): void
    {
        if (!$by->isCustomer()) {
            return;
        }
        $open = Order::query()
            ->withoutGlobalScope(CurrentBot::SCOPE)
            ->where('subscription_id', $service->id)
            ->where('user_id', $by->userId)
            ->whereIn('status', Order::OPEN)
            ->toBase()->distinct()->pluck('status')->all();
        if ($open !== []) {
            throw ValidationException::on('status', in_array(OrderStatus::Failed->value, $open, true) ? self::FAILED_ORDER : self::OPEN_ORDER);
        }
    }
}
