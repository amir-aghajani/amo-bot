<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Catalog\DTO\CustomPrice;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\RenewalRefusal;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\KeyboardLayout;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Money;

/**
 * «♻️ تمدید سرویس»: one of the customer's services renewed through the shared checkout — on its own plan, or, a service
 * without one (CustomerRenewal::picksPlan(): claimed by its link, or built), on a plan the customer
 * picks among those on sale on its server, which the renewal makes the service's plan — or, while the builder is offered
 * (CustomerRenewal::buildable()), with a package they build: «🛠 تمدید با سرویس دلخواه» beside the plans leads through
 * the builder's steps (CustomServiceHandler, `custom:r:…`) to this checkout of it (checkout()/pay() with `$built`):
 *   renew:{service}:{from}                            → the checkout, with the service as the renewal would leave it
 *                                                       (CustomerRenewal::preview(), the website's too); `from` is where
 *                                                       «بازگشت» goes: 0 the service's screen, n page n of the menu's
 *                                                       renewals list. A service without a plan gets the plans to pick
 *                                                       instead
 *   renew:{service}:{from}:0:{plan}[:{method}]       → the checkout on the plan picked (0: its own), by the method
 *   renew:{service}:{from}:{pay}[:{plan}[:{method}]] → pay: the renewal ordered now, or the open one found
 * `{method}` is a renewal method's place among RenewalMethod's cases (CallbackData::place()), left out for «تمدید پلن
 * فعلی». While the shop offers more than one, the checkout has a button per method, the one it is drawn by marked: a
 * checkout that names none starts on the first offered, a pay button that names none renews by extend (as every button
 * drawn before methods did). Carried in the buttons rather than the chat's session, the method a pay button renews by
 * is always the one its checkout showed — another checkout opened meanwhile cannot change it —, and a discount code
 * redraw keeps it; the code itself is kept for the checkout whatever its method, so switching the method keeps the
 * code. A pay button of a method the shop no longer offers shows the checkout as it is now instead.
 * A service's own plan wins over one named. Opened from the service's screen, the menu's list and a payment reminder.
 * A service that may not be renewed now — or not yet, its renewal window closed (CustomerRenewal::window()): the popup
 * says from when —, whose renewal is already under way, or without a plan and neither a plan on sale to pick nor the
 * builder, gets a popup saying so — a message, for an amount typed at the builder's step — and nothing is ordered. An
 * agent's shop whose selling is suspended says so in place of the screen, as everywhere it sells.
 */
final class RenewalHandler implements Handler, RedrawableCheckout
{
    public const PREFIX = 'renew:';

