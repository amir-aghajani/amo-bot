<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Payments\Deposits\Services\DepositSettings;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use Tests\BotTestCase;

/**
 * «تایید خودکار واریز» in the bot's card message: the exact sum's wording only for a payment that asks a sum of its own.
 * One the method's most left no room above its order's asks the price itself, which another payment may ask too: its
 * customer is asked for the receipt as ever.
 */
final class BotDepositsTest extends BotTestCase
{
    private Server $server;
    private Plan $plan;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->sellingServer();
        $this->plan = $this->plan(on: $this->server);
        $this->wallet($this->customer(), '500000.00');
        $this->card = $this->cardMethod();
        $this->service(DepositSettings::class)->enable(true);
    }

    public function testAPaymentLeftNoRoomForASumOfItsOwnAsksForTheReceipt(): void
    {
        $this->card->forceFill(['max_amount' => '120000.00'])->save();

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        self::assertSame('120000.00', Payment::query()->sole()->amount);
        self::assertSame([$this->cardInstructions('120000')], $this->said(), 'the receipt asked for, no word of the sum approving itself');
    }
}
