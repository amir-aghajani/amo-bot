<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Services\PlanCategoryService;
use App\Modules\Discounts\DTO\AutomaticDiscounts;
use App\Modules\Discounts\Services\Loyalty;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Referrals\Services\ReferralSettings;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Subscriptions\Services\ServiceClaims;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Enums\WalletTransactionType;
use App\Modules\Users\Models\WalletTransaction;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The main menu's screens, reached from a reply-keyboard label or a `menu:*` callback: the plan list (by category when
 * the shop has any), «سرویس‌های من» and the renewals list (paged), the wallet, the referral screen and «آموزش». The
 * agency, support, gift-code, wheel and free-test screens are their own handlers' home(). With an inline menu every
 * screen carries «بازگشت» to it (MainMenu::screen()).
 */
final class MenuHandler implements Handler
{
    /** `cat:{id}` — the plans of one category; `cat:0` the uncategorised ones. */
    public const CATEGORY = 'cat:';

    /** Services per page of «سرویس‌های من» and the renewals list. */
    private const SUBSCRIPTIONS_PER_PAGE = 5;

    private const WALLET_HISTORY_LINES = 5;

    public function __construct(
        private readonly MainMenu $menu,
        private readonly Buttons $buttons,
        private readonly PlanCategoryService $categories,
        private readonly Pricing $pricing,
        private readonly BotTexts $texts,
        private readonly ReferralService $referrals,
        private readonly ReferralSettings $referralSettings,
        private readonly AgencyHandler $agencyScreens,
        private readonly TicketHandler $supportScreens,
        private readonly GiftCodeHandler $giftScreens,
        private readonly WheelHandler $wheelScreens,
        private readonly TestAccountHandler $testScreens,
        private readonly CustomServiceHandler $customScreens,
        private readonly WalletTransferHandler $walletSends,
        private readonly Loyalty $loyalty,
        private readonly ServiceClaims $claims,
    ) {}

    /** The plan list of a category, or of the uncategorised plans ("سایر پلن‌ها") for null — at that page. */
    public static function categoryCallback(?int $categoryId, int $page = 1): string
    {
        return CallbackData::build(self::CATEGORY, $categoryId ?? 0, ...$page > 1 ? [$page] : []);
    }

    /** «خرید اشتراک» at that page: the categories, or the plans of a shop without any. */
    public static function plansCallback(int $page): string
    {
        return $page > 1 ? CallbackData::build(MainMenu::PLANS, $page) : MainMenu::PLANS;
    }

    /** "سرویس‌های من" at that page. */
    public static function subscriptionsCallback(int $page): string
    {
        return CallbackData::build(MainMenu::SUBSCRIPTIONS, $page);
    }

    /** The menu's «تمدید سرویس» at that page: the services the customer may renew. */
    public static function renewalsCallback(int $page): string
    {
        return CallbackData::build(MainMenu::RENEW, $page);
    }

    /** The page of «سرویس‌های من» the service is on (newest first), so «بازگشت» from it lands where the customer was. */
    public static function subscriptionsCallbackFor(Subscription $subscription): string
    {
        $newer = CustomerServices::running($subscription->user_id)->where('id', '>', $subscription->id)->count();

        return self::subscriptionsCallback(intdiv($newer, self::SUBSCRIPTIONS_PER_PAGE) + 1);
    }

