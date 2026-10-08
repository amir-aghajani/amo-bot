<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Services\PlanCategoryService;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Referrals\Services\ReferralSettings;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerRenewal;
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
 * The home screens: reached from the menu keyboard (a reply-keyboard tap arrives as the button's
 * label, an inline one as its `menu:*` callback) or from an inline "back" button. Purchase and top-up
 * flows have their own handlers, and so have «نمایندگی» and «پشتیبانی» — its tickets — their screens
 * (AgencyHandler::home(), TicketHandler::home()); this one renders the overviews, and «آموزش», the text the
 * admin wrote (BotText::Tutorial). When the menu is inline there is nothing under the text field, so every
 * screen carries a "back" to the menu (MainMenu::screen()).
 */
final class MenuHandler implements Handler
{
    /** `cat:{id}` — the plans of one category; `cat:0` the uncategorised ones. */
    public const CATEGORY = 'cat:';

    /** Services per page of "سرویس‌های من". */
    private const SUBSCRIPTIONS_PER_PAGE = 5;

    private const WALLET_HISTORY_LINES = 5;

    public function __construct(
        private readonly MainMenu $menu,
        private readonly Buttons $buttons,
        private readonly PlanCategoryService $categories,
        private readonly BotTexts $texts,
        private readonly ReferralService $referrals,
        private readonly ReferralSettings $referralSettings,
        private readonly AgencyHandler $agencyScreens,
        private readonly TicketHandler $supportScreens,
    ) {}