    public function __construct(
        private readonly CustomerRenewal $renewals,
        private readonly OrderService $orders,
        private readonly Pricing $pricing,
        private readonly CheckoutScreen $screen,
        private readonly CustomerServices $services,
        private readonly ExtrasHandler $extras,
        private readonly ServiceCard $card,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /**
     * The renewal's checkout, opened from the service's screen (`$from` 0) or from that page of the menu's list — on the
     * plan `$planId` picked for a service without one (0: its own plan, or the plans to pick), by `$method` (null: the
     * first the shop offers).
     */
    public static function checkoutCallback(int $subscriptionId, int $from = 0, int $planId = 0, ?RenewalMethod $method = null): string
    {
        return self::callback($subscriptionId, $from, 0, $planId, $method);
    }

    public function handle(Context $ctx): void
    {
        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        $from = max(0, (int) ($args[1] ?? 0));
        $subscription = $this->service($ctx, (int) ($args[0] ?? 0), $from);
        if ($subscription === null) {
            return;
        }

        $pay = (int) ($args[2] ?? 0);
        $planId = (int) ($args[3] ?? 0);
        $method = self::methodNamed((int) ($args[4] ?? 0));
        if ($pay > 0 && $planId > 0 && !CustomerRenewal::picksPlan($subscription)) {
            // A pay button of a picked plan's checkout, for a service that picks none any more (it got a plan since — even
            // that one —, or may not be renewed now): its checkout as it is now, to be seen before anything is paid — the
            // price, and a code kept for the old checkout, may differ (checkout() goes by its own plan in any case).
            $this->checkout($ctx, $subscription, $from, planId: $planId);

            return;
        }
        $pay > 0
            ? $this->pay($ctx, $subscription, $from, $pay, $method ?? RenewalMethod::Extend, $planId)
            : $this->checkout($ctx, $subscription, $from, planId: $planId, method: $method);
    }

    /** The checkout `renew:{service}:{from}[:0:{plan}[:{method}]]` drawn again, for Checkouts. */
    public function drawCheckout(Context $ctx, array $args, ?string $code = null): void
    {
        $from = max(0, (int) ($args[1] ?? 0));
        $subscription = $this->service($ctx, (int) $args[0], $from);
        if ($subscription !== null) {
            $this->checkout($ctx, $subscription, $from, $code, (int) ($args[3] ?? 0), self::methodNamed((int) ($args[4] ?? 0)));
        }
    }

    /** A renewal makes no new service: none, for Checkouts. */
    public function serverOf(array $args): ?int
    {
        return null;
    }

    /**
     * The customer's service, «بازگشت» leading where the renewal was opened from (`$from`: 0 the service's screen, n page
     * n of the menu's renewals list) when it is not there.
     */
    public function service(Context $ctx, int $id, int $from): ?Subscription
    {
        return $this->services->find($ctx, $id, $from > 0 ? MenuHandler::renewalsCallback($from) : MenuHandler::subscriptionsCallback(1));
    }

    /** A renewal method as callbacks carry it (CallbackData::place()); 0 for none, and for extend. */
    public static function methodArg(?RenewalMethod $method): int
    {
        // Extend is 0: every button drawn before there were methods renews by it.
        return $method === RenewalMethod::Extend ? 0 : CallbackData::place($method);
    }

    /** The method a callback names by its place (methodArg()); null for none (0, or no such place). */
    public static function methodNamed(int $place): ?RenewalMethod
    {
        return CallbackData::caseAt(RenewalMethod::class, $place);
    }

    /**
     * The renewal's checkout: what it renews with — the plan, or the package `$built` — its term and traffic, the
     * customer's price, the service's end and traffic as the renewal would leave them by `$method` (and what of the
     * current period's traffic expires), the price's discount lines, when the shop removes the service unless it is
     * renewed, the plan's note, the ways to pay, and — while the shop offers more than one — a button per method. A method
     * it does not offer (null among them) is its first. From the menu's list it also offers the service's extras: the way
     * to a service whose traffic or time ran out, which «سرویس‌های من» does not list. A service without a plan gets the
     * plans to pick until `$planId` is one of them or a package is built; one with a plan is checked out on it, whatever
     * `$planId` names. «بازگشت» leads to the plans picked among, or to the builder's days for a package built.
     *
     * @throws DiscountCodeRefusedException when `$typed` does not hold
     */
    public function checkout(Context $ctx, Subscription $subscription, int $from = 0, ?string $typed = null, int $planId = 0, ?RenewalMethod $method = null, ?CustomPrice $built = null): void
    {
        $method = $this->renewals->resolveMethod($method) ?? $this->renewals->methods()[0];
        $preview = $this->preview($ctx, $subscription, $from, $built ?? ($subscription->plan_id !== null ? 0 : $planId), $method);
        if ($preview === null) {
            return;
        }

        $with = $preview->with;
        [$quote, $dropped] = $this->screen->quote($ctx, self::checkoutOf($subscription, $from, $preview), $this->price($subscription, $preview), $typed);
        $values = $this->card->values($preview->after);
        $text = $this->texts->render(BotText::RenewCheckout, [
            'client' => $subscription->remote_name,
            'plan' => $with instanceof Plan ? $with->name : Order::builtName($with->gb, $with->days),
            'duration' => Messages::duration($preview->package->hours),
            'traffic' => Messages::traffic($preview->package->bytes),
            'devices' => ServiceCard::devicesAfter($preview->after),
            'devices_from' => $this->card->devicesFrom($preview->after),
            'amount' => Money::format($quote->amount()),
            'expires' => $values['expires'],
            'remaining' => $values['remaining'],
            'leftover' => $this->card->periodNote($preview->after),
        ]);
        // The removal line goes under the discount lines: they belong with the price.
        $text = BotTexts::paragraphs($this->screen->priced($text, $quote, $dropped), $this->card->removal($subscription));

        $back = match (true) {
            $with instanceof CustomPrice => CustomServiceHandler::renewalDaysCallback($subscription->id, $from, $with->gb),
            self::picked($subscription, $with) > 0 => self::checkoutCallback($subscription->id, $from),
            default => $this->backFrom($subscription, $from),
        };
        $this->screen->show(
            $ctx,
            $with instanceof Plan ? $this->screen->withNote($text, $with) : $text,
            OrderType::Renewal,
            $quote->amount(),
            static fn(PaymentMethod $way): string => self::payOf($subscription, $from, $preview, $way),
            $back,
            $from > 0 ? $this->extras->buttons($subscription) : [],
            free: !Money::isPositive($quote->amount()),
            quote: $quote,
            checkout: self::checkoutOf($subscription, $from, $preview, $method),
            choices: $this->methodButtons($subscription, $from, $preview),
        );
    }

    /**
     * A way to pay picked: the renewal by `$method` — on the plan `$planId` picked (0: its own), or with the package
     * `$built` — ordered (or the open one found) and paid, unless one is under way already. A method the shop no longer
     * offers, or a way to pay switched off since, shows the checkout as it is now instead, to be seen before anything is
     * paid.
     */
    public function pay(Context $ctx, Subscription $subscription, int $from, int $wayId, RenewalMethod $method, int $planId = 0, ?CustomPrice $built = null): void
    {
        $redraw = fn() => $this->checkout($ctx, $subscription, $from, planId: $planId, method: $method, built: $built);
        if ($this->renewals->resolveMethod($method) === null) {
            $redraw();

            return;
        }
        $preview = $this->preview($ctx, $subscription, $from, $built ?? $planId, $method);
        if ($preview === null) {
            return;
        }

        try {
            $this->screen->pay(
                $ctx,
                $wayId,
                OrderType::Renewal,
                self::checkoutOf($subscription, $from, $preview, $method),
                $this->price($subscription, $preview),
                fn(?OrderKey $key, Quote $quote): Order => $this->orders->openPreviewed($ctx->user, $subscription, $preview, $key, $quote),
                $redraw,
                codeFor: self::checkoutOf($subscription, $from, $preview),
            );
        } catch (RenewalUnderWayException) {
            // Another tap, or the automatic renewal, started one in the same moment.
            $this->refuse($ctx, $subscription, $from, $this->texts->get(BotText::RenewUnderWay));
        }
    }

    /**
     * A service without a plan: the plans on sale on its server to renew it on (CustomerRenewal::choices()), each at the
     * customer's renewal price in the plan's own button colour and row, «🛠 تمدید با سرویس دلخواه» under them while the
     * builder is offered (CustomerRenewal::buildable()) — alone when no plan is on sale there —, and «بازگشت» where its
     * checkout's would lead; with nothing to pick (gone since its refusal was asked), RenewNoPlans. Showing it leaves
     * whatever step the chat was at.
     */
    private function plans(Context $ctx, Subscription $subscription, int $from): void
    {
        $choices = $this->renewals->choices($subscription);
        $builds = $this->renewals->buildable($subscription);
        if ($choices->isEmpty() && !$builds) {
            $this->refuse($ctx, $subscription, $from, $this->texts->get(BotText::RenewNoPlans));

            return;
        }

        $buttons = [];
        $quotes = $this->pricing->renewalOffers($ctx->user, ...$choices->all());
        foreach ($choices as $plan) {
            $label = $this->texts->render(BotText::PlanButton, [
                'plan' => $plan->name,
                'traffic' => Messages::traffic($plan->trafficBytes()),
                'duration' => Messages::duration($plan->duration_hours),
                'price' => Money::format($quotes[$plan->id]->amount()),
            ]);
            $buttons[] = [InlineKeyboard::callback($label, self::checkoutCallback($subscription->id, $from, $plan->id), KeyboardLayout::style($plan->button_style)), $plan->button_row];
        }
        $build = InlineKeyboard::callback($this->texts->get(BotText::CustomRenewButton), CustomServiceHandler::renewalCallback($subscription->id, $from), 'primary');
        $keyboard = InlineKeyboard::make()
            ->flow($buttons)
            ->row(...$builds ? [$build] : [])
            ->row(...$from > 0 ? $this->extras->buttons($subscription) : [])
            ->row($this->buttons->back($this->backFrom($subscription, $from)));

        $ctx->session->clear();
        $ctx->edit($this->texts->render(BotText::RenewPickPlan, ['client' => $subscription->remote_name]), ['reply_markup' => $keyboard->build()]);
    }

    /**
     * A button per method the shop offers, each redrawing the checkout by it, the one `$preview` is drawn by marked;
     * none while it offers one.
     *
     * @return list<array<string, mixed>>
     */
    private function methodButtons(Subscription $subscription, int $from, RenewalPreview $preview): array
    {
        if (count($preview->methods) < 2) {
            return [];
        }

        return array_map(fn(RenewalMethod $method): array => InlineKeyboard::callback(
            $this->texts->render($method === $preview->method ? BotText::RenewMethodChosen : BotText::RenewMethodButton, ['method' => $method->label()]),
            self::checkoutOf($subscription, $from, $preview, $method),
        ), $preview->methods);
    }

    /** @return \Closure(?string): Quote The renewal's price for the customer with a given discount code. */
    private function price(Subscription $subscription, RenewalPreview $preview): \Closure
    {
        return fn(?string $code): Quote => $this->renewals->priced($subscription, $preview, $code);
    }

    /** Where the renewal was opened from: that page of the menu's list, or the service's screen. */
    private function backFrom(Subscription $subscription, int $from): string
    {
        return $from > 0 ? MenuHandler::renewalsCallback($from) : SubscriptionHandler::serviceCallback($subscription->id);
    }

    /**
     * The renewal before it is paid by `$method`, on the service's own plan, on the plan `$with` picks among its choices
     * or with the package `$with` builds (CustomerRenewal::preview()); null once the customer was shown why not
     * (refused()).
     */
    private function preview(Context $ctx, Subscription $subscription, int $from, int|CustomPrice $with, RenewalMethod $method): ?RenewalPreview
    {
        $with = $with === 0 ? null : $with;
        $preview = $this->renewals->preview($subscription, $with, $method);
        if ($preview === null) {
            $this->refused($ctx, $subscription, $from, $this->renewals->refusal($subscription, $with, $method));
        }

        return $preview;
    }

    /**
     * Why the service has no checkout (CustomerRenewal::refusal()), in the bot's words (refuse()): its window closed —
     * from when —, a renewal of it under way (its receipt with support, or paid and being delivered), or nothing it
     * may be renewed with now; an agent's shop whose selling is suspended says so in place of the screen
     * (CheckoutScreen::paused()). A service without a plan that names nothing it may pick gets the plans (and the
     * builder) to pick instead.
     */
    private function refused(Context $ctx, Subscription $subscription, int $from, ?RenewalRefusal $why): void
    {
        match (true) {
            $why === RenewalRefusal::NotYet => $this->refuse($ctx, $subscription, $from, $this->texts->render(BotText::RenewNotYet, ['opens' => $this->renewals->window($subscription)->when()])),
            $why === RenewalRefusal::UnderWay => $this->refuse($ctx, $subscription, $from, $this->texts->get(BotText::RenewUnderWay)),
            $why === RenewalRefusal::SellingSuspended => $this->screen->paused($ctx, $this->backFrom($subscription, $from)),
            $why?->picking() === true, $why === RenewalRefusal::PlanNotOffered, $why === RenewalRefusal::BuilderOff => $this->plans($ctx, $subscription, $from),
            $why === RenewalRefusal::NotRenewable => $this->refuse($ctx, $subscription, $from, $this->texts->get(CustomerRenewal::picksPlan($subscription) ? BotText::RenewNoPlans : BotText::RenewUnavailable)),
            // A package named for a service on its own plan (its first renewal made it so): its checkout on that plan.
            $why === RenewalRefusal::RenewsOnItsPlan => $this->checkout($ctx, $subscription, $from),
            default => $this->refuse($ctx, $subscription, $from, $this->texts->get(BotText::RenewUnavailable)),
        };
    }

    /**
     * Why nothing is renewed, in a popup on the button tapped — or, for an amount typed at the builder's step (no button
     * to answer), a message with «بازگشت» where the renewal was opened from, the step over (Context::alert()).
     */
    private function refuse(Context $ctx, Subscription $subscription, int $from, string $words): void
    {
        $ctx->alert($words, ['reply_markup' => $this->buttons->backOnly($this->backFrom($subscription, $from))]);
    }

    /** The plan a service without one renews on, as its callbacks carry it; 0 for a service on its own plan. */
    private static function picked(Subscription $subscription, Plan $plan): int
    {
        return CustomerRenewal::picksPlan($subscription) ? $plan->id : 0;
    }

    /**
     * The checkout of the renewal `$preview` shows, by `$method` — on a plan (`renew:…`), or with a package built
     * (`custom:r:…`, CustomServiceHandler). Without a method, the one a discount code is kept for: the renewal's whatever
     * its method.
     */
    private static function checkoutOf(Subscription $subscription, int $from, RenewalPreview $preview, ?RenewalMethod $method = null): string
    {
        $with = $preview->with;

        return $with instanceof CustomPrice
            ? CustomServiceHandler::renewalCheckoutCallback($subscription->id, $from, $with->gb, $with->days, $method)
            : self::checkoutCallback($subscription->id, $from, self::picked($subscription, $with), $method);
    }

    /** That checkout's button paying with `$way`, by the method `$preview` is drawn by. */
    private static function payOf(Subscription $subscription, int $from, RenewalPreview $preview, PaymentMethod $way): string
    {
        $with = $preview->with;

        return $with instanceof CustomPrice
            ? CustomServiceHandler::renewalPayCallback($subscription->id, $from, $with->gb, $with->days, $preview->method, $way->id)
            : self::callback($subscription->id, $from, $way->id, self::picked($subscription, $with), $preview->method);
    }

    /** `renew:{service}:{from}:{pay}:{plan}:{method}`, the arguments left off from the right while they are 0. */
    private static function callback(int $subscriptionId, int $from, int $pay, int $planId, ?RenewalMethod $method): string
    {
        $args = [$subscriptionId, $from, $pay, $planId, self::methodArg($method)];
        while (count($args) > 2 && end($args) === 0) {
            array_pop($args);
        }

        return CallbackData::build(self::PREFIX, ...$args);
    }
}
