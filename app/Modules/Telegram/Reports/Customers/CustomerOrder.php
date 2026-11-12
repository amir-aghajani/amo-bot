<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Bots\Exceptions\SellingSuspendedException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\Enums\ManualPayment;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\ManualOrders;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Unit;
use Illuminate\Database\Eloquent\Collection;

/**
 * «➕ سرویس دستی» on a customer's card in the report group (CustomerCards: the checks, the look, the questions): a service
 * made for the customer by hand, as the panel's «افزودن سفارش» makes one (ManualOrders, under the admin's name), in steps
 * that each rewrite the card — a plan the shop offers now (paged), one of its servers that can deliver it now, how it is
 * paid («🎁 رایگان», «👛 از کیف پول» or «💵 پرداخت بیرون از فروشگاه» at the plan's price, or «✍️ مبلغ دیگر» typed in
 * reply), then the summary asked again. «✔️ بله، ساخته شود» carries a nonce drawn with it: the order's key, so the same
 * button pressed twice — by anyone — makes one order. The result says the service's name once delivered (the customer is
 * told), or that it waits for its retry. Never the admin's own account nor an agent's; every refusal in a popup.
 */
final class CustomerOrder implements GroupHandler, AnswersPrompts
{
    /** What the section's buttons' callback data starts with: `uo:<action>:<customer id>[:<arg>…]`. */
    public const PREFIX = 'uo:';

    /** The section opened from the card's front: the plans, `uo:o:<customer id>[:<page>]`. */
    public const OPEN = 'o';

    /** The plan's servers: `uo:s:<customer id>:<plan>`. */
    private const SERVERS = 's';
    /** How it is paid: `uo:p:<customer id>:<plan>:<server>`. */
    private const PAYMENT = 'p';
    /** «✍️ مبلغ دیگر»: the amount asked — and the question's kind — `uo:a:<customer id>:<plan>:<server>:<payment>`. */
    private const AMOUNT = 'a';
    /** The summary asked again: `uo:c:<customer id>:<plan>:<server>:<payment>:<amount>`. */
    private const CONFIRM = 'c';
    /** Made: `uo:y:<customer id>:<plan>:<server>:<payment>:<amount>:<nonce>`. */
    private const MAKE = 'y';

    /** The plans a page shows. */
    private const PER_PAGE = 10;

    /** A name the words show. */
    private const NAME_MAX = 64;

    /** The most of a failed delivery's reason the result shows, in Telegram's units. */
    private const NOTES_MAX = 300;

    private const SECTION = 'سرویس دستی';
    private const PICK_PLAN = '👇 پلن سرویس را انتخاب کنید؛ بعد سرور و روش پرداخت. سرویس بلافاصله تحویل و به مشتری خبر داده می‌شود.';
    private const PICK_SERVER = '👇 سرور را انتخاب کنید:';
    private const PICK_PAYMENT = '👇 چطور پرداخت شود؟';
    private const ASK_CONFIRM = '❓ <b>ساخته شود؟</b> مشتری بعد از تحویل خبردار می‌شود.';
    private const PAGE = 'صفحه %s از %s';

    private const NO_PLANS = 'هیچ پلنی الان قابل فروش نیست؛ پلن‌ها و سرورهایشان را در پنل ببینید.';
    private const PLAN_GONE = 'این پلن دیگر قابل فروش نیست.';
    private const NO_SERVERS = 'هیچ‌کدام از سرورهای این پلن الان سرویس تازه‌ای نمی‌سازد؛ دلیل هر کدام در پنل، فرم پلن، کنار همان سرور آمده است.';
    private const SERVER_GONE = 'این سرور دیگر این پلن را تحویل نمی‌دهد.';

    private const ASK_WALLET = '✍️ چه مبلغی از کیف پول مشتری کم شود؟ مبلغ را به تومان بنویسید. موجودی: %s.';
    private const ASK_OUTSIDE = '✍️ چه مبلغی بیرون از فروشگاه گرفته‌اید؟ مبلغ را به تومان بنویسید؛ در درآمد فروشگاه حساب می‌شود.';
    private const PLACEHOLDER = 'مبلغ به تومان';
    private const AMOUNT_SET = '✅ مبلغ %s شد؛ برای ساختن سرویس، روی کارت «✔️ بله، ساخته شود» را بزنید.';

