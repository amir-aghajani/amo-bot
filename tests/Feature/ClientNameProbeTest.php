<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Drivers\PasarGuard\PasarGuardApiException;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Exceptions\MoveException;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * The name a panel refused (ProvisioningService::named()): refused as taken — HTTP 409, the Marzban family's —, it gives
 * way to the customer's next with no look (PasarGuard 5 shows an admin limited to its own users no other admin's user);
 * refused otherwise, the panel is asked about it, and a client by that name it will not show — another admin's user, on
 * a panel where the shop's admin sees only its own (PanelApiException::hidesAnothersClient()) — has the name all the
 * same. Any other failure of that look, a permission the admin lacks among them, is the delivery's.
 */
final class ClientNameProbeTest extends DatabaseTestCase
{
    private User $customer;
    private Plan $plan;
    private Server $berlin;
    private Server $paris;

    /** @var list<string> The makes and looks that reached the panel answers() plays, in order */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->customer(['username' => 'customer', 'telegram_id' => 42]);
        $this->berlin = $this->sellingServer('Berlin');
        $this->paris = $this->sellingServer('Paris');
        $this->plan = $this->plan(['price' => '50000.00'], $this->berlin);
    }

    public function testANameThePanelRefusesAndWillNotShowGivesWayToTheNext(): void
    {
        $this->answers($this->berlin, ['createClient' => [self::refusal(400, 'duplicate name')], 'findClient' => [self::refusal(403, "You're not allowed")]]);

        $order = $this->buy($this->customer, $this->plan, $this->berlin);

        self::assertSame(['createClient', 'findClient', 'createClient'], $this->calls, 'the panel asked about the name once it refused it');
        self::assertSame(['customer_2'], $this->created());
        self::assertSame('customer_2', $order->subscription?->remote_name);
    }

    public function testANameThePanelRefusesAsTakenGivesWayToTheNextWithNoLook(): void
    {
        // PasarGuard 5: another admin's user is a 404 to an admin limited to its own users, yet its name is refused.
        $this->answers($this->berlin, ['createClient' => [self::refusal(409, 'User already exists')], 'findClient' => [self::refusal(500, 'Internal Server Error')]]);

        $order = $this->buy($this->customer, $this->plan, $this->berlin);

        self::assertSame(['createClient', 'createClient'], $this->calls, 'a look could say no more: the name is taken');
        self::assertSame(['customer_2'], $this->created());
        self::assertSame('customer_2', $order->subscription?->remote_name);
    }

    public function testARefusalOfAnotherKindWithNoClientByThatNameFailsThePurchase(): void
    {
        $this->answers($this->berlin, ['createClient' => [self::refusal(400, 'Group not found')]]);
        $this->service(WalletService::class)->credit($this->customer, '50000', 'test');
        $order = $this->service(OrderService::class)->openPurchase($this->customer, $this->plan, $this->berlin);

        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        self::assertSame(['createClient', 'findClient'], $this->calls, 'no next name for a refusal that is no taken name');
        self::assertSame([], $this->created());
    }

    /** @return iterable<string, array{ProviderException}> */
    public static function probeFailures(): iterable
    {
        yield 'a refusal of another kind' => [self::refusal(500, 'Internal Server Error')];
        yield 'an answer that is not the API' => [new UnexpectedResponseException(500, 'Internal Server Error')];
        yield 'no answer' => [new ConnectionException(ConnectionFailure::Timeout, 'Operation timed out')];
        yield 'a permission the admin lacks' => [PasarGuardApiException::refused('GET', '/api/user/by-username/customer_1', 403, 'Permission denied: users.read')];
    }

    #[DataProvider('probeFailures')]
    public function testALookAtTheRefusedNameThatFailsOtherwiseFailsThePurchase(ProviderException $failure): void
    {
        $this->answers($this->berlin, ['createClient' => [self::refusal(400, 'duplicate name')], 'findClient' => [$failure]]);
        $this->service(WalletService::class)->credit($this->customer, '50000', 'test');
        $order = $this->service(OrderService::class)->openPurchase($this->customer, $this->plan, $this->berlin);

        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        self::assertSame(['createClient', 'findClient'], $this->calls, 'no next name: whose the name is, the panel could not say');
        self::assertSame([], $this->created());
    }

    public function testAMoveWhoseNameTheTargetRefusesAndWillNotShowGetsTheCustomersNext(): void
    {
        $subscription = $this->mirrored($this->subscription($this->customer, $this->plan, $this->berlin, 'customer_1'));
        // Another admin's user on the target: the panel refuses its name, and refuses to show it, however often asked.
        FakeProvider::put($this->paris, new ClientInfo(name: 'customer_1', enabled: true, totalBytes: 0, expiry: Expiry::never(), subscriptionUrl: null));
        $this->answers($this->paris, ['findClient' => self::refusal(403, "You're not allowed")]);

        $this->service(ProvisioningService::class)->move($subscription, $this->paris, leavePrevious: false);

        self::assertContains('findClient', $this->calls, 'the target asked about the name it refused');
        self::assertSame(['customer_2'], $this->created());
        self::assertSame([$this->paris->id, 'customer_2'], [$subscription->refresh()->server_id, $subscription->remote_name]);
        self::assertSame([['server' => $this->berlin->id, 'name' => 'customer_1']], FakeProvider::$deleted, 'the previous client off its panel, the other admin\'s user left alone');
    }

    public function testAMoveWhoseLookAtTheRefusedNameFailsOtherwiseStaysWhereItWas(): void
    {
        $subscription = $this->mirrored($this->subscription($this->customer, $this->plan, $this->berlin, 'customer_1'));
        $this->answers($this->paris, ['createClient' => [self::refusal(400, 'duplicate name')], 'findClient' => [self::refusal(500, 'Internal Server Error')]]);

        try {
            $this->service(ProvisioningService::class)->move($subscription, $this->paris, leavePrevious: false);
            self::fail('the target could not say whose the name is');
        } catch (MoveException $e) {
            self::assertInstanceOf(PanelApiException::class, $e->getPrevious());
        }

        self::assertSame(['createClient', 'findClient'], $this->calls);
        self::assertSame([], $this->created());
        self::assertSame([$this->berlin->id, 'customer_1'], [$subscription->refresh()->server_id, $subscription->remote_name]);
        self::assertSame([], FakeProvider::$deleted);
    }

    /**
     * The server's panel fails the calls named in `$failures` as they reach it: with the next of a list, or with the one
     * failure every time. Its makes and looks that reach it are recorded (`$calls`).
     *
     * @param array<string, list<ProviderException>|ProviderException> $failures
     */
    private function answers(Server $server, array $failures): void
    {
        FakeProvider::$onCall = function (int $id, string $call) use ($server, &$failures): void {
            if ($id !== $server->id) {
                return;
            }
            if (in_array($call, ['createClient', 'findClient'], true)) {
                $this->calls[] = $call;
            }
            $failure = is_array($failures[$call] ?? null) ? array_shift($failures[$call]) : $failures[$call] ?? null;
            if ($failure !== null) {
                throw $failure;
            }
        };
    }

    /** A panel's refusal with its status and words, as a connector throws it (Marzban's 409, 403, 500; another's 400). */
    private static function refusal(int $status, string $words): PanelApiException
    {
        return new PanelApiException("The panel refused: {$words}", $status, $words);
    }

    /** @return list<string> The names the panels made clients under, in order. */
    private function created(): array
    {
        return array_map(static fn(array $made): string => $made['spec']->name, FakeProvider::$created);
    }
}
