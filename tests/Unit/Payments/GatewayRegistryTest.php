<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Core\Drivers\UnknownDriverException;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\DTO\CardTransfer;
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentCard;
use App\Modules\Payments\Models\PaymentMethod;
use Tests\TestCase;

/**
 * GatewayRegistry: a method row read through its driver — its kind and built-in trait, its summary and gateway made from
 * its settings, the gateway showing a payment its card — and a row whose driver is no longer installed, which has none
 * of them.
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

        self::assertSame(['wallet', 'outside', 'manual', 'cubepay', 'zarinpal', 'atlaspay', 'abangateway', 'plisio', 'aqayepardakht', 'variza'], array_map(static fn(GatewayDriver $driver): string => $driver->key(), $this->gateways->all()));
        self::assertSame([GatewayKind::Manual, GatewayKind::Instant, GatewayKind::Online, null], [$this->gateways->kind('manual'), $this->gateways->kind('wallet'), $this->gateways->kind('cubepay'), $this->gateways->kind('paypal')]);
        self::assertSame([false, true, false], [$this->gateways->builtin('manual'), $this->gateways->builtin('wallet'), $this->gateways->builtin('paypal')]);
        self::assertSame([true, false, false], [$this->gateways->isManual($card), $this->gateways->isManual($wallet), $this->gateways->isManual($gone)]);
        self::assertSame([null, null, null], [$this->gateways->summary($card), $this->gateways->summary($wallet), $this->gateways->summary($gone)], 'a card method\'s cards are rows of their own: its settings say nothing of them');
    }

    public function testAMethodsGatewayIsItsDriversMadeFromItsSettings(): void
    {
        [$card, $wallet, $gone] = self::methods();

        $payment = (new Payment())->setRelation('card', new PaymentCard(['number' => '6037997700001119', 'holder' => 'AmoBot', 'bank' => 'ملت']));
        self::assertEquals(new CardTransfer('6037997700001119', 'AmoBot', '', 'ملت'), $this->gateways->forMethod($card)->initiate($payment)->transfer, 'the card the payment was shown; its note, kept before there was one, is none');
        self::assertNull($this->gateways->forMethod($wallet)->initiate(new Payment())->transfer, 'the wallet settles at once');

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
