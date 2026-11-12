<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Store\Contracts\Door;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerGates;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * What the bot asks of a customer before it gives anything for free — a Telegram account, their own number while the shop
 * asks it, the shop's channels joined — is asked the same of a free test and a spin of the wheel through the Store API,
 * and through the mini app of an order, a gift code and a service added by its link too. Bot admins pass, as in the bot.
 */
final class StoreBotGatesTest extends HttpTestCase
{
    private Website $website;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->sellingServer('آلمان', ['test_enabled' => true]);
        $this->botSettings('test_accounts', ['test_enabled' => true, 'test_per_user' => 1, 'test_period_days' => 0, 'test_daily_limit' => 0, 'test_newcomers_only' => false, 'test_cleanup' => true, 'test_cleanup_hours' => 24, 'test_unused_days' => 0]);
        $this->wheelOn();
        $this->wheelPrize('پوچ', ['kind' => RewardKind::Nothing]);
        $this->bearer($this->customerSession($this->ali));
    }

    public function testACustomerWithoutTelegramTakesNoFreeTestNorASpin(): void
    {
        $this->bearer($this->customerSession($this->webCustomer()));

        $this->assertHeldBack(CustomerGates::NO_TELEGRAM);
    }

    public function testTheirNumberIsAskedWhileTheShopAsksIt(): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true]);
        $this->assertHeldBack(CustomerGates::NO_PHONE);

        $this->ali->forceFill(['phone' => '+989121234567'])->save();
        self::assertNull($this->decode($this->get($this->storeApi($this->website, '/test-account')))['refusal'], 'verified in the bot, they may');
    }

    public function testTheShopsChannelsAreJoinedFirst(): void
    {
        $this->botSettings('channels', ['join_required' => true]);
        $this->channel(-1001, 'کانال اخبار', ['username' => 'news']);
        $this->channel(-1002, 'گروه VIP', ['type' => 'supergroup', 'username' => null, 'invite_link' => 'https://t.me/+vip']);
        // Each of the three asks looks both channels up: the news channel not joined, the group joined.
        $this->telegram()->reply(...array_merge(...array_fill(0, 3, [['status' => 'left'], ['status' => 'member']])));

        $this->assertHeldBack(sprintf(CustomerGates::CHANNELS, '«کانال اخبار»'));

        $this->telegram()->reply(['status' => 'member'], ['status' => 'member']);
        self::assertNull($this->decode($this->get($this->storeApi($this->website, '/test-account')))['refusal'], 'joined, they may');
    }

    /**
     * The mini app is the bot's own door, and the bot answers nothing before its gates: an order, a gift code and a
     * service added by its link wait for their number there — not on the website, which is no door of the bot's.
     */
    public function testThroughTheMiniAppTheirNumberIsAskedBeforeAnOrderAGiftCodeOrAClaim(): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true]);
        $app = $this->miniApp();
        $server = Server::query()->firstOrFail();
        $plan = $this->plan([], $server);
        $wallet = $this->walletMethod();
        $tries = 0;
        $order = function (Door $door) use ($plan, $server, $wallet, &$tries): ResponseInterface {
            $tries++;

            return $this->send('POST', $this->storeApi($door, '/orders'), ['plan_id' => $plan->id, 'server_id' => $server->id, 'method_id' => $wallet->id], ['Idempotency-Key' => "order-{$tries}"]);
        };

        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $order($app), message: 'a purchase');
        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $this->send('POST', $this->storeApi($app, '/wallet/top-up'), ['amount' => 100000, 'method_id' => $wallet->id], ['Idempotency-Key' => 'top-up']), message: 'a top-up');
        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $this->postJson($this->storeApi($app, '/gift-codes/redeem'), ['code' => 'NOWRUZ7K3M9QXAPT']), message: 'a gift code');
        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $this->postJson($this->storeApi($app, '/subscriptions/claim'), ['link' => 'https://sub.example.com/sub/abc']), message: 'a service by its link');
        self::assertSame(0, Order::query()->count(), 'nothing ordered');

        self::assertArrayNotHasKey('status', $this->decode($order($this->website))['errors'] ?? [], 'the website asks no number of an order');

        $this->ali->forceFill(['phone' => '+989121234567'])->save();
        self::assertArrayNotHasKey('status', $this->decode($order($app))['errors'] ?? [], 'verified in the bot, they may');

        $this->ali->forceFill(['phone' => null, 'role' => UserRole::Admin])->save();
        self::assertArrayNotHasKey('status', $this->decode($order($app))['errors'] ?? [], 'a bot admin passes, as in the bot');
    }

    public function testABotAdminPassesEveryGate(): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true]);
        $this->ali->forceFill(['role' => UserRole::Admin])->save();

        self::assertNull($this->decode($this->get($this->storeApi($this->website, '/test-account')))['refusal']);
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/wheel/spin'))->getStatusCode());
    }

    /** The free test is offered with why not, taken refused, and the wheel's spin refused — each in `$words`. */
    private function assertHeldBack(string $words): void
    {
        self::assertSame(['offers' => [], 'refusal' => $words], $this->decode($this->get($this->storeApi($this->website, '/test-account'))));
        $this->assertRefusedUnder('status', $words, $this->postJson($this->storeApi($this->website, '/test-account'), ['server_id' => 1]));
        $this->assertRefusedUnder('status', $words, $this->postJson($this->storeApi($this->website, '/wheel/spin')));
    }
}
