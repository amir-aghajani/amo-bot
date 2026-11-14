<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
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
 * and through the mini app of an order, a gift code and a service added by its link too — the number, where the shop
 * asks it only at the moments it chose, at those alone. Bot admins pass, as in the bot, and so does a customer excused
 * from the number.
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

    public function testWhileIranianNumbersAloneCountAnotherCountrysOnFileIsSaidSo(): void
    {
        $this->ali->forceFill(['phone' => '+491701234567'])->save();
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true, 'phone_iran_only' => true]);

        $this->assertHeldBack(CustomerGates::NO_IRANIAN_PHONE);
        self::assertTrue($this->decode($this->get($this->storeApi($this->website)))['shop']['phone_iran_only'], 'the website and the mini app are told');

        $this->ali->forceFill(['phone' => '+989121234567'])->save();
        self::assertNull($this->decode($this->get($this->storeApi($this->website, '/test-account')))['refusal'], 'an Iranian number counts');
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

    /**
     * Asked only at the moments the shop chose, the mini app holds back just an order that is one of them — a purchase
     * (a service built too), a renewal (an extra too), a card-to-card payment whatever it pays for —; a gift code and a
     * service added by its link wait for nothing, and the website still asks no number of an order.
     */
    public function testAtTheMomentsChosenTheMiniAppHoldsBackOnlyThoseOrders(): void
    {
        $app = $this->miniApp();
        $server = Server::query()->firstOrFail();
        $plan = $this->plan([], $server);
        $service = $this->subscription($this->ali, $plan, $server, 'ali_1');
        $wallet = $this->walletMethod();
        $card = $this->cardMethod();
        $tries = 0;
        $key = static function () use (&$tries): array {
            $tries++;

            return ['Idempotency-Key' => "try-{$tries}"];
        };
        $buy = fn(Door $door, PaymentMethod $method): ResponseInterface => $this->send('POST', $this->storeApi($door, '/orders'), ['plan_id' => $plan->id, 'server_id' => $server->id, 'method_id' => $method->id], $key());
        $build = fn(PaymentMethod $method): ResponseInterface => $this->send('POST', $this->storeApi($app, '/custom-services/orders'), ['gb' => 10, 'days' => 30, 'server_id' => $server->id, 'method_id' => $method->id], $key());
        $renew = fn(PaymentMethod $method): ResponseInterface => $this->send('POST', $this->storeApi($app, "/subscriptions/{$service->id}/renewal"), ['method_id' => $method->id], $key());
        $extra = fn(PaymentMethod $method): ResponseInterface => $this->send('POST', $this->storeApi($app, "/subscriptions/{$service->id}/extra-traffic"), ['gb' => 10, 'method_id' => $method->id], $key());
        $topUp = fn(PaymentMethod $method): ResponseInterface => $this->send('POST', $this->storeApi($app, '/wallet/top-up'), ['amount' => 100000, 'method_id' => $method->id], $key());

        $this->phoneAt(purchase: true);
        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $buy($app, $wallet), message: 'a purchase');
        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $build($wallet), message: 'a service built is a purchase');
        $this->assertNotAskedTheNumber($renew($wallet), 'a renewal is no moment of theirs');
        $this->assertNotAskedTheNumber($topUp($card), 'nor a card-to-card payment');
        $this->assertNotAskedTheNumber($this->postJson($this->storeApi($app, '/gift-codes/redeem'), ['code' => 'NOWRUZ7K3M9QXAPT']), 'a gift code is no moment');
        $this->assertNotAskedTheNumber($this->postJson($this->storeApi($app, '/subscriptions/claim'), ['link' => 'https://sub.example.com/sub/abc']), 'nor a service added by its link');
        $this->assertNotAskedTheNumber($buy($this->website, $wallet), 'the website asks no number of an order');

        $this->phoneAt(renewal: true);
        $this->assertNotAskedTheNumber($buy($app, $wallet), 'a purchase is no moment of theirs now');
        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $renew($wallet), message: 'a renewal');
        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $extra($wallet), message: 'an extra counts as a renewal');

        $this->phoneAt(card: true);
        $this->assertNotAskedTheNumber($buy($app, $wallet), 'a purchase from the wallet');
        $this->assertNotAskedTheNumber($renew($wallet), 'a renewal from the wallet');
        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $buy($app, $card), message: 'a purchase paid by card');
        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $renew($card), message: 'a renewal paid by card');
        $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $topUp($card), message: 'a top-up paid by card');
        $this->assertNotAskedTheNumber($buy($this->website, $card), 'the website asks no number of a card payment either');

        $this->ali->forceFill(['phone' => '+989121234567'])->save();
        $this->assertNotAskedTheNumber($buy($app, $card), 'verified in the bot, they may');
    }

    /**
     * Asked only at the moments chosen, a free test waits for the number — through either key — with its moment on
     * alone, and the wheel never.
     */
    public function testAtTheMomentsChosenAFreeTestWaitsForTheNumberOnlyAtItsOwn(): void
    {
        $app = $this->miniApp();

        $this->phoneAt(purchase: true, renewal: true, card: true);
        foreach ([$this->website, $app] as $door) {
            self::assertNull($this->decode($this->get($this->storeApi($door, '/test-account')))['refusal'], 'a free test is no moment of theirs');
        }

        $this->phoneAt(test: true);
        foreach ([$this->website, $app] as $door) {
            self::assertSame(['offers' => [], 'refusal' => CustomerGates::NO_PHONE], $this->decode($this->get($this->storeApi($door, '/test-account'))));
            $this->assertRefusedUnder('status', CustomerGates::NO_PHONE, $this->postJson($this->storeApi($door, '/test-account'), ['server_id' => 1]));
        }

        $this->phoneAt(purchase: true, renewal: true, test: true, card: true);
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/wheel/spin'))->getStatusCode(), 'a spin is none of the moments');
    }

    /**
     * A customer support excused from the number is never asked it — before everything or at a moment, through either
     * key — and `GET /me` says so.
     */
    public function testACustomerExcusedFromTheNumberIsNeverAskedIt(): void
    {
        $app = $this->miniApp();
        $server = Server::query()->firstOrFail();
        $plan = $this->plan([], $server);
        $card = $this->cardMethod();
        $tries = 0;
        $order = function () use ($app, $plan, $server, $card, &$tries): ResponseInterface {
            $tries++;

            return $this->send('POST', $this->storeApi($app, '/orders'), ['plan_id' => $plan->id, 'server_id' => $server->id, 'method_id' => $card->id], ['Idempotency-Key' => "order-{$tries}"]);
        };
        $this->ali->forceFill(['phone_exempt' => true])->save();
        self::assertTrue($this->decode($this->get($this->storeApi($app, '/me')))['customer']['phone_exempt']);

        $this->botSettings('general', ['enabled' => true, 'phone_required' => true, 'phone_scope' => 'everywhere']);
        $this->assertNotAskedTheNumber($order(), 'before everything');
        self::assertNull($this->decode($this->get($this->storeApi($this->website, '/test-account')))['refusal']);
        self::assertSame(200, $this->postJson($this->storeApi($app, '/wheel/spin'))->getStatusCode());

        $this->phoneAt(purchase: true, renewal: true, test: true, card: true);
        $this->assertNotAskedTheNumber($order(), 'at a moment');
        self::assertNull($this->decode($this->get($this->storeApi($app, '/test-account')))['refusal']);
    }

    /** A bot admin passes every gate, and their own `GET /me` says the number is never asked of them. */
    public function testABotAdminPassesEveryGate(): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true]);
        $this->ali->forceFill(['role' => UserRole::Admin])->save();

        self::assertNull($this->decode($this->get($this->storeApi($this->website, '/test-account')))['refusal']);
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/wheel/spin'))->getStatusCode());
        self::assertTrue($this->decode($this->get($this->storeApi($this->miniApp(), '/me')))['customer']['phone_exempt'], 'the mini app offers them no «ثبت شماره تلفن»');
        self::assertFalse($this->ali->refresh()->phone_exempt, 'the row keeps what support set');
    }

    /** The phone rule on, the number asked only at the moments switched on here («فقط موقع این کارها»). */
    private function phoneAt(bool $purchase = false, bool $renewal = false, bool $test = false, bool $card = false): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true, 'phone_scope' => 'moments', 'phone_on_purchase' => $purchase, 'phone_on_renewal' => $renewal, 'phone_on_test' => $test, 'phone_on_card' => $card, 'support_contact' => '']);
    }

    /** What the response answers — served or refused for its own reasons — is not the ask for their number. */
    private function assertNotAskedTheNumber(ResponseInterface $response, string $message): void
    {
        self::assertNotContains(CustomerGates::NO_PHONE, $this->decode($response)['errors']['status'] ?? [], "{$message}: {$response->getBody()}");
    }

    /** The free test is offered with why not, taken refused, and the wheel's spin refused — each in `$words`. */
    private function assertHeldBack(string $words): void
    {
        self::assertSame(['offers' => [], 'refusal' => $words], $this->decode($this->get($this->storeApi($this->website, '/test-account'))));
        $this->assertRefusedUnder('status', $words, $this->postJson($this->storeApi($this->website, '/test-account'), ['server_id' => 1]));
        $this->assertRefusedUnder('status', $words, $this->postJson($this->storeApi($this->website, '/wheel/spin')));
    }
}
