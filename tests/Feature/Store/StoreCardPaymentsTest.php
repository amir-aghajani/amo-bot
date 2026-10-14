<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\ReceiptInReviewException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * A card payment on the website, as the bot takes one: the checkout's answer gives the card — or, while the shop takes
 * card payments in support's private chat, no card but the way to support (`contact`). One receipt at a time: while a
 * receipt of the customer's is in review, a card checkout and another receipt are each a 422 on `status` naming its
 * order, until the shop takes several.
 */
final class StoreCardPaymentsTest extends HttpTestCase
{
    /** A PNG of one pixel: a picture by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Website $website;
    private User $customer;
    private Server $server;
    private Plan $plan;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->customer = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->card = $this->cardMethod(overrides: ['config' => ['instructions' => 'فقط از کارت خودتان']]);
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheCheckoutGivesTheCardAndNoContact(): void
    {
        $transfer = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'card-1'))['checkout']['transfer'];

        self::assertSame(['card' => '6037997700001119', 'holder' => 'AmoBot', 'instructions' => 'فقط از کارت خودتان', 'contact' => null], array_intersect_key($transfer, array_flip(['card', 'holder', 'instructions', 'contact'])));
    }

    public function testInSupportsPrivateChatTheCheckoutGivesNoCardButTheWayToSupport(): void
    {
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $this->botSettings('payments', ['card_to_support' => true]);

        $response = $this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'card-1');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $checkout = $this->decode($response)['checkout'];
        self::assertSame('transfer', $checkout['outcome']);
        self::assertSame([
            'payment_id' => Payment::query()->sole()->id,
            'amount' => '120000.00',
            'card' => null,
            'holder' => null,
            'bank' => null,
            'instructions' => 'فقط از کارت خودتان',
            'contact' => ['text' => '@amo_support', 'url' => 'https://t.me/amo_support'],
        ], $checkout['transfer']);
        self::assertSame([PaymentStatus::Pending, null], [Payment::query()->sole()->status, Payment::query()->sole()->card_id], 'support approves it in the panels; no card was picked for it');

        // Made again once the shop shows its cards again: the same payment, now shown a card.
        $this->botSettings('payments', ['card_to_support' => false]);
        $transfer = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'card-1'))['checkout']['transfer'];
        self::assertSame([Payment::query()->sole()->id, '6037997700001119', null], [$transfer['payment_id'], $transfer['card'], $transfer['contact']]);
    }

    public function testWhileAReceiptIsInReviewACardCheckoutAndAnotherReceiptAreRefusedUntilTheShopTakesSeveral(): void
    {
        $inReview = $this->receipt(Payment::query()->findOrFail($this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'card-1'))['checkout']['transfer']['payment_id']));
        $other = $this->cardPayment($this->purchaseOrder($this->customer, $this->plan, $this->server, ['amount' => '90000.00']), $this->card);
        $refusal = sprintf(ReceiptInReviewException::MESSAGE, $inReview->order_id);

        $checkout = $this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'card-2');
        self::assertSame(422, $checkout->getStatusCode());
        self::assertSame(['status' => [$refusal]], $this->decode($checkout)['errors']);
        self::assertSame(2, Order::query()->count(), 'nothing ordered');

        $upload = $this->upload($this->storeApi($this->website, "/payments/{$other->id}/receipt"), 'file', 'receipt.png', (string) base64_decode(self::PNG));
        self::assertSame(422, $upload->getStatusCode());
        self::assertSame(['status' => [$refusal]], $this->decode($upload)['errors']);
        self::assertSame(PaymentStatus::Pending, $other->refresh()->status);

        // Made again with its key, the checkout whose receipt is in review is answered as before.
        self::assertSame(200, $this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'card-1')->getStatusCode());

        $this->botSettings('payments', ['receipt_single' => false]);
        self::assertSame(200, $this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'card-2')->getStatusCode());
        self::assertSame(200, $this->upload($this->storeApi($this->website, "/payments/{$other->id}/receipt"), 'file', 'receipt.png', (string) base64_decode(self::PNG))->getStatusCode());
    }
}