    /** The plan list of a category, or of the uncategorised plans ("سایر پلن‌ها") for null. */
    public static function categoryCallback(?int $categoryId): string
    {
        return CallbackData::build(self::CATEGORY, $categoryId ?? 0);
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

    /** The page of "سرویس‌های من" the service is on (newest first): "back" from it lands where the customer was. */
    public static function subscriptionsCallbackFor(Subscription $subscription): string
    {
        $newer = self::services($subscription->user_id)->where('id', '>', $subscription->id)->count();

        return self::subscriptionsCallback(intdiv($newer, self::SUBSCRIPTIONS_PER_PAGE) + 1);
    }

    public function handle(Context $ctx): void
    {
        $update = $ctx->update;
        $screen = (string) ($update->isCallback() ? $update->callbackData() : $this->menu->screenFor($update->text()));

        $category = CallbackData::args($screen, self::CATEGORY);
        $page = CallbackData::args($screen, MainMenu::SUBSCRIPTIONS);
        $renewals = CallbackData::args($screen, MainMenu::RENEW);
        match (true) {
            $category !== null => $this->category($ctx, (int) ($category[0] ?? 0)),
            $page !== null => $this->subscriptions($ctx, (int) ($page[0] ?? 1)),
            $renewals !== null => $this->renewals($ctx, (int) ($renewals[0] ?? 1)),
            $screen === MainMenu::PLANS => $this->plans($ctx),
            $screen === MainMenu::WALLET => $this->wallet($ctx),
            $screen === MainMenu::AFFILIATES => $this->referral($ctx),
            $screen === MainMenu::AGENCY => $this->agencyScreens->home($ctx),
            $screen === MainMenu::SUPPORT => $this->supportScreens->home($ctx),
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
     * "خرید اشتراک": the categories when the shop has any, otherwise straight to the plans.
     */
    private function plans(Context $ctx): void
    {
        $groups = $this->categories->groups();
        if (!self::isGrouped($groups)) {
            if ($groups === []) {
                $this->menu->screen($ctx, $this->texts->get(BotText::PlansEmpty));
            } else {
                $this->planList($ctx, $groups[0]['plans'], null, grouped: false);
            }

            return;
        }

        $buttons = [];
        foreach ($groups as $group) {
            $category = $group['category'];
            $buttons[] = InlineKeyboard::callback($category->name ?? $this->texts->get(BotText::CategoryOther), self::categoryCallback($category?->id));
        }

        $this->menu->screen($ctx, $this->texts->get(BotText::CategoriesTitle), InlineKeyboard::make()->grid($buttons, 1));
    }

    /** `cat:{id}` — the plans of one category (0 = the uncategorised ones), with a way back to the categories. */
    private function category(Context $ctx, int $categoryId): void
    {
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
            $this->planList($ctx, $group['plans'], $group['category'], self::isGrouped($groups));

            return;
        }

        // The category went meanwhile: the categories as they are now.
        $this->plans($ctx);
    }

    /**
     * The plans to pick from — a category's, one level under the categories (and "back" goes there), or the whole
     * shop's, a screen of the menu.
     *
     * @param Collection<int, Plan> $plans
     */
    private function planList(Context $ctx, Collection $plans, ?PlanCategory $category, bool $grouped): void
    {
        $buttons = [];
        foreach ($plans as $plan) {
            $label = $this->texts->render(BotText::PlanButton, [
                'plan' => $plan->name,
                'traffic' => Messages::traffic($plan->trafficBytes()),
                'duration' => Messages::duration($plan->duration_days),
                'price' => Money::format($plan->price),
            ]);
            $buttons[] = InlineKeyboard::callback($label, PurchaseHandler::planCallback($plan->id));
        }
        $keyboard = InlineKeyboard::make()->grid($buttons, 1);

        $title = $category !== null
            ? $this->texts->render(BotText::CategoryPlans, ['category' => $category->name])
            : $this->texts->get(BotText::PlansTitle);
        if ($grouped) {
            $ctx->edit($title, ['reply_markup' => $keyboard->row($this->buttons->back(MainMenu::PLANS))->build()]);
        } else {
            $this->menu->screen($ctx, $title, $keyboard);
        }
    }

    /**
     * Whether the shop files its plans under categories (any active one, empty or not) — the customer picks a category
     * first then.
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
     * "سرویس‌های من": the customer's active services, newest first, a page at a time (`menu:subs:{page}`), each opening
     * its screen.
     */
    private function subscriptions(Context $ctx, int $page): void
    {
        $this->servicePage(
            $ctx,
            self::services($ctx->user->id),
            $page,
            BotText::SubscriptionsTitle,
            BotText::SubscriptionsEmpty,
            static fn(Subscription $subscription): string => SubscriptionHandler::serviceCallback($subscription->id),
            self::subscriptionsCallback(...),
        );
    }

    /**
     * The menu's «تمدید سرویس»: the services the customer may renew (CustomerRenewal) — active or ended, an ended one
     * being what "سرویس‌های من" leaves out —, newest first, a page at a time (`menu:renew:{page}`), each opening its
     * renewal's checkout, whose «بازگشت» comes back to this page.
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
     * A page of the customer's services: one button per service named as the panel names it (what the delivery message
     * called «نام کاربری سرویس»), "next"/"previous" under them when there is more than a page, the page's number under the
     * title, and a red back.
     *
     * @param Builder<Subscription> $services
     * @param \Closure(Subscription, int): string $open What a service's button opens, from the page it is on
     * @param \Closure(int): string $at The list at another page
     */
    private function servicePage(Context $ctx, Builder $services, int $page, BotText $title, BotText $empty, \Closure $open, \Closure $at): void
    {
        $total = (clone $services)->count();
        if ($total === 0) {
            $this->menu->screen($ctx, $this->texts->get($empty), backStyle: 'danger');

            return;
        }

        $pages = (int) ceil($total / self::SUBSCRIPTIONS_PER_PAGE);
        $page = max(1, min($page, $pages));

        $keyboard = InlineKeyboard::make();
        foreach ($services->forPage($page, self::SUBSCRIPTIONS_PER_PAGE)->get() as $subscription) {
            $label = $this->texts->render(BotText::ServiceButton, ['client' => $subscription->remote_name]);
            $keyboard->row(InlineKeyboard::callback($label, $open($subscription, $page)));
        }

        $text = $this->texts->get($title);
        if ($pages > 1) {
            $keyboard->row(...$this->buttons->pages($page, $pages, $at));
            $text .= $this->texts->part(BotText::SubscriptionsPage, ['page' => Persian::digits($page), 'pages' => Persian::digits($pages)]);
        }

        $this->menu->screen($ctx, $text, $keyboard, backStyle: 'danger');
    }

    /** @return Builder<Subscription> A customer's running services, newest first: "سرویس‌های من" and its pages. */
    private static function services(int $userId): Builder
    {
        return Subscription::active()->where('user_id', $userId)->latest('id');
    }

    /** The balance (a debt below zero) — an agent's credit under it — and the last few ledger lines, with the way to charge it. */
    private function wallet(Context $ctx): void
    {
        $text = $this->texts->render(BotText::WalletBalance, ['balance' => Messages::balance($ctx->user->balance())]);
        $credit = $ctx->user->credit();
        if (Money::isPositive($credit)) {
            $text .= $this->texts->part(BotText::WalletCredit, ['credit' => Messages::credit($credit)]);
        }

        $lines = WalletTransaction::query()
            ->where('user_id', $ctx->user->id)
            ->latest('id')
            ->limit(self::WALLET_HISTORY_LINES)
            ->get()
            ->map(fn(WalletTransaction $line): Html => $this->texts->part($line->type === WalletTransactionType::Credit ? BotText::WalletHistoryCredit : BotText::WalletHistoryDebit, [
                'amount' => Money::format($line->amount),
                'entry' => (string) $line->description,
                'when' => Persian::date($line->created_at),
            ]))
            ->all();
        $text .= $lines === [] ? $this->texts->part(BotText::WalletNoHistory) : $this->texts->part(BotText::WalletHistory, ['history' => Html::lines(...$lines)]);

        $this->menu->screen($ctx, $text, InlineKeyboard::make()->row($this->buttons->topUp()));
    }

    /**
     * «زیرمجموعه‌گیری»: the customer's invite link (in a <code> span, copied with a tap) with what it earns — the rate,
     * from every payment or only the first — and their numbers so far, with a button that opens Telegram's share
     * sheet. While the program is off, or before the bot knows its own @username, it says so instead.
     */
    private function referral(Context $ctx): void
    {
        $link = $this->referralSettings->enabled() ? $this->referrals->linkFor($ctx->user) : null;
        if ($link === null) {
            $this->menu->screen($ctx, $this->texts->get(BotText::ReferralOff));

            return;
        }

        ['referrals' => $referrals, 'earned' => $earned] = $this->referrals->statsFor($ctx->user);
        $terms = $this->texts->part($this->referralSettings->firstOnly() ? BotText::ReferralTermsFirst : BotText::ReferralTermsEvery, [
            'rate' => Persian::digits($this->referralSettings->rate()) . '٪',
        ]);
        $text = $this->texts->render(BotText::ReferralScreen, [
            'terms' => $terms,
            'link' => $link,
            'referrals' => Persian::number($referrals),
            'earned' => Money::format($earned),
        ]);

        $share = InlineKeyboard::url($this->texts->get(BotText::ReferralShare), 'https://t.me/share/url?url=' . rawurlencode($link));
        $this->menu->screen($ctx, $text, InlineKeyboard::make()->row($share));
    }
}
