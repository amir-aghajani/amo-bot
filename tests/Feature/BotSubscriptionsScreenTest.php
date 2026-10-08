<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use Tests\BotTestCase;

/**
 * "سرویس‌های من": the customer's running services, newest first, as buttons named like the panel names them, five to
 * a page with «بعدی» / «قبلی» under them (next on the left: forward is leftward for a right-to-left reader) and «صفحه x
 * از y» in the text, and a red «بازگشت» — no search button.
 */
final class BotSubscriptionsScreenTest extends BotTestCase
{
    /** @var array<int, Subscription> ali_1 … ali_n by number, in the order they were bought; ali_4 has expired. */
    private array $services = [];

    protected function setUp(): void
    {
        parent::setUp();

        // An inline menu, so first-level screens carry the inline "back" (a reply menu has none).
        $this->inlineStartMenu();
    }

    public function testTheFirstPageListsTheNewestFiveWithANextButtonAndARedBack(): void
    {
        $this->sold(7);

        $this->send($this->tap(MainMenu::SUBSCRIPTIONS));

        $keyboard = $this->inlineKeyboard(0);
        self::assertSame([self::text(BotText::SubscriptionsTitle) . self::text(BotText::SubscriptionsPage, ['page' => '۱', 'pages' => '۲'])], $this->said(), 'six running ones (one expired is left out): two pages');

        $services = array_slice($keyboard, 0, 5);
        self::assertSame(self::buttons('ali_7', 'ali_6', 'ali_5', 'ali_3', 'ali_2'), self::labels($services), 'newest first, named as on the panel');
        self::assertSame(SubscriptionHandler::serviceCallback($this->services[7]->id), $services[0][0]['callback_data']);

        self::assertSame([['text' => self::text(BotText::PageNext), 'callback_data' => MenuHandler::subscriptionsCallback(2)]], $keyboard[5], 'only "next" on the first page');
        self::assertSame(['text' => self::text(BotText::Back), 'callback_data' => MainMenu::HOME, 'style' => 'danger'], $keyboard[6][0], 'back, in red');
        self::assertCount(7, $keyboard, 'no search button');
    }

    public function testAPageBetweenOthersHasNextOnTheLeftOfPrevious(): void
    {
        $this->sold(12);

        $this->send($this->tap(MenuHandler::subscriptionsCallback(2)));

        $keyboard = $this->inlineKeyboard(0);
        self::assertStringEndsWith(self::text(BotText::SubscriptionsPage, ['page' => '۲', 'pages' => '۳']), $this->said()[0]);
        self::assertSame(self::buttons('ali_7', 'ali_6', 'ali_5', 'ali_3', 'ali_2'), self::labels(array_slice($keyboard, 0, 5)));
        self::assertSame([
            ['text' => self::text(BotText::PageNext), 'callback_data' => MenuHandler::subscriptionsCallback(3)],
            ['text' => self::text(BotText::PagePrev), 'callback_data' => MenuHandler::subscriptionsCallback(1)],
        ], $keyboard[5], 'Telegram lays a row out left to right');
    }

    public function testTheLastPageHasTheRestAndOnlyPrevious(): void
    {
        $this->sold(7);

        $this->send($this->tap(MenuHandler::subscriptionsCallback(2)));

        $keyboard = $this->inlineKeyboard(0);
        self::assertStringEndsWith(self::text(BotText::SubscriptionsPage, ['page' => '۲', 'pages' => '۲']), $this->said()[0]);
        self::assertSame(self::buttons('ali_1'), self::labels(array_slice($keyboard, 0, 1)));
        self::assertSame([['text' => self::text(BotText::PagePrev), 'callback_data' => MenuHandler::subscriptionsCallback(1)]], $keyboard[1]);
        self::assertSame('danger', $keyboard[2][0]['style']);
    }

    public function testAPageOutOfRangeLandsOnTheNearestOne(): void
    {
        $this->sold(7);

        $this->send($this->tap(MenuHandler::subscriptionsCallback(9)));

        self::assertStringEndsWith(self::text(BotText::SubscriptionsPage, ['page' => '۲', 'pages' => '۲']), $this->said()[0]);
    }

    public function testFewerThanAPageNeedsNoNavigation(): void
    {
        $this->sold(3);

        $this->send($this->tap(MainMenu::SUBSCRIPTIONS));

        self::assertSame([self::text(BotText::SubscriptionsTitle)], $this->said());
        $keyboard = $this->inlineKeyboard(0);
        self::assertSame(self::buttons('ali_3', 'ali_2', 'ali_1'), self::labels(array_slice($keyboard, 0, 3)));
        self::assertSame([[MainMenu::HOME]], array_map(static fn(array $row): array => array_column($row, 'callback_data'), array_slice($keyboard, 3)), 'and the back button alone');
    }

    public function testAServicesWayBackIsThePageItIsOn(): void
    {
        $this->sold(7);

        self::assertSame(MenuHandler::subscriptionsCallback(1), MenuHandler::subscriptionsCallbackFor($this->services[2]), 'ali_2 is the fifth newest running one');
        self::assertSame(MenuHandler::subscriptionsCallback(2), MenuHandler::subscriptionsCallbackFor($this->services[1]));
    }

    /** The customer's services ali_1 … ali_n, bought in that order; ali_4 has expired. */
    private function sold(int $count): void
    {
        $user = $this->customer(['username' => 'ali']);
        $server = $this->fakeServer();
        $plan = $this->plan();

        foreach (range(1, $count) as $n) {
            $this->services[$n] = $this->subscription($user, $plan, $server, "ali_{$n}", $n === 4 ? ['status' => SubscriptionStatus::Expired] : []);
        }
    }

    /** @return list<string> The services' buttons, each named as the panel names the service. */
    private static function buttons(string ...$names): array
    {
        return array_map(static fn(string $name): string => self::text(BotText::ServiceButton, ['client' => $name]), $names);
    }

    /**
     * @param list<list<array<string, mixed>>> $rows
     * @return list<string>
     */
    private static function labels(array $rows): array
    {
        return array_column(array_merge(...$rows), 'text');
    }
}
