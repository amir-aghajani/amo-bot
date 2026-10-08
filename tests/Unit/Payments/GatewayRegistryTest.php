<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Core\Drivers\UnknownDriverException;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\DTO\CardTransfer;
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use Tests\TestCase;

/**
 * A method row read through its driver (the container's `payment.drivers`): how it settles and whether it is the shop's
 * own, by the driver's traits; its one line and its gateway, made from its settings — and a row whose driver is no
 * longer installed says nothing, and is no gateway at all.
 */
final class GatewayRegistryTest extends TestCase
{
    private GatewayRegistry $gateways;

    protected function setUp(): void
    {
        parent::setUp();

        $gateways = $this->service(GatewayRegistry::class);
        self::assertInstanceOf(GatewayRegistry::class, $gateways);
        $this->gateways = $gateways;
    }

    public function testAMethodIsReadThroughItsDriver(): void
    {
        [$card, $wallet, $gone] = self::methods();

        self::assertSame(['wallet', 'manual'], array_map(static fn(GatewayDriver $driver): string => $driver->key(), $this->gateways->all()));
        self::assertSame([GatewayKind::Manual, GatewayKind::Instant, null], [$this->gateways->kind('manual'), $this->gateways->kind('wallet'), $this->gateways->kind('paypal')]);
        self::assertSame([false, true, false], [$this->gateways->builtin('manual'), $this->gateways->builtin('wallet'), $this->gateways->builtin('paypal')]);
        self::assertSame([true, false, false], [$this->gateways->isManual($card), $this->gateways->isManual($wallet), $this->gateways->isManual($gone)]);
        self::assertSame(['6037 •••• •••• 1119 · AmoBot', null, null], [$this->gateways->summary($card), $this->gateways->summary($wallet), $this->gateways->summary($gone)]);
    }

    public function testAMethodsGatewayIsItsDriversMadeFromItsSettings(): void
    {
        [$card, $wallet, $gone] = self::methods();

        self::assertEquals(new CardTransfer('6037997700001119', 'AmoBot', ''), $this->gateways->forMethod($card)->initiate()->transfer, 'its note, kept before there was one, is none');
        self::assertTrue($this->gateways->forMethod($wallet)->initiate()->isInstant());

        $this->expectException(UnknownDriverException::class);
        $this->gateways->forMethod($gone);
    }

    /** @return array{PaymentMethod, PaymentMethod, PaymentMethod} A card, the wallet, and a method of a driver no longer installed */
    private static function methods(): array
    {
        return [
            new PaymentMethod(['driver' => 'manual', 'config' => ['card_number' => '6037997700001119', 'card_holder' => 'AmoBot']]),
            new PaymentMethod(['driver' => 'wallet', 'config' => []]),
            new PaymentMethod(['driver' => 'paypal', 'config' => ['card_number' => '6037997700001119']]),
        ];
    }
}
