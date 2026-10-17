<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Modules\Payments\Drivers\CubePay\CubePayDriver;
use App\Modules\Payments\Drivers\CubePay\CubePayGateway;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\DTO\PayLink;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The CubePay driver: an online gateway of the merchant's token, a secret, and the fee passed on to customers (its
 * summary line); and the gateway it makes, which holds CubePay's answers to what its documentation says before believing
 * them — a page on its own host, a card payment's authority, a sum no smaller than the order's (the card shown only with
 * it), a verification that says this order's id and amount.
 */
final class CubePayDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app(); // the models' casts
    }

    public function testItIsAnOnlineGatewayOfASecretTokenAndTheFeePassedOn(): void
    {
        $driver = new CubePayDriver(new Client());
        $described = $driver->describe()->toArray();

        self::assertSame(['cubepay', 'CubePay'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'online', 'builtin' => false], $described['traits']);
        $fields = array_column($described['fields'], null, 'name');
        self::assertSame(['token', 'fee_percent', 'fee_fixed'], array_keys($fields));
        self::assertSame(['secret', 'text', 'number'], [$fields['token']['type'], $fields['fee_percent']['type'], $fields['fee_fixed']['type']]);
        self::assertNull($driver->summary(['token' => 'cp-1234']), 'no screen shows the token');
        self::assertSame('کارمزد مشتری: ۳.۱٪ + ۵۰۰ تومان', $driver->summary(['token' => 'cp-1234', 'fee_percent' => '3.1', 'fee_fixed' => 500]));
    }

    public function testTheFeesHintSaysHowAPercentIsPassedOnInFullAndThatTheCardsFeeIsFixed(): void
    {
        $fields = array_column((new CubePayDriver(new Client()))->describe()->toArray()['fields'], null, 'name');
        $hint = (string) $fields['fee_percent']['hint'];

        // CubePay takes a percent of the whole sum: 3% is passed on in full at 3 ÷ 97 — 3.1.
        // What the owner types is quoted as typed, in Latin digits; a quantity in words is in Persian ones.
        self::assertStringContainsString('برای کارمزد ۳ درصدی، «3.1»', $hint);
        self::assertStringContainsString('خالی یا «0» یعنی هیچ', $hint);
        self::assertStringContainsString('کارمزد کارت به کارت CubePay مبلغی ثابت است', $hint);
        self::assertStringNotContainsString('9.9', $hint, 'no word of a VIP fee most merchants never pay');
    }

    public function testTheTokenIsRequiredAndLatinWithoutSpaces(): void
    {
        $form = (new CubePayDriver(new Client()))->describe()->form;
        $nothing = static fn(Field $field): mixed => null;

        self::assertSame('cp-test-token-1234', $form->check(['token' => 'cp-test-token-1234', 'fee_fixed' => 0], $nothing)['token']);
        foreach (['' => 'توکن API را از ربات CubePay وارد کنید.', 'توکن فارسی' => 'توکن API را همان‌طور که ربات CubePay نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.', 'two words' => null] as $typed => $refusal) {
            try {
                $form->check(['token' => $typed, 'fee_fixed' => 0], $nothing);
                self::fail("«{$typed}» is no token");
            } catch (ValidationException $e) {
                self::assertSame(['token'], array_keys($e->errors()));
                if ($refusal !== null) {
                    self::assertSame([$refusal], $e->errors()['token']);
                }
            }
        }
    }

    public function testAnOpeningItCannotBelieveIsRefused(): void
    {
        $answers = [
            'a page on another host' => ['success' => true, 'method' => 'crypto', 'pay_page_url' => 'https://evil.example/pay'],
            'a page over http' => ['success' => true, 'method' => 'crypto', 'pay_page_url' => 'http://cubevps.ir/crypto/pay.php?token=1'],
            'a card payment with no authority' => ['success' => true, 'method' => 'card', 'pay_page_url' => 'https://cubevps.ir/smspay/pay.php?authority='],
            'no way it made' => ['success' => true, 'method' => 'cash', 'pay_page_url' => 'https://cubevps.ir/pay'],
            'not a success' => ['success' => false, 'message' => 'ظرفیت پر است'],
        ];
        foreach ($answers as $case => $answer) {
            try {
                self::gateway(new Response(200, [], (string) json_encode($answer)))->open(new Payment(), self::request());
                self::fail($case);
            } catch (GatewayUnavailableException $e) {
                self::assertSame(GatewayUnavailableException::UNAVAILABLE, $e->getMessage(), $case);
            }
        }
    }

    public function testAnErrorStatusIsCubePaysAnswerNeverAnOutage(): void
    {
        // A client left to throw on a 4xx, as Guzzle's default is: the status is read all the same.
        $gateway = new CubePayGateway('cp-test-token-1234', new Client(['handler' => HandlerStack::create(new MockHandler([new Response(401, [], (string) json_encode(['success' => false, 'message' => 'توکن نامعتبر است.']))]))]));

        try {
            $gateway->open(new Payment(), self::request());
            self::fail('a refusal');
        } catch (GatewayUnavailableException $e) {
            self::assertSame('CubePay would not open an order (HTTP 401): توکن نامعتبر است. — the API token is wrong: set the one @cubepy_bot gives in the payment method', $e->detail);
        }
    }

    public function testASumSmallerThanTheOrdersIsNotTakenAsTheSumAsked(): void
    {
        $invoice = self::gateway(new Response(200, [], (string) json_encode([
            'success' => true, 'method' => 'card', 'authority' => str_repeat('a1', 25),
            'pay_page_url' => 'https://cubevps.ir/smspay/pay.php?authority=' . str_repeat('a1', 25),
            'pay_amount_toman' => 49000, 'expires_in_minutes' => 30, 'card' => ['number' => '6219-8619', 'holder' => 'x'],
        ])))->open(new Payment(), self::request());

        self::assertNull($invoice->details['pay_amount'], 'less than the order\'s 50,000: not believed');
        self::assertNull($invoice->details['card'], 'not a card number');
        self::assertEqualsWithDelta(Carbon::now()->addMinutes(30)->getTimestamp(), $invoice->expiresAt?->getTimestamp(), 5);
    }

    public function testTheCardIsShownOnlyWithTheExactSumItsPageAsks(): void
    {
        $card = ['number' => '6219861900412221', 'holder' => 'Sara'];
        foreach (['missing' => [], 'a string' => ['pay_amount_toman' => '50037'], 'below the price' => ['pay_amount_toman' => 49000]] as $case => $sum) {
            $link = self::opened(['card' => $card] + $sum);
            self::assertNull($link->card, "a sum {$case}: a transfer of the price would never be matched to it");
            self::assertSame('50000.00', $link->amount, $case);
        }

        $link = self::opened(['card' => $card, 'pay_amount_toman' => 50037]);
        self::assertSame(['6219861900412221', 'Sara', null], [$link->card?->card, $link->card?->holder, $link->card?->bank]);
        self::assertSame('50037.00', $link->amount);
    }

    public function testACardWithNoHolderShowsNone(): void
    {
        foreach ([['number' => '6219861900412221'], ['number' => '6219861900412221', 'holder' => '  '], ['number' => '6219861900412221', 'holder' => 7]] as $card) {
            $link = self::opened(['card' => $card, 'pay_amount_toman' => 50037]);
            self::assertSame('6219861900412221', $link->card?->card);
            self::assertNull($link->card->holder, (string) json_encode($card));
        }
    }

    public function testAVerificationOfAnotherOrderOrASmallerAmountIsHeld(): void
    {
        foreach ([['order_id' => 'amo-2-abc', 'amount' => 500000], ['order_id' => 'amo-1-abc', 'amount' => 400000]] as $said) {
            self::assertHeld($said);
        }
    }

    public function testAVerificationThatDoesNotSayTheOrderOrItsAmountIsHeld(): void
    {
        foreach ([[], ['order_id' => 'amo-1-abc'], ['amount' => 500000], ['order_id' => 'amo-1-abc', 'amount' => '500000'], ['order_id' => null, 'amount' => 500000], ['order_id' => 1, 'amount' => 500000]] as $said) {
            self::assertHeld($said);
        }

        $paid = self::gateway(new Response(200, [], (string) json_encode(['success' => true, 'status' => 'verified', 'order_id' => 'amo-1-abc', 'amount' => 500000])))->check(self::payment());
        self::assertSame('abcdef1234', $paid->reference, 'this order, this amount: paid');
    }

    /** @param array<string, mixed> $said What CubePay's first verification says besides its yes */
    private static function assertHeld(array $said): void
    {
        try {
            self::gateway(new Response(200, [], (string) json_encode(['success' => true, 'status' => 'verified'] + $said)))->check(self::payment());
            self::fail('verified for another order or amount: ' . json_encode($said));
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held, (string) json_encode($said));
            self::assertStringContainsString('another order or amount', $e->detail);
        }
    }

    /**
     * The link a card payment CubePay opened as `$answer` says (and asked to show the card in the bot) is shown with.
     *
     * @param array<string, mixed> $answer
     */
    private static function opened(array $answer): PayLink
    {
        $gateway = self::gateway(new Response(200, [], (string) json_encode($answer + [
            'success' => true, 'method' => 'card', 'authority' => str_repeat('a1', 25), 'show_card_in_bot' => true,
            'pay_page_url' => 'https://cubevps.ir/smspay/pay.php?authority=' . str_repeat('a1', 25),
        ])));
        $invoice = $gateway->open(new Payment(), self::request());
        $payment = (new Payment())->forceFill(['amount' => '50000.00', 'link' => $invoice->url, 'link_expires_at' => $invoice->expiresAt, 'invoice' => $invoice->details]);

        return $gateway->initiate($payment)->link ?? throw new \LogicException('no link');
    }

    private static function payment(): Payment
    {
        return (new Payment())->forceFill(['amount' => '50000.00', 'link' => 'https://cubevps.ir/smspay/pay.php?authority=x', 'invoice' => ['order_id' => 'amo-1-abc', 'authority' => 'abcdef1234', 'price' => 50000]]);
    }

    private static function gateway(Response ...$answers): CubePayGateway
    {
        return new CubePayGateway('cp-test-token-1234', new Client(['handler' => HandlerStack::create(new MockHandler($answers)), 'http_errors' => false]));
    }

    private static function request(): OnlineRequest
    {
        return new OnlineRequest('amo-1-abc', 50000, 'https://shop.example/payments/callback/1/secret', 'سفارش #1');
    }
}
