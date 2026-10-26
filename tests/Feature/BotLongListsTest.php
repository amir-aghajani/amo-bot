<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TestAccountHandler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Traffic;
use Tests\BotTestCase;

/**
 * A list the shop makes as long as it likes — its plans, their categories, a plan's locations, the plans a service
 * without one renews on, the servers giving a test — is one screen up to one keyboard's worth (Buttons::LIST_PAGE; the
 * test's servers, a line of the message each, TestAccountHandler::PER_PAGE) and paged past it as «سرویس‌های من» is:
 * «بعدی» / «قبلی» and «صفحه ۱ از ۲», never a keyboard Telegram refuses whole (Limits::KEYBOARD_BUTTONS). A page past the
 * end — the list shrank since — is its last.
 */
final class BotLongListsTest extends BotTestCase
{
    private Server $berlin;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->berlin = $this->sellingServer('Berlin');
        $this->ali = $this->customer();
    }

    public function testThePlansAreOneScreenUpToAKeyboardsWorthAndPagedPastIt(): void
    {
        $plans = $this->plans(Buttons::LIST_PAGE);

        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame([self::text(BotText::PlansTitle)], $this->said(), "one keyboard's worth: one screen, no pages");
        self::assertSame(self::planTaps($plans), $this->callbacks(0));

        $last = $this->plans(1, 'آخر')[0];
        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame([self::text(BotText::PlansTitle) . self::page(1, 2)], $this->said());
        self::assertSame([...self::planTaps($plans), MenuHandler::plansCallback(2)], $this->callbacks(0), 'one more: «بعدی» to the rest');
        self::assertSame(self::text(BotText::PageNext), $this->inlineKeyboard(0)[Buttons::LIST_PAGE][0]['text']);
        self::assertLessThanOrEqual(Limits::KEYBOARD_BUTTONS, count(array_merge(...$this->inlineKeyboard(0))));

        $this->send($this->tap(MenuHandler::plansCallback(2)));
        self::assertSame([self::text(BotText::PlansTitle) . self::page(2, 2)], $this->said());
        self::assertSame([PurchaseHandler::planCallback($last->id), MainMenu::PLANS], $this->callbacks(0), 'the last plan, and «قبلی» to the first page');
        self::assertSame(self::text(BotText::PagePrev), $this->inlineKeyboard(0)[1][0]['text']);

        $this->send($this->tap(MenuHandler::plansCallback(9)));
        self::assertSame([self::text(BotText::PlansTitle) . self::page(2, 2)], $this->said(), 'a page past the end: the last');
    }

    public function testTheCategoriesAndACategorysPlansArePagedPastOneKeyboardsWorth(): void
    {
        $categories = array_map(fn(int $i): PlanCategory => $this->category(['name' => "دسته {$i}"]), range(1, Buttons::LIST_PAGE + 1));
        $first = $categories[0];
        $plans = $this->plans(Buttons::LIST_PAGE + 1, category: $first);

        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame([self::text(BotText::CategoriesTitle) . self::page(1, 2)], $this->said());
        $taps = array_map(static fn(PlanCategory $category): string => MenuHandler::categoryCallback($category->id), $categories);
        self::assertSame([...array_slice($taps, 0, Buttons::LIST_PAGE), MenuHandler::plansCallback(2)], $this->callbacks(0));

        $this->send($this->tap(MenuHandler::plansCallback(2)));
        self::assertSame([$taps[Buttons::LIST_PAGE], MainMenu::PLANS], $this->callbacks(0), 'the last category, «قبلی» back');

        $this->send($this->tap(MenuHandler::categoryCallback($first->id)));
        self::assertSame([self::text(BotText::CategoryPlans, ['category' => 'دسته 1']) . self::page(1, 2)], $this->said());
        self::assertSame([...self::planTaps(array_slice($plans, 0, Buttons::LIST_PAGE)), MenuHandler::categoryCallback($first->id, 2), MainMenu::PLANS], $this->callbacks(0), 'its plans a page at a time, then back to the categories');

        $this->send($this->tap(MenuHandler::categoryCallback($first->id, 2)));
        self::assertSame([self::text(BotText::CategoryPlans, ['category' => 'دسته 1']) . self::page(2, 2)], $this->said());
        self::assertSame([PurchaseHandler::planCallback($plans[Buttons::LIST_PAGE]->id), MenuHandler::categoryCallback($first->id), MainMenu::PLANS], $this->callbacks(0));
    }

    public function testAPlansLocationsArePagedPastOneKeyboardsWorth(): void
    {
        $servers = [$this->berlin, ...array_map(fn(int $i): Server => $this->sellingServer("سرور {$i}"), range(1, Buttons::LIST_PAGE))];
        $plan = $this->plan([], $servers);
        $taps = array_map(static fn(Server $server): string => PurchaseHandler::serverCallback($plan->id, $server->id), $servers);

        $this->send($this->tap(PurchaseHandler::planCallback($plan->id)));
        self::assertStringEndsWith(self::page(1, 2), $this->said()[0]);
        self::assertSame([...array_slice($taps, 0, Buttons::LIST_PAGE), PurchaseHandler::planCallback($plan->id, 2), MainMenu::PLANS], $this->callbacks(0));

        $this->send($this->tap(PurchaseHandler::planCallback($plan->id, 2)));
        self::assertStringEndsWith(self::page(2, 2), $this->said()[0]);
        self::assertSame([$taps[Buttons::LIST_PAGE], PurchaseHandler::planCallback($plan->id), MainMenu::PLANS], $this->callbacks(0), 'the last location, «قبلی», and back to the plans');
    }

    public function testThePlansAServiceWithoutOneRenewsOnArePagedPastOneKeyboardsWorth(): void
    {
        $claimed = $this->mirrored($this->claimedSubscription($this->ali, $this->berlin, 'manual_7'));
        $plans = $this->plans(Buttons::LIST_PAGE + 1);
        $taps = array_map(static fn(Plan $plan): string => RenewalHandler::checkoutCallback($claimed->id, 0, $plan->id), $plans);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($claimed->id, 'renew')));
        self::assertSame([self::text(BotText::RenewPickPlan, ['client' => 'manual_7']) . self::page(1, 2)], $this->said());
        $callbacks = $this->callbacks(0);
        self::assertSame(array_slice($taps, 0, Buttons::LIST_PAGE), array_slice($callbacks, 0, Buttons::LIST_PAGE));
        self::assertSame(SubscriptionHandler::serviceCallback($claimed->id), $callbacks[Buttons::LIST_PAGE + 1], 'back to the service');

        // «بعدی»: the rest, judged as the checkout is.
        $this->send($this->tap($callbacks[Buttons::LIST_PAGE]));
        self::assertSame([self::text(BotText::RenewPickPlan, ['client' => 'manual_7']) . self::page(2, 2)], $this->said());
        self::assertSame([$taps[Buttons::LIST_PAGE], RenewalHandler::checkoutCallback($claimed->id), SubscriptionHandler::serviceCallback($claimed->id)], $this->callbacks(0), 'the last plan, «قبلی» to the first page');
    }

    public function testTheCategoryPlansAServiceWithoutOneRenewsOnArePagedPastOneKeyboardsWorth(): void
    {
        $claimed = $this->mirrored($this->claimedSubscription($this->ali, $this->berlin, 'manual_7'));
        $category = $this->category(['name' => 'دسته 1']);
        $plans = $this->plans(Buttons::LIST_PAGE + 1, category: $category);
        $this->plan(['name' => 'بی دسته'], $this->berlin);
        $taps = array_map(static fn(Plan $plan): string => RenewalHandler::checkoutCallback($claimed->id, 0, $plan->id), $plans);
        $categories = RenewalHandler::checkoutCallback($claimed->id);

        $this->send($this->tap(RenewalHandler::categoryCallback($claimed->id, 0, $category->id)));
        self::assertSame([self::text(BotText::RenewCategoryPlans, ['client' => 'manual_7', 'category' => 'دسته 1']) . self::page(1, 2)], $this->said());
        self::assertSame([...array_slice($taps, 0, Buttons::LIST_PAGE), RenewalHandler::categoryCallback($claimed->id, 0, $category->id, 2), $categories], $this->callbacks(0), 'its plans a page at a time, then back to the categories');

        $this->send($this->tap(RenewalHandler::categoryCallback($claimed->id, 0, $category->id, 2)));
        self::assertSame([self::text(BotText::RenewCategoryPlans, ['client' => 'manual_7', 'category' => 'دسته 1']) . self::page(2, 2)], $this->said());
        self::assertSame([$taps[Buttons::LIST_PAGE], RenewalHandler::categoryCallback($claimed->id, 0, $category->id), $categories], $this->callbacks(0), 'the last plan, «قبلی» to the first page');
    }

    public function testTheServersGivingATestArePagedByTheLinesTheMessageHolds(): void
    {
        $this->botSettings('test_accounts', ['test_enabled' => true, 'test_per_user' => 1, 'test_period_days' => 0, 'test_daily_limit' => 0, 'test_newcomers_only' => false, 'test_cleanup' => true, 'test_cleanup_hours' => 24, 'test_unused_days' => 7]);
        $servers = array_map(fn(int $i): Server => $this->sellingServer("تست {$i}", ['test_enabled' => true, 'test_traffic_bytes' => 500 * Traffic::MEGABYTE, 'test_duration_hours' => 24, 'test_on_hold' => false]), range(1, TestAccountHandler::PER_PAGE));
        $taps = static fn(array $servers): array => array_map(static fn(Server $server): string => TestAccountHandler::PREFIX . $server->id, $servers);

        $this->send($this->tap(MainMenu::TEST));
        self::assertSame([self::testScreen($servers)], $this->said(), 'a page of servers: one screen');
        self::assertSame($taps($servers), $this->callbacks(0));

        $servers[] = $this->sellingServer('آخر', ['test_enabled' => true, 'test_traffic_bytes' => 500 * Traffic::MEGABYTE, 'test_duration_hours' => 24, 'test_on_hold' => false]);
        $this->send($this->tap(MainMenu::TEST));
        self::assertSame([self::testScreen(array_slice($servers, 0, TestAccountHandler::PER_PAGE)) . self::page(1, 2)], $this->said());
        self::assertSame([...$taps(array_slice($servers, 0, TestAccountHandler::PER_PAGE)), TestAccountHandler::pageCallback(2)], $this->callbacks(0));

        $this->send($this->tap(TestAccountHandler::pageCallback(2)));
        self::assertSame([self::testScreen([$servers[TestAccountHandler::PER_PAGE]]) . self::page(2, 2)], $this->said());
        self::assertSame([...$taps([$servers[TestAccountHandler::PER_PAGE]]), MainMenu::TEST], $this->callbacks(0));
    }

    /**
     * `$count` plans on sale on Berlin, in a category when given.
     *
     * @return list<Plan>
     */
    private function plans(int $count, string $name = 'پلن', ?PlanCategory $category = null): array
    {
        return array_map(fn(int $i): Plan => $this->plan(['name' => "{$name} {$i}", 'category_id' => $category?->id], $this->berlin), range(1, $count));
    }

    /**
     * The plans' buttons, in order.
     *
     * @param list<Plan> $plans
     * @return list<string>
     */
    private static function planTaps(array $plans): array
    {
        return array_map(static fn(Plan $plan): string => PurchaseHandler::planCallback($plan->id), $plans);
    }

    /** «صفحه x از y» under a page of a list. */
    private static function page(int $page, int $pages): string
    {
        return self::text(BotText::SubscriptionsPage, ['page' => Persian::digits($page), 'pages' => Persian::digits($pages)]);
    }

    /**
     * The test screen listing these servers, each giving 500 MB for a day.
     *
     * @param list<Server> $servers
     */
    private static function testScreen(array $servers): string
    {
        return self::text(BotText::TestScreen, ['servers' => implode("\n", array_map(static fn(Server $server): string => self::text(BotText::TestServerLine, [
            'server' => $server->name,
            'traffic' => '۵۰۰ مگابایت',
            'duration' => '۱ روز',
            'hold' => '',
        ]), $servers))]);
    }
}