    public function handle(Context $ctx): void
    {
        $update = $ctx->update;
        $screen = (string) ($update->isCallback() ? $update->callbackData() : $this->menu->screenFor($update->text()));

        $category = CallbackData::args($screen, self::CATEGORY);
        $page = CallbackData::args($screen, MainMenu::SUBSCRIPTIONS);
        $renewals = CallbackData::args($screen, MainMenu::RENEW);
        $plans = CallbackData::args($screen, MainMenu::PLANS);
        $tests = CallbackData::args($screen, MainMenu::TEST);
        match (true) {
            $category !== null => $this->category($ctx, (int) ($category[0] ?? 0), (int) ($category[1] ?? 1)),
            $page !== null => $this->subscriptions($ctx, (int) ($page[0] ?? 1)),
            $renewals !== null => $this->renewals($ctx, (int) ($renewals[0] ?? 1)),
            $plans !== null => $this->plans($ctx, (int) ($plans[0] ?? 1)),
            $screen === MainMenu::WALLET => $this->wallet($ctx),
            $screen === MainMenu::AFFILIATES => $this->referral($ctx),
            $screen === MainMenu::AGENCY => $this->agencyScreens->home($ctx),
            $screen === MainMenu::SUPPORT => $this->supportScreens->home($ctx),
            $screen === MainMenu::GIFT => $this->giftScreens->home($ctx),
            $screen === MainMenu::WHEEL => $this->wheelScreens->home($ctx),
            $tests !== null => $this->testScreens->home($ctx, page: (int) ($tests[0] ?? 1)),
            $screen === MainMenu::TUTORIAL => $this->menu->screen($ctx, $this->texts->get(BotText::Tutorial)),
            default => $this->home($ctx),
        };
    }

    /** The menu itself, in place of the tapped message: the greeting, with the inline buttons when that is how the menu is laid out. */
    private function home(Context $ctx): void
    {
        $text = $this->texts->welcome($ctx->user->name());
        $ctx->edit($text, $this->menu->isInline() ? ['reply_markup' => $this->menu->markup($ctx->user)] : []);
    }

    /**
     * «خرید اشتراک» at `$page`: the categories when the shop has any, otherwise straight to the plans — and under them,
     * while the custom builder is offered, «🛠 ساخت سرویس دلخواه» (CustomServiceHandler), even with no plan on sale. Either
     * list is paged past one keyboard's worth (Buttons::LIST_PAGE). An agent's shop whose selling is suspended offers no
     * plan, whatever its categories: it says so, politely (the builder is the main shop's alone).
     */
    private function plans(Context $ctx, int $page = 1): void
    {
        if (!CurrentBot::get()->sells()) {
            $this->menu->screen($ctx, $this->texts->get(BotText::SellingPaused));

            return;
        }

        $groups = $this->categories->groups();
        $custom = $this->customScreens->button();
        if (!self::isGrouped($groups)) {
            if ($groups === []) {
                $this->menu->screen($ctx, $this->texts->get(BotText::PlansEmpty), $custom === [] ? null : InlineKeyboard::make()->row(...$custom));
            } else {
                $this->planList($ctx, $groups[0]['plans'], null, $page, grouped: false, below: $custom);
            }

            return;
        }

        [$shown, $page, $pages] = Buttons::slice($groups, $page);
        $buttons = [];
        foreach ($shown as $group) {
            $category = $group['category'];
            $buttons[] = InlineKeyboard::callback($category->name ?? $this->texts->get(BotText::CategoryOther), self::categoryCallback($category?->id));
        }

        $keyboard = InlineKeyboard::make()->grid($buttons, 1);
        $text = $this->buttons->paged($keyboard, $this->texts->get(BotText::CategoriesTitle), $page, $pages, self::plansCallback(...));
        $this->menu->screen($ctx, $text, $keyboard->row(...$custom));
    }

    /**
     * `cat:{id}[:{page}]` — the plans of one category (0 = the uncategorised ones), with a way back to the categories; a
     * category of an old message in a shop whose selling is suspended says so (plans()), not that it is empty.
     */
    private function category(Context $ctx, int $categoryId, int $page): void
    {
        if (!CurrentBot::get()->sells()) {
            $this->plans($ctx);

            return;
        }

        $groups = $this->categories->groups();
        foreach ($groups as $group) {
            if (($group['category']->id ?? 0) !== $categoryId) {
                continue;
            }
            if ($group['plans']->isEmpty()) {
                $name = $group['category']->name ?? $this->texts->get(BotText::CategoryOther);
                $ctx->edit($this->texts->render(BotText::CategoryEmpty, ['category' => $name]), ['reply_markup' => $this->buttons->backOnly(MainMenu::PLANS)]);

                return;
            }
            $this->planList($ctx, $group['plans'], $group['category'], $page, self::isGrouped($groups));

            return;
        }

        // The category went meanwhile: the categories as they are now.
        $this->plans($ctx);
    }