    private const MADE = "✅ <b>ساخته و تحویل شد</b> · سفارش \u{200E}#%d";
    private const TOLD = 'به مشتری خبر داده شد.';
    private const FAILED = "⚠️ <b>سفارش \u{200E}#%d پرداخت شد ولی تحویلش ناموفق بود</b>";
    private const WAITS = 'منتظر تلاش دوباره است: در تاپیک «خطاها» با «🔁 تلاش دوباره برای تحویل»، یا از صفحه «سفارش‌ها» در پنل؛ مشتری بعد از تحویل خبردار می‌شود.';
    private const CANCELLED = "❌ <b>سفارش \u{200E}#%d ثبت نشد</b>";
    private const UNDER_WAY = "⏳ <b>سفارش \u{200E}#%d ثبت شد و تحویلش در جریان است</b>؛ نتیجه را در صفحه «سفارش‌ها» در پنل ببینید.";

    private const DELIVERED = '✅ ساخته و تحویل شد';
    private const NOT_DELIVERED = '⚠️ ثبت شد ولی تحویلش ناموفق بود؛ منتظر تلاش دوباره است.';
    private const NOT_MADE = '❌ سفارش ثبت نشد.';

    public function __construct(
        private readonly CustomerCards $cards,
        private readonly ManualOrders $orders,
        private readonly ServerSelector $selector,
        private readonly PaymentMethods $methods,
    ) {}

    /** A press on one of the section's buttons: never on the admin's own account nor an agent's; a refusal in a popup. */
    public function handle(Update $update): void
    {
        $press = $this->cards->press($update, self::PREFIX);
        if ($press === null) {
            return;
        }
        if (!self::shaped($press)) {
            $this->cards->answer($press);

            return;
        }

        $plan = (int) $press->int(0);
        $server = (int) $press->int(1);
        try {
            CustomerCards::mayChange($press->user, $press->admin);
            match ($press->action) {
                self::SERVERS => $this->servers($press, $plan),
                self::PAYMENT => $this->payment($press, $plan, $server),
                self::AMOUNT => $this->ask($press, $plan, $server, self::paid($press)),
                self::CONFIRM => $this->confirm($press, $plan, $server, self::paid($press), (int) $press->int(3)),
                self::MAKE => $this->make($press, $plan, $server, self::paid($press), (int) $press->int(3), $press->args[4]),
                default => $this->plans($press, $press->int(0) ?? 1),
            };
        } catch (DomainRuleException $e) {
            // Refused as ManualOrders refuses it (the wallet short, selling suspended, the server or the plan, their own
            // account or an agent's): its words, the card as it stands.
            $this->cards->refused($press, $e);
        }
    }

    /**
     * The amount typed for the wallet or an outside payment: the card rewritten into the summary asked again; an amount
     * that is none, or a plan or server no longer offered, says so under the reply, the question kept.
     *
     * @throws DomainRuleException their own account or an agent's, the wallet switched off
     */
    public function answered(CardReply $reply): void
    {
        $plan = $reply->int(0);
        $server = $reply->int(1);
        $payment = self::paymentOf($reply->int(2));
        if ($reply->kind !== self::AMOUNT || $plan === null || $server === null || $payment === null || $payment === ManualPayment::Free) {
            return;
        }
        CustomerCards::mayChange($reply->user, $reply->admin);

        // «۱۵۰٬۰۰۰», «150000», «۱۵۰ هزار تومان».
        $amount = CustomerCards::number($reply->text) ?? Input::amountOf($reply->text, Unit::Toman);
        if ($amount === null || $amount <= 0 || $amount > ManualOrders::AMOUNT_MAX) {
            $this->cards->retry($reply, self::notAnAmount());

            return;
        }
        $picked = $this->choice($plan, $server);
        if (is_string($picked)) {
            $this->cards->retry($reply, $picked);

            return;
        }

        [$html, $rows] = $this->summary($reply->user, $picked[0], $picked[1], $payment, $amount);
        $this->cards->done($reply, sprintf(self::AMOUNT_SET, Money::format($amount)), $html, $rows);
    }

