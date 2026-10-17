<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Forms\Fields\Field;
use App\Core\Http\Urls;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Controllers\GatewayWebhookController;
use App\Modules\Payments\Drivers\Variza\VarizaDriver;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\HttpTestCase;
use Tests\Support\FakeVariza;

/**
 * Variza, card-to-card it confirms itself: a checkout makes a payment link in Toman, the customer's browser sent back to
 * the callback address after; Variza answers no question, so its word is only its webhook, signed with the method's
 * secret, to the shop's one address for it — believed when the signature is a method's, kept on the payment and read
 * by its check(): paid for the order's sum, held for another; nothing heard is waiting while its page takes money, and
 * closed after (a late word still settles it).
 */
final class VarizaPaymentsTest extends HttpTestCase
{
    private User $customer;
    private PaymentMethod $method;
    private FakeVariza $variza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example', 'telegram.username' => 'amo_shop_bot']);
        $this->telegram();
        $this->variza = $this->variza();
        $this->customer = $this->customer();
        $this->method = $this->varizaMethod();
    }

    public function testItIsAnOnlineGatewayOfAKeyAWebhookSecretAndACard(): void
    {
        $driver = $this->service(VarizaDriver::class);
        $described = $driver->describe()->toArray();
        self::assertSame(['variza', 'واریزا'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'online', 'builtin' => false], $described['traits']);
        self::assertSame(['api_key' => 'secret', 'webhook_secret' => 'secret', 'card' => 'text'], array_column($described['fields'], 'type', 'name'));
        self::assertStringContainsString('https://shop.example/payments/webhooks/variza', $described['notes'][0], 'the address to paste in its profile');
        self::assertSame('کارت مقصد: تصادفی', $driver->summary(['card' => 'random']));
        self::assertSame('کارت مقصد: کارت‌های واریزا (تسویه تتر)', $driver->summary(['card' => 'variza']));
        self::assertSame('کارت مقصد: •••• 6037', $driver->summary(['card' => '6037']));

        // Typed in Persian or as Variza names it, kept as Variza names it.
        $card = array_values(array_filter($driver->describe()->form->fields, static fn(Field $field): bool => $field->name === 'card'))[0];
        foreach (['تصادفی' => 'random', ' واریزا ' => 'variza', 'RANDOM' => 'random', '۶۰۳۷' => '6037'] as $typed => $kept) {
            self::assertSame($kept, $card->read(['card' => $typed], null), $typed);
        }
        self::assertSame('تصادفی', $described['fields'][2]['placeholder'], 'what to type, in Persian');
    }

    public function testACheckoutMakesALinkInTomanThatSendsTheCustomerBack(): void
    {
        $result = $this->topUp('50000', $this->varizaMethod('واریزا ۶۰۳۷', card: '6037'));

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        $slug = $this->variza->onlyLink();
        $payment = $result->payment()->refresh();
        self::assertSame("https://variza.ir/pay/{$slug}", $result->payLink()->url, 'Variza\'s own page');
        self::assertSame(['50000.00', null], [$result->payLink()->amount, $result->payLink()->fee]);
        self::assertEqualsWithDelta(time() + 30 * 60, $result->payLink()->expiresAt?->getTimestamp(), 5);
        self::assertSame([
            'amount' => 50000,
            'return_url' => $this->service(OnlineInvoices::class)->callbackUrl($payment),
            'title' => sprintf("سفارش \u{200E}#%d", $payment->order_id),
            'expires_in' => '30m',
            'card_last_4' => '6037',
        ], $this->variza->links[$slug]['body'], 'in Toman, the customer back at the shop after');
        self::assertSame('Bearer ' . FakeVariza::KEY, $this->variza->calls[0]['authorization']);
        self::assertSame([$slug, ['slug' => $slug, 'amount' => 50000, 'card' => '6037']], [$payment->reference, $payment->invoice]);
    }

    public function testItsSignedWebhookIsItsWordAndThePaymentIsPaidOnce(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $slug = $this->variza->onlyLink();

        // Back before Variza said a word: nothing to believe yet.
        self::assertStringContainsString('هنوز تایید نشده است', (string) $this->browse($payment)->getBody());
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $webhook = $this->variza->webhook($slug);
        $answer = $this->webhook($webhook);
        self::assertSame([200, ['received' => true]], [$answer->getStatusCode(), $this->decode($answer)]);
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, $slug, OrderStatus::Fulfilled], [$payment->status, $payment->reference, $payment->order->status]);
        self::assertSame(['amount' => 50128, 'base_amount' => 50000, 'attempt_code' => '152688947983'], array_intersect_key($payment->invoice['heard'], ['amount' => 0, 'base_amount' => 0, 'attempt_code' => 0]));
        self::assertSame('50000.00', $this->customer->balance(), 'the order\'s sum: the few Toman above it are Variza\'s');

        // Sent again (Variza retries until it hears a 200): heard, nothing more.
        self::assertSame(200, $this->webhook($webhook)->getStatusCode());
        self::assertSame('50000.00', $this->customer->balance());
        self::assertStringContainsString('پرداخت شما تایید شد', (string) $this->browse($payment)->getBody());
        self::assertSame([], array_values(array_filter($this->variza->calls, static fn(array $call): bool => !str_ends_with($call['path'], '/pay'))), 'Variza is never asked');
    }

    public function testAWebhookNoMethodSignsChangesNothing(): void
    {
        $logs = $this->logs();
        $payment = $this->topUp('50000')->payment()->refresh();
        $slug = $this->variza->onlyLink();

        $forged = $this->variza->webhook($slug, secret: 'not-the-secret');
        $answer = $this->webhook($forged);
        self::assertSame([400, GatewayWebhookController::UNSIGNED], [$answer->getStatusCode(), $this->decode($answer)['message']]);
        self::assertTrue($logs->hasWarningThatContains('A variza webhook came whose signature no method checks'));

        // Signed, then changed on the way; or no signature at all.
        $signed = $this->variza->webhook($slug, ['amount' => 1000, 'base_amount' => 1000]);
        $changed = ['body' => str_replace('"amount":1000', '"amount":50128', $signed['body'])] + $signed;
        self::assertSame(400, $this->webhook($changed)->getStatusCode());
        $bare = $this->variza->webhook($slug);
        unset($bare['headers']['X-Webhook-Signature']);
        self::assertSame(400, $this->webhook($bare)->getStatusCode());

        $payment->refresh();
        self::assertSame([PaymentStatus::Pending, false], [$payment->status, isset($payment->invoice['heard'])]);
        self::assertSame(404, $this->webhook($this->variza->webhook($slug), 'nosuchgateway')->getStatusCode());

        // The address of a gateway the shop asks instead of hearing it: no webhook is taken there, and no secret blamed.
        $this->cubePayMethod();
        $asked = $this->webhook($this->variza->webhook($slug), 'cubepay');
        self::assertSame([404, GatewayWebhookController::UNKNOWN], [$asked->getStatusCode(), $this->decode($asked)['message']]);
        self::assertFalse($logs->hasWarningThatContains('A cubepay webhook came'));
    }

    public function testASignedWordOfAnotherSumIsHeldForSupport(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();

        $answer = $this->webhook($this->variza->webhook($this->variza->onlyLink(), ['amount' => 20064, 'base_amount' => 20000]));
        self::assertSame(200, $answer->getStatusCode(), 'heard: Variza need not send it again');
        $payment->refresh();
        self::assertSame([PaymentStatus::Pending, PaymentService::NOTE_HELD], [$payment->status, $payment->note]);
        self::assertTrue(OnlinePayments::awaitsSupport($payment));
        self::assertSame('0.00', $this->customer->balance());
    }

    public function testASignedEventThatIsNoPaymentsWordChangesNothing(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $slug = $this->variza->onlyLink();

        self::assertSame(200, $this->webhook($this->variza->webhook($slug, ['event' => 'payment.expired', 'status' => 'expired']))->getStatusCode());
        // A slug of no payment of the shop's: heard, and nothing to do.
        self::assertSame(200, $this->webhook($this->variza->webhook('aB3xY9kL2m'))->getStatusCode());

        $payment->refresh();
        self::assertSame([PaymentStatus::Pending, false], [$payment->status, isset($payment->invoice['heard'])]);
    }

    public function testNothingHeardWaitsWhileItsPageIsOpenThenClosesAndALateWordStillSettles(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();

        self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment), 'the customer may still be paying');

        Carbon::setTestNow(now()->addMinutes(31));
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_LINK_CLOSED], [$payment->refresh()->status, $payment->note]);

        // The bank's text came late, and Variza with it: still the customer's money.
        self::assertSame(200, $this->webhook($this->variza->webhook($this->variza->onlyLink()))->getStatusCode());
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testTwoMethodsOnOneAccountSignAlikeAndThePaymentIsTheOneThatHasIt(): void
    {
        $this->topUp('50000');
        $second = $this->topUp('30000', $this->varizaMethod('واریزا ۲', card: 'variza'))->payment()->refresh();

        self::assertSame(200, $this->webhook($this->variza->webhook((string) $second->reference))->getStatusCode());
        self::assertSame(PaymentStatus::Paid, $second->refresh()->status);
        self::assertSame('30000.00', $this->customer->balance());
        self::assertSame(1, Payment::query()->where('status', PaymentStatus::Pending->value)->count(), 'the first one still waits');
    }

    public function testAWrongKeyAnHttpShopOrNoVarizaOpensNothing(): void
    {
        $logs = $this->logs();

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->varizaMethod('واریزا', key: 'vz_live_wrongwrongwrong'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('Variza would not make a payment link (HTTP 401): Unauthenticated. — the API key is wrong'));

        $this->config(['app.url' => 'http://shop.example']);
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('40000', $this->varizaMethod('واریزا ۲'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('Variza sends a customer back only to an https address'));

        $this->config(['app.url' => 'https://shop.example']);
        $this->variza->down();
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('30000', $this->varizaMethod('واریزا ۳'))->outcome);
        self::assertSame([], $this->variza->links);
    }

    private function topUp(string $amount, ?PaymentMethod $method = null): CheckoutResult
    {
        $orders = $this->service(OrderService::class);

        return $this->service(Checkout::class)->pay($this->customer, $method ?? $this->method, OrderType::WalletTopUp, $amount, fn(?OrderKey $key): Order => $orders->openTopUp($this->customer, (int) $amount, $key));
    }

    /** The customer's browser back at the payment's callback address. */
    private function browse(Payment $payment): ResponseInterface
    {
        return $this->send('GET', (string) parse_url($this->service(OnlineInvoices::class)->callbackUrl($payment), PHP_URL_PATH), null, ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']);
    }

    /**
     * Variza's webhook as it posts it, its raw body as signed, to the shop's address for `$driver`'s.
     *
     * @param array{body: string, headers: array<string, string>} $webhook
     */
    private function webhook(array $webhook, string $driver = 'variza'): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost' . str_replace('{driver:[a-z0-9]+}', $driver, Urls::PAYMENT_WEBHOOK))
            ->withBody((new StreamFactory())->createStream($webhook['body']));
        foreach ($webhook['headers'] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app()->http()->handle($request);
    }
}