    /**
     * The plans to pick from at `$page` — a category's (with «بازگشت» to the categories) or the whole shop's, paged past
     * one keyboard's worth — each at the customer's price (Pricing::offers(), asked once for the page), in the plan's own
     * button colour and row, then the row `$below`.
     *
     * @param Collection<int, Plan> $plans
     * @param list<array<string, mixed>> $below
     */
    private function planList(Context $ctx, Collection $plans, ?PlanCategory $category, int $page, bool $grouped, array $below = []): void
    {
        [$shown, $page, $pages] = Buttons::slice($plans->all(), $page);
        $prices = $this->pricing->offers($ctx->user, ...$shown);
        $buttons = array_map(fn(Plan $plan): array => $this->buttons->plan($plan, $prices[$plan->id]->amount(), PurchaseHandler::planCallback($plan->id)), $shown);
        $keyboard = InlineKeyboard::make()->flow($buttons);

        $title = $this->buttons->paged(
            $keyboard,
            $category !== null ? $this->texts->render(BotText::CategoryPlans, ['category' => $category->name]) : $this->texts->get(BotText::PlansTitle),
            $page,
            $pages,
            $grouped ? static fn(int $at): string => self::categoryCallback($category?->id, $at) : self::plansCallback(...),
        );
        $keyboard->row(...$below);
        if ($grouped) {
            $ctx->edit($title, ['reply_markup' => $keyboard->row($this->buttons->back(MainMenu::PLANS))->build()]);
        } else {
            $this->menu->screen($ctx, $title, $keyboard);
        }
    }