    /** Whether the press has the arguments its action takes: ids, a way to pay, an amount, a nonce. */
    private static function shaped(CardPress $press): bool
    {
        $ids = static function (int $count) use ($press): bool {
            if (count($press->args) !== $count) {
                return false;
            }
            foreach (array_keys($press->args) as $index) {
                if ($press->int($index) === null) {
                    return false;
                }
            }

            return true;
        };

        return match ($press->action) {
            self::OPEN => $press->args === [] || $ids(1),
            self::SERVERS => $ids(1),
            self::PAYMENT => $ids(2),
            self::AMOUNT => $ids(3) && in_array(self::paymentOf($press->int(2)), [ManualPayment::Wallet, ManualPayment::Outside], true),
            self::CONFIRM => $ids(4) && self::paymentOf($press->int(2)) !== null,
            self::MAKE => count($press->args) === 5 && preg_match('/^[0-9a-z]{4,12}$/', $press->args[4]) === 1
                && $press->int(0) !== null && $press->int(1) !== null && self::paymentOf($press->int(2)) !== null && $press->int(3) !== null,
            default => false,
        };
    }

    /**
     * The plans the shop offers now (the bot's and the panel's rule, ServerSelector::offers()), a page of them, each a
     * button with its name and price; the press answered with `$word` — a popup — when given. None at all: a popup, the
     * card as it is.
     *
     * @throws ValidationException 422 on `status`: the agent's shop's selling is suspended
     */
    private function plans(CardPress $press, int $page, ?string $word = null): void
    {
        $user = $press->user;
        if (!$user->shop()->sells()) {
            throw ValidationException::on('status', SellingSuspendedException::STAFF);
        }
        $plans = $this->offered();
        if ($plans === []) {
            $this->cards->answer($press, self::NO_PLANS, alert: true);

            return;
        }

        [$shown, $page, $pages] = Buttons::slice($plans, $page, self::PER_PAGE);
        $keyboard = InlineKeyboard::make()->grid(array_map(static fn(Plan $plan): array => CustomerCards::button(
            self::label($plan->name) . ' · ' . Money::format($plan->price),
            CustomerCards::data(self::PREFIX, self::SERVERS, $user->id, $plan->id),
        ), $shown), 2);
        $lines = [CustomerCards::header($user, self::SECTION), '', self::PICK_PLAN];
        if ($pages > 1) {
            // Forward is leftward for a right-to-left reader: «بعدی» first.
            $keyboard->row(...array_filter([
                $page < $pages ? CustomerCards::button('⬅️ بعدی', CustomerCards::data(self::PREFIX, self::OPEN, $user->id, $page + 1)) : null,
                $page > 1 ? CustomerCards::button('➡️ قبلی', CustomerCards::data(self::PREFIX, self::OPEN, $user->id, $page - 1)) : null,
            ]));
            $lines[] = sprintf(self::PAGE, Persian::digits($page), Persian::digits($pages));
        }
        $keyboard->row(CustomerCards::back($user->id));

        $this->cards->show($press, implode("\n", $lines), $keyboard->build()['inline_keyboard'], $word, $word !== null);
    }

    /**
     * The plan's servers that can deliver it now, a button each, and back to the plans' page it is on. A plan no longer
     * offered: the plans as they now stand, with why in a popup.
     */
    private function servers(CardPress $press, int $planId, ?string $word = null): void
    {
        $offer = $this->offer($planId);
        if (is_string($offer)) {
            $this->plans($press, $this->pageOf($planId), $offer);

            return;
        }

        [$plan, $choices] = $offer;
        $user = $press->user;
        $rows = InlineKeyboard::make()
            ->grid(array_map(static fn(Server $server): array => CustomerCards::button(
                self::label($server->name),
                CustomerCards::data(self::PREFIX, self::PAYMENT, $user->id, $plan->id, $server->id),
            ), $choices), 2)
            ->row(CustomerCards::button('⬅️ بازگشت', CustomerCards::data(self::PREFIX, self::OPEN, $user->id, $this->pageOf($plan->id))))
            ->build()['inline_keyboard'];
        $html = implode("\n", [CustomerCards::header($user, self::SECTION), '', self::planLine($plan), self::PICK_SERVER]);

        $this->cards->show($press, $html, $rows, $word, $word !== null);
    }

    /**
     * How it is paid: «🎁 رایگان», «👛 از کیف پول» (while the shop's wallet is on) and «💵 پرداخت بیرون از فروشگاه» at the
     * plan's price, «✍️ مبلغ دیگر» for either of the two; back to the servers.
     */
    private function payment(CardPress $press, int $planId, int $serverId): void
    {
        $picked = $this->picked($press, $planId, $serverId);
        if ($picked === null) {
            return;
        }

        [$plan, $server] = $picked;
        $user = $press->user;
        $wallet = $this->methods->enabledWallet() !== null;
        $price = (int) Money::digits($plan->price);
        $confirm = static fn(ManualPayment $payment, int $amount): string => CustomerCards::data(self::PREFIX, self::CONFIRM, $user->id, $plan->id, $server->id, CallbackData::place($payment), $amount);
        $other = static fn(ManualPayment $payment): string => CustomerCards::data(self::PREFIX, self::AMOUNT, $user->id, $plan->id, $server->id, CallbackData::place($payment));

        $keyboard = InlineKeyboard::make()->row(CustomerCards::button('🎁 رایگان', $confirm(ManualPayment::Free, 0)));
        if ($price > 0) {
            if ($wallet) {
                $keyboard->row(CustomerCards::button('👛 از کیف پول · ' . Money::format($price), $confirm(ManualPayment::Wallet, $price)));
            }
            $keyboard->row(CustomerCards::button('💵 پرداخت بیرون از فروشگاه · ' . Money::format($price), $confirm(ManualPayment::Outside, $price)));
        }
        $keyboard
            ->grid(array_values(array_filter([
                $wallet ? CustomerCards::button('✍️ مبلغ دیگر · کیف پول', $other(ManualPayment::Wallet)) : null,
                CustomerCards::button('✍️ مبلغ دیگر · بیرون', $other(ManualPayment::Outside)),
            ])), 2)
            ->row(CustomerCards::button('⬅️ بازگشت', CustomerCards::data(self::PREFIX, self::SERVERS, $user->id, $plan->id)));

        $lines = [CustomerCards::header($user, self::SECTION), '', self::planLine($plan), self::location($server)];
        if ($wallet) {
            $lines[] = self::balance($user);
        }
        $lines[] = self::PICK_PAYMENT;

        $this->cards->show($press, implode("\n", $lines), $keyboard->build()['inline_keyboard']);
    }

    /**
     * «✍️ مبلغ دیگر»: the amount asked in Toman, under the card; the reply comes back to answered().
     *
     * @throws ValidationException 422 on `payment`: the shop's wallet is switched off
     */
    private function ask(CardPress $press, int $planId, int $serverId, ManualPayment $payment): void
    {
        if ($this->picked($press, $planId, $serverId) === null) {
            return;
        }
        $this->walletOn($payment);

        $question = $payment === ManualPayment::Wallet ? sprintf(self::ASK_WALLET, Messages::balance($press->user->balance())) : self::ASK_OUTSIDE;
        $this->cards->ask($press, self::PREFIX, self::AMOUNT, [$planId, $serverId, CallbackData::place($payment)], $question, self::PLACEHOLDER);
    }

    /**
     * The summary asked again, at the plan's price or none (free).
     *
     * @throws ValidationException the wallet switched off, an amount that is none
     */
    private function confirm(CardPress $press, int $planId, int $serverId, ManualPayment $payment, int $amount): void
    {
        $picked = $this->picked($press, $planId, $serverId);
        if ($picked === null) {
            return;
        }

        [$html, $rows] = $this->summary($press->user, $picked[0], $picked[1], $payment, $amount);
        $this->cards->show($press, $html, $rows);
    }

    /**
     * «✔️ بله، ساخته شود»: the order made, paid and delivered by ManualOrders under the admin's name and the key its nonce
     * makes — pressed again, the same order as it stands —; then the result.
     *
     * @throws DomainRuleException as ManualOrders refuses it
     */
    private function make(CardPress $press, int $planId, int $serverId, ManualPayment $payment, int $amount, string $nonce): void
    {
        $order = $this->orders->make($press->user, Actor::groupAdmin($press->admin), [
            'plan_id' => $planId,
            'server_id' => $serverId,
            'payment' => $payment->value,
        ] + ($payment === ManualPayment::Free ? [] : ['amount' => $amount]), self::key($nonce, $press));

        $this->result($press, $order);
    }