    /**
     * Whether the shop files its plans under categories (any active one, empty or not): the customer picks one first.
     *
     * @param list<array{category: PlanCategory|null, plans: Collection<int, Plan>}> $groups PlanCategoryService::groups()
     */
    private static function isGrouped(array $groups): bool
    {
        foreach ($groups as $group) {
            if ($group['category'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * «سرویس‌های من»: the customer's active services, newest first, a page at a time, each opening its screen — and,
     * while the shop takes services by their link, «➕ افزودن سرویس» under them, or under the word that there are none
     * (ServiceClaimHandler).
     */
    private function subscriptions(Context $ctx, int $page): void
    {
        $this->servicePage(
            $ctx,
            CustomerServices::running($ctx->user->id),
            $page,
            BotText::SubscriptionsTitle,
            BotText::SubscriptionsEmpty,
            static fn(Subscription $subscription): string => SubscriptionHandler::serviceCallback($subscription->id),
            self::subscriptionsCallback(...),
            $this->claims->offered() ? [InlineKeyboard::callback($this->texts->get(BotText::ClaimButton), ServiceClaimHandler::PREFIX, 'primary')] : [],
        );
    }

    /**
     * The menu's «تمدید سرویس»: the services the customer may renew — ended ones too, which «سرویس‌های من» leaves out —,
     * a page at a time, each opening its renewal's checkout (one without a plan, the plans to pick first).
     */
    private function renewals(Context $ctx, int $page): void
    {
        $this->servicePage(
            $ctx,
            CustomerRenewal::candidates($ctx->user->id),
            $page,
            BotText::RenewChoose,
            BotText::RenewNothing,
            static fn(Subscription $subscription, int $page): string => RenewalHandler::checkoutCallback($subscription->id, $page),
            self::renewalsCallback(...),
        );
    }

    /**
     * A page of the customer's services: a button per service under its panel name, «بعدی» / «قبلی» and the page number
     * when there is more than one page, the row `$below` (on the empty screen too), and a red «بازگشت».
     *
     * @param Builder<Subscription> $services
     * @param \Closure(Subscription, int): string $open What a service's button opens, from the page it is on
     * @param \Closure(int): string $at The list at another page
     * @param list<array<string, mixed>> $below
     */
    private function servicePage(Context $ctx, Builder $services, int $page, BotText $title, BotText $empty, \Closure $open, \Closure $at, array $below = []): void
    {
        $total = (clone $services)->count();
        if ($total === 0) {
            $this->menu->screen($ctx, $this->texts->get($empty), $below === [] ? null : InlineKeyboard::make()->row(...$below), backStyle: 'danger');

            return;
        }

        [$page, $pages] = Buttons::page($total, $page, self::SUBSCRIPTIONS_PER_PAGE);

        $keyboard = InlineKeyboard::make();
        foreach ($services->forPage($page, self::SUBSCRIPTIONS_PER_PAGE)->get() as $subscription) {
            $label = $this->texts->render(BotText::ServiceButton, ['client' => $subscription->remote_name]);
            $keyboard->row(InlineKeyboard::callback($label, $open($subscription, $page)));
        }

        $text = $this->buttons->paged($keyboard, $this->texts->get($title), $page, $pages, $at);
        $keyboard->row(...$below);

        $this->menu->screen($ctx, $text, $keyboard, backStyle: 'danger');
    }

    /**
     * «کیف پول»: the balance (or debt), an agent's credit, the loyalty tier, the last ledger lines, the top-up (not in an
     * agent's shop whose selling is suspended) and — while the shop offers it — «انتقال موجودی».
     */
    private function wallet(Context $ctx): void
    {
        $text = $this->texts->render(BotText::WalletBalance, ['balance' => Messages::balance($ctx->user->balance())]);
        $credit = $ctx->user->credit();
        if (Money::isPositive($credit)) {
            $text .= $this->texts->part(BotText::WalletCredit, ['credit' => Messages::credit($credit)]);
        }
        $text .= $this->loyaltyLine($ctx);

        $lines = WalletTransaction::query()
            ->where('user_id', $ctx->user->id)
            ->latest('id')
            ->limit(self::WALLET_HISTORY_LINES)
            ->get()
            ->map(fn(WalletTransaction $line): Html => $this->texts->part($line->type === WalletTransactionType::Credit ? BotText::WalletHistoryCredit : BotText::WalletHistoryDebit, [
                'amount' => Money::format($line->amount),
                'entry' => Messages::rowNumbers((string) $line->description),
                'when' => Persian::date($line->created_at),
            ]))
            ->all();
        $text .= $lines === [] ? $this->texts->part(BotText::WalletNoHistory) : $this->texts->part(BotText::WalletHistory, ['history' => Html::lines(...$lines)]);

        // One row in reading order: the top-up first, on the right.
        $topUp = CurrentBot::get()->sells() ? [$this->buttons->topUp()] : [];
        $this->menu->screen($ctx, $text, InlineKeyboard::make()->grid([...$topUp, ...$this->walletSends->button()]));
    }

    /**
     * The customer's loyalty tier and what is left to the next one (or to the first); nothing in a shop without tiers.
     * The tiers are read first so a shop without any never asks what the customer spent (Loyalty::standing() always
     * does, which would cost every wallet screen a query).
     */
    private function loyaltyLine(Context $ctx): Html|string
    {
        $tiers = $this->loyalty->tiers();
        if ($tiers === []) {
            return '';
        }
        $spent = $this->loyalty->spent($ctx->user);
        $tier = Loyalty::tierFor($spent, $tiers);
        $next = Loyalty::nextFor($spent, $tiers);
        $climb = $next === null ? null : ['to_next_tier' => Money::format(Money::subtract($next->min_spent, $spent)), 'next_tier' => $next->name];
        if ($tier === null) {
            return $climb === null ? '' : $this->texts->part(BotText::LoyaltyNoTier, $climb);
        }

        return $this->texts->part(BotText::LoyaltyTierLine, [
            'loyalty_tier' => $tier->name,
            'tier_climb' => $climb === null ? '' : $this->texts->part(BotText::LoyaltyTierClimb, $climb),
            'tier_discount' => AutomaticDiscounts::percent($tier->discount_percent),
            'tier_cashback' => AutomaticDiscounts::percent($tier->cashback_percent),
        ]);
    }

    /**
     * «زیرمجموعه‌گیری»: the customer's invite link, the program's terms, their numbers so far and a share button; while
     * the program is off, or the bot's @username is unknown, it says so instead.
     */
    private function referral(Context $ctx): void
    {
        $link = $this->referralSettings->enabled() ? $this->referrals->linkFor($ctx->user) : null;
        if ($link === null) {
            $this->menu->screen($ctx, $this->texts->get(BotText::ReferralOff));

            return;
        }

        ['referrals' => $referrals, 'earned' => $earned] = $this->referrals->statsFor($ctx->user);
        $text = $this->texts->render(BotText::ReferralScreen, [
            'terms' => $this->referralTerms(),
            'link' => $link,
            'referrals' => Persian::number($referrals),
            'earned' => Money::format($earned),
        ]);

        $share = InlineKeyboard::url($this->texts->get(BotText::ReferralShare), 'https://t.me/share/url?url=' . rawurlencode($link));
        $this->menu->screen($ctx, $text, InlineKeyboard::make()->row($share));
    }

    /** The program's terms, a part each: the commission (and a renewal's, when it differs), the start gift and the plan reward. */
    private function referralTerms(): Html
    {
        $settings = $this->referralSettings;
        $parts = [$this->texts->part($settings->firstOnly() ? BotText::ReferralTermsFirst : BotText::ReferralTermsEvery, ['rate' => AutomaticDiscounts::percent($settings->rate())])];
        // With only the first payment earning, a renewal (never someone's first) needs no line of its own.
        if (!$settings->firstOnly() && $settings->renewalRate() !== $settings->rate()) {
            $parts[] = $settings->renewalRate() === 0 ? $this->texts->part(BotText::ReferralTermsNoRenewal) : $this->texts->part(BotText::ReferralTermsRenewal, ['rate' => AutomaticDiscounts::percent($settings->renewalRate())]);
        }

        $you = $settings->startReferrer();
        $friend = $settings->startInvitee();
        $trigger = $settings->startWhen()->words();
        $parts[] = match (true) {
            Money::isPositive($you) && Money::isPositive($friend) => $this->texts->part(BotText::ReferralTermsGiftBoth, ['trigger' => $trigger, 'you' => Money::format($you), 'friend' => Money::format($friend)]),
            Money::isPositive($you) => $this->texts->part(BotText::ReferralTermsGiftYou, ['trigger' => $trigger, 'you' => Money::format($you)]),
            Money::isPositive($friend) => $this->texts->part(BotText::ReferralTermsGiftFriend, ['trigger' => $trigger, 'friend' => Money::format($friend)]),
            default => null,
        };

        $plan = $settings->rewardEvery() > 0 && $settings->rewardPlanId() !== null ? Plan::query()->find($settings->rewardPlanId()) : null;
        if ($plan !== null) {
            $parts[] = $this->texts->part(BotText::ReferralTermsReward, ['invites' => Persian::number($settings->rewardEvery()), 'plan' => $plan->name]);
        }

        // A part the admin emptied leaves no line behind.
        return Html::lines(...array_filter($parts, static fn(?Html $part): bool => $part !== null && (string) $part !== ''));
    }
}