    /**
     * What came of it: delivered — the service's name, the customer told —, failed and waiting for its retry, or (asked
     * again with its key) cancelled or still on its way; «🛍 سرویس‌ها» and back to the front.
     */
    private function result(CardPress $press, Order $order): void
    {
        $order->loadMissing(['plan', 'server', 'subscription']);
        $user = $press->user;
        $payment = $order->manualPayment();
        $made = [
            '📦 پلن: ' . self::name($order->packageName() ?? '—'),
            self::location($order->subscription->server ?? $order->server),
        ];
        if ($payment !== null) {
            $made[] = '💳 پرداخت: ' . $payment->label() . ($payment === ManualPayment::Free ? '' : ' · ' . Money::format($order->amount));
        }
        $notes = $order->notes === null || trim($order->notes) === '' ? [] : ['📝 ' . htmlspecialchars(Limits::fit(trim($order->notes), self::NOTES_MAX))];

        [$lines, $word, $alert] = match ($order->status) {
            OrderStatus::Fulfilled => [[
                sprintf(self::MADE, $order->id),
                ...$made,
                ...($order->subscription === null ? [] : ['🔖 سرویس: <code>' . htmlspecialchars($order->subscription->remote_name) . '</code>']),
                self::TOLD,
            ], self::DELIVERED, false],
            OrderStatus::Failed => [[sprintf(self::FAILED, $order->id), ...$made, ...$notes, self::WAITS], self::NOT_DELIVERED, true],
            OrderStatus::Cancelled => [[sprintf(self::CANCELLED, $order->id), ...$made, ...$notes], self::NOT_MADE, true],
            default => [[sprintf(self::UNDER_WAY, $order->id), ...$made], null, false],
        };
        $rows = InlineKeyboard::make()->grid([
            CustomerCards::button('🛍 سرویس‌ها', CustomerCards::data(CustomerServices::PREFIX, CustomerServices::OPEN, $user->id)),
            CustomerCards::back($user->id),
        ], 2)->build()['inline_keyboard'];

        $this->cards->show($press, implode("\n", [CustomerCards::header($user, self::SECTION), '', ...$lines]), $rows, $word, $alert);
    }

    /**
     * The summary of the order asked again — the plan, the server, how it is paid and the amount (none for a gift) —, with
     * «⬅️ انصراف» and «✔️ بله، ساخته شود», whose data carries a nonce drawn now.
     *
     * @return array{string, list<list<array<string, mixed>>>}
     * @throws ValidationException 422 on `payment`: the wallet switched off; on `amount`: an amount that is none
     */
    private function summary(User $user, Plan $plan, Server $server, ManualPayment $payment, int $amount): array
    {
        $this->walletOn($payment);
        $amount = $payment === ManualPayment::Free ? 0 : $amount;
        if ($payment !== ManualPayment::Free && ($amount <= 0 || $amount > ManualOrders::AMOUNT_MAX)) {
            throw ValidationException::on('amount', self::notAnAmount());
        }

        $lines = [CustomerCards::header($user, self::SECTION), '', self::planLine($plan), self::location($server), '💳 پرداخت: ' . match ($payment) {
            ManualPayment::Free => $payment->label() . '؛ هدیه است و خرید مشتری حساب نمی‌شود',
            ManualPayment::Wallet => $payment->label() . ' · ' . Money::format($amount),
            ManualPayment::Outside => $payment->label() . ' · ' . Money::format($amount) . '؛ در درآمد فروشگاه حساب می‌شود',
        }];
        if ($payment === ManualPayment::Wallet) {
            $lines[] = self::balance($user);
        }
        $rows = InlineKeyboard::make()->row(
            CustomerCards::back($user->id, '⬅️ انصراف'),
            CustomerCards::button('✔️ بله، ساخته شود', CustomerCards::data(self::PREFIX, self::MAKE, $user->id, $plan->id, $server->id, CallbackData::place($payment), $amount, self::nonce()), 'success'),
        )->build()['inline_keyboard'];

        return [implode("\n", [...$lines, '', self::ASK_CONFIRM]), $rows];
    }

    /**
     * The plan and server the press names while the plan is offered and the server one of its choices; null after showing
     * the step before as it now stands, with why in a popup — the plans for a plan gone, its servers for a server gone.
     *
     * @return array{Plan, Server}|null
     */
    private function picked(CardPress $press, int $planId, int $serverId): ?array
    {
        $picked = $this->choice($planId, $serverId);
        if (is_array($picked)) {
            return $picked;
        }

        if ($picked === self::SERVER_GONE) {
            $this->servers($press, $planId, $picked);
        } else {
            $this->plans($press, $this->pageOf($planId), $picked);
        }

        return null;
    }

    /**
     * The plan and server while the plan is offered and the server one of its choices; else why not.
     *
     * @return array{Plan, Server}|string
     */
    private function choice(int $planId, int $serverId): array|string
    {
        $offer = $this->offer($planId);
        if (is_string($offer)) {
            return $offer;
        }
        foreach ($offer[1] as $server) {
            if ($server->id === $serverId) {
                return [$offer[0], $server];
            }
        }

        return self::SERVER_GONE;
    }

    /**
     * The plan while the shop offers it, with the servers that can deliver it now; else why not — gone or switched off,
     * no server that can, or ServerSelector's word (an agent's traffic short of it, their selling suspended).
     *
     * @return array{Plan, list<Server>}|string
     */
    private function offer(int $planId): array|string
    {
        $plan = Plan::active()->whereKey($planId)->first();
        $choices = $plan === null ? [] : array_column($this->selector->choices($plan), 'server');

        return match (true) {
            $plan === null => self::PLAN_GONE,
            $choices === [] => self::NO_SERVERS,
            default => $this->selector->judge(new Collection([$plan]))[$plan->id] ?? [$plan, $choices],
        };
    }

    /**
     * The plans the shop offers now, in the bot's order: switched on, not kept from the shop by the owner, deliverable on
     * a server now — and in an agent's shop, covered by their traffic (ServerSelector::offers()).
     *
     * @return list<Plan>
     */
    private function offered(): array
    {
        $plans = Plan::active()->get();
        $offers = $this->selector->offers($plans);

        return array_values($plans->filter(static fn(Plan $plan): bool => isset($offers[$plan->id]))->all());
    }

    /** The page of the plans the plan is on; the first for one no longer among them. */
    private function pageOf(int $planId): int
    {
        $at = array_search($planId, array_map(static fn(Plan $plan): int => $plan->id, $this->offered()), true);

        return $at === false ? 1 : intdiv($at, self::PER_PAGE) + 1;
    }

    /** @throws ValidationException 422 on `payment`: the wallet paying while the shop's wallet is switched off */
    private function walletOn(ManualPayment $payment): void
    {
        if ($payment === ManualPayment::Wallet && $this->methods->enabledWallet() === null) {
            throw ValidationException::on('payment', ManualOrders::WALLET_OFF);
        }
    }

    /** The way to pay the press names — its third argument —, which shaped() checked. */
    private static function paid(CardPress $press): ManualPayment
    {
        return self::paymentOf($press->int(2)) ?? throw new \LogicException('A press without its way to pay.');
    }

    /** The way to pay at its place among ManualPayment's cases (CallbackData::place()); null for none. */
    private static function paymentOf(?int $place): ?ManualPayment
    {
        return $place === null ? null : CallbackData::caseAt(ManualPayment::class, $place);
    }

    /** A nonce for «✔️ بله»: six base-36 characters, drawn as its summary is drawn. */
    private static function nonce(): string
    {
        return str_pad(base_convert((string) random_int(0, 36 ** 6 - 1), 10, 36), 6, '0', STR_PAD_LEFT);
    }

    /** The order's key: the nonce and the card it was pressed on — the same button, whoever presses it, the same key. */
    private static function key(string $nonce, CardPress $press): string
    {
        return sprintf('report-group:%s:%d', $nonce, (int) $press->update->messageId());
    }

    /** «📦 پلن: یک‌ماهه · ۳۰ گیگابایت · ۳۰ روز · ۱ کاربر · ۱۲۰٬۰۰۰ تومان». */
    private static function planLine(Plan $plan): string
    {
        return '📦 پلن: ' . implode(' · ', [
            self::name($plan->name),
            Messages::traffic($plan->trafficBytes()),
            Messages::duration($plan->duration_hours),
            Messages::devices($plan->ip_limit),
            Money::format($plan->price),
        ]);
    }

    /** «📍 لوکیشن: آلمان». */
    private static function location(?Server $server): string
    {
        return '📍 لوکیشن: ' . self::name($server->name ?? '—');
    }

    /** «👛 موجودی کیف پول مشتری: ۸۰٬۰۰۰ تومان». */
    private static function balance(User $user): string
    {
        return '👛 موجودی کیف پول مشتری: ' . Messages::balance($user->balance());
    }

    private static function notAnAmount(): string
    {
        return sprintf(ManualOrders::NOT_AN_AMOUNT, Money::format(ManualOrders::AMOUNT_MAX));
    }

    /** A name in a button's label: plain text, as it is. */
    private static function label(string $name): string
    {
        return mb_substr(trim($name), 0, self::NAME_MAX);
    }

    private static function name(string $name): string
    {
        return htmlspecialchars(self::label($name));
    }
}
