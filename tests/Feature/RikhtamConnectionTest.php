<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Session\Session;
use App\Modules\Admin\Api\PaymentRikhtamController;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Payments\Deposits\Services\Deposits;
use App\Modules\Payments\Deposits\Services\DepositSettings;
use App\Modules\Payments\Deposits\Services\RikhtamDeposits;
use App\Modules\Payments\Drivers\Rikhtam\RikhtamApi;
use App\Modules\Payments\Enums\DepositStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Deposit;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Settings\Models\Setting;
use App\Modules\Users\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\HttpTestCase;
use Tests\Support\FakeRikhtam;

/**
 * The owner connects Rikhtam with its gateway's «کلید امنیتی»: the shop registers its own address with it, keeps the key
 * encrypted only once Rikhtam took the address, and listens; each refusal is said in the owner's words, nothing kept. A
 * card payment is offered the deposits Rikhtam holds, and one attached to it — Rikhtam sending it to the shop's address
 * in the middle of the call, while the request holds no session and no transaction — approves it.
 */
final class RikhtamConnectionTest extends HttpTestCase
{
    private const CALLBACK = 'https://shop.example/webhooks/rikhtam/' . Bot::MAIN;

    private Server $server;
    private Plan $plan;
    private PaymentMethod $card;
    private User $ali;
    private FakeRikhtam $rikhtam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example']);
        $this->telegram();
        $this->withoutQr();
        $this->rikhtam = $this->rikhtam();
        $this->rikhtam->domain = 'shop.example';
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '150000.00'], $this->server);
        $this->card = $this->cardMethod();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->loginAsAdmin();
    }

    public function testTheKeyConnectsRikhtamOnceItTookTheShopsAddress(): void
    {
        $before = $this->decode($this->get('/api/admin/deposits/settings'));
        self::assertSame([false, ['connected' => false, 'domain' => null, 'project_id' => null, 'callback_url' => self::CALLBACK, 'connected_at' => null]], [$before['enabled'], $before['rikhtam']]);
        self::assertSame(['forapp'], array_column($before['sources'], 'key'), 'Rikhtam is no app the shop\'s key is pasted in');

        $settings = $this->decode($this->putJson('/api/admin/deposits/settings/rikhtam', ['secret' => ' ' . FakeRikhtam::KEY . ' ']));

        self::assertSame(self::CALLBACK, $this->rikhtam->hooked);
        self::assertSame([true, true, 'shop.example', FakeRikhtam::PROJECT, self::CALLBACK], [$settings['enabled'], $settings['rikhtam']['connected'], $settings['rikhtam']['domain'], $settings['rikhtam']['project_id'], $settings['rikhtam']['callback_url']], 'switched on with it');
        self::assertNotNull($settings['rikhtam']['connected_at']);
        self::assertTrue($this->service(DepositSettings::class)->acceptsRikhtam(FakeRikhtam::KEY));
        self::assertStringNotContainsString(FakeRikhtam::KEY, (string) json_encode(Setting::query()->pluck('value')->all()), 'kept encrypted');
    }

    public function testEachRefusalIsSaidAndNothingKept(): void
    {
        $this->assertRefusedUnder('secret', RikhtamDeposits::BAD_KEY, $this->putJson('/api/admin/deposits/settings/rikhtam', ['secret' => 'rk_not_this_one']));
        $this->assertRefusedUnder('secret', RikhtamDeposits::NO_SECRET, $this->putJson('/api/admin/deposits/settings/rikhtam', ['secret' => '  ']));

        $this->rikhtam->domain = 'other.example';
        $wrong = $this->putJson('/api/admin/deposits/settings/rikhtam', ['secret' => FakeRikhtam::KEY]);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString("آدرس فروشگاه (\u{200E}shop.example) روی دامنه‌ای نیست", $this->decode($wrong)['errors']['secret'][0]);
        self::assertStringContainsString(self::CALLBACK, $this->decode($wrong)['errors']['secret'][0], 'the address to put in the gateway\'s «آدرس کال‌بک»');

        $this->config(['app.url' => 'https://localhost']);
        $this->assertRefusedUnder('secret', RikhtamDeposits::BAD_URL, $this->putJson('/api/admin/deposits/settings/rikhtam', ['secret' => FakeRikhtam::KEY]));

        $this->config(['app.url' => 'http://shop.example']);
        $calls = count($this->rikhtam->calls);
        $this->assertRefusedUnder('secret', RikhtamDeposits::NOT_HTTPS, $this->putJson('/api/admin/deposits/settings/rikhtam', ['secret' => FakeRikhtam::KEY]));
        self::assertCount($calls, $this->rikhtam->calls, 'refused before Rikhtam is asked');

        $this->config(['app.url' => 'https://shop.example']);
        $this->rikhtam->domain = 'shop.example';
        $this->rikhtam->down();
        $this->assertRefusedUnder('secret', RikhtamDeposits::OUT_OF_REACH, $this->putJson('/api/admin/deposits/settings/rikhtam', ['secret' => FakeRikhtam::KEY]));

        $settings = $this->decode($this->get('/api/admin/deposits/settings'));
        self::assertSame([false, false, null], [$settings['enabled'], $settings['rikhtam']['connected'], $this->rikhtam->hooked]);
    }

    public function testTheAddressIsRegisteredAgainAfterAMoveAndForgotten(): void
    {
        $this->assertRefusedUnder('secret', RikhtamDeposits::NOT_CONNECTED, $this->postJson('/api/admin/deposits/settings/rikhtam/hook'));
        $this->putJson('/api/admin/deposits/settings/rikhtam', ['secret' => FakeRikhtam::KEY]);

        $this->config(['app.url' => 'https://bot.shop.example']);
        $moved = $this->decode($this->postJson('/api/admin/deposits/settings/rikhtam/hook'));
        self::assertSame(['https://bot.shop.example/webhooks/rikhtam/' . Bot::MAIN, true], [$this->rikhtam->hooked, $moved['rikhtam']['connected']], 'a subdomain of the gateway\'s');

        $forgotten = $this->decode($this->deleteJson('/api/admin/deposits/settings/rikhtam'));
        self::assertSame([false, null, true], [$forgotten['rikhtam']['connected'], $forgotten['rikhtam']['connected_at'], $forgotten['enabled']], 'the switch stays as it was');
        self::assertSame(403, $this->rikhtamSends($this->rikhtam->healthTest())->getStatusCode(), 'its key opens nothing any more');
    }

    public function testACardPaymentIsOfferedTheDepositsRikhtamHoldsClosestFirst(): void
    {
        $payment = $this->openCardPayment();
        $path = "/api/admin/payments/{$payment->id}/rikhtam";
        self::assertFalse($this->decode($this->get("/api/admin/payments/{$payment->id}"))['payment']['actions']['rikhtam']);
        self::assertSame(['connected' => false, 'deposits' => []], $this->decode($this->get($path)));
        self::assertSame([], $this->rikhtam->calls, 'not connected: nothing asked');

        $this->connect();
        self::assertTrue($this->decode($this->get("/api/admin/payments/{$payment->id}"))['payment']['actions']['rikhtam']);
        $toman = (int) $payment->amount;
        $this->rikhtam->pending = [
            ['uid' => 'far', 'amount' => 90000, 'time' => 1_794_000_000, 'account' => '6037997700001119'],
            ['uid' => 'near', 'amount' => $toman, 'time' => 1_794_000_100, 'account' => '1119'],
            ['uid' => 'close', 'amount' => $toman + 2, 'time' => 1_794_000_200, 'account' => ''],
        ];

        $listed = $this->decode($this->get($path));

        self::assertTrue($listed['connected']);
        self::assertSame(['near', 'close', 'far'], array_column($listed['deposits'], 'uid'));
        self::assertSame(['uid' => 'far', 'amount' => '90000.00', 'received_at' => '2026-11-06T21:20:00+00:00', 'account' => '1119', 'covers' => false], $listed['deposits'][2]);
        self::assertNull($listed['deposits'][1]['account']);
        self::assertSame([true, true, false], array_column($listed['deposits'], 'covers'), 'what pays its order\'s own price, and what is short of it');

        $this->rikhtam->down();
        self::assertSame([503, PaymentRikhtamController::DOWN], [$this->get($path)->getStatusCode(), $this->decode($this->get($path))['message']]);
    }

    public function testAPaymentNoDepositMayPayIsRefusedAsAnApprovalIs(): void
    {
        $this->connect();
        $paid = $this->openCardPayment();
        $this->service(PaymentService::class)->approve($paid, 'admin');

        $this->assertRefusedUnder('status', 'این پرداخت تایید شده است.', $this->get("/api/admin/payments/{$paid->id}/rikhtam"));
        $this->assertRefusedUnder('status', 'این پرداخت تایید شده است.', $this->postJson("/api/admin/payments/{$paid->id}/rikhtam", ['uid' => 'dep-1']));
        self::assertFalse($this->decode($this->get("/api/admin/payments/{$paid->id}"))['payment']['actions']['rikhtam']);
        self::assertSame(['hook'], array_column($this->rikhtam->calls, 'a'), 'Rikhtam is not asked');
    }

    public function testAnAttachedDepositApprovesThePaymentWhileTheRequestHoldsNothing(): void
    {
        $this->connect();
        $payment = $this->openCardPayment();
        $this->rikhtam->pending = [['uid' => 'dep-20', 'amount' => (int) $payment->amount, 'time' => time(), 'account' => '1119']];
        $level = $this->db()->transactionLevel();
        $held = null;
        $this->rikhtam->onAttach = function (string $uid, string $invoice) use ($payment, $level, &$held): int {
            // What Rikhtam does in the middle of bg_attach: the same deposit, named for the invoice, to the shop's address.
            try {
                $this->service(Session::class)->set('probe', true);
                $held = 'the session';
            } catch (\LogicException) {
                $held = $this->db()->transactionLevel() === $level ? 'nothing' : 'a transaction';
            }

            return $this->rikhtamSends($this->rikhtam->deposit((int) round((float) $payment->amount * 10), $uid, ['invoice' => $invoice, 'manual' => '1']))->getStatusCode();
        };

        $answer = $this->decode($this->postJson("/api/admin/payments/{$payment->id}/rikhtam", ['uid' => 'dep-20']));

        self::assertSame('nothing', $held, 'no session nor transaction held while Rikhtam calls back');
        self::assertSame(['paid', 'واریز به صورتحساب پیوست شد.', number_format(intdiv((int) $payment->amount, 100), 2, '.', '')], [$answer['payment']['status'], $answer['message'], $answer['fee']]);
        self::assertSame([PaymentStatus::Paid, Deposits::REVIEWER], [$payment->refresh()->status, $payment->reviewer]);
        $deposit = Deposit::query()->sole();
        self::assertSame(['rikhtam', 'dep-20', DepositStatus::Matched, $payment->id], [$deposit->source, $deposit->external_id, $deposit->status, $deposit->payment_id]);
        $attach = $this->rikhtam->calls[array_key_last($this->rikhtam->calls)];
        self::assertSame(['bg_attach', 'dep-20', (string) $payment->id, 45], [$attach['a'], $attach['body']['uid'], $attach['body']['invoice'], $attach['timeout']]);
    }

    public function testAnAttachTheShopsAddressRefusedIsRikhtamsOwnWords(): void
    {
        $this->connect();
        $payment = $this->openCardPayment();
        $this->rikhtam->pending = [['uid' => 'dep-21', 'amount' => 140000, 'time' => time(), 'account' => '1119']];
        $this->rikhtam->onAttach = fn(string $uid, string $invoice): int => $this->rikhtamSends($this->rikhtam->deposit(1_400_000, $uid, ['invoice' => $invoice, 'manual' => '1']))->getStatusCode();

        $refused = $this->postJson("/api/admin/payments/{$payment->id}/rikhtam", ['uid' => 'dep-21']);

        $this->assertRefusedUnder('uid', 'واریز پیدا نشد، قبلا تایید شده یا درگاه شما صورتحساب را نپذیرفت.', $refused);
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertSame(DepositStatus::Unmatched, Deposit::query()->sole()->status, 'kept, short of the order\'s price');
        $this->assertRefusedUnder('uid', RikhtamDeposits::NO_UID, $this->postJson("/api/admin/payments/{$payment->id}/rikhtam", ['uid' => '']));

        $this->rikhtam->down();
        self::assertSame(503, $this->postJson("/api/admin/payments/{$payment->id}/rikhtam", ['uid' => 'dep-21'])->getStatusCode());
    }

    public function testAnAttachWhoseAnswerIsLostIsAnsweredByWhatTheShopMadeOfIt(): void
    {
        $this->connect();
        $payment = $this->openCardPayment();
        $rial = (int) round((float) $payment->amount * 10);
        $this->rikhtam->pending = [['uid' => 'dep-30', 'amount' => (int) $payment->amount, 'time' => time(), 'account' => '1119']];
        $this->rikhtam->onAttach = function (string $uid, string $invoice) use ($rial): int {
            $this->rikhtamSends($this->rikhtam->deposit($rial, $uid, ['invoice' => $invoice, 'manual' => '1']));

            // The shop took it; Rikhtam's own answer never comes back.
            throw new ConnectException('Operation timed out after 45000 milliseconds', new Request('POST', RikhtamApi::URL));
        };

        $answer = $this->postJson("/api/admin/payments/{$payment->id}/rikhtam", ['uid' => 'dep-30']);

        self::assertSame(200, $answer->getStatusCode(), (string) $answer->getBody());
        self::assertSame(['paid', RikhtamDeposits::TAKEN_UNHEARD, null], [$this->decode($answer)['payment']['status'], $this->decode($answer)['message'], $this->decode($answer)['fee']], 'the payment as it is: approved');
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function testAnAttachRikhtamRefusedAfterTheShopTookItIsSaidAsTheShopsRecordHasIt(): void
    {
        $this->connect();
        $payment = $this->openCardPayment();
        $rial = (int) round((float) $payment->amount * 10);
        $this->rikhtam->pending = [
            ['uid' => 'dep-31', 'amount' => (int) $payment->amount, 'time' => time(), 'account' => '1119'],
            ['uid' => 'dep-32', 'amount' => (int) $payment->amount, 'time' => time(), 'account' => '1119'],
        ];
        // Rikhtam stopped waiting while the shop was still deciding the deposit: nothing is known yet.
        $this->rikhtam->onAttach = static function (string $uid) use ($rial): int {
            Deposit::query()->create(['source' => 'rikhtam', 'external_id' => $uid, 'status' => DepositStatus::Received, 'amount_rial' => $rial, 'received_at' => now()]);

            return 504;
        };
        $deciding = $this->postJson("/api/admin/payments/{$payment->id}/rikhtam", ['uid' => 'dep-31']);
        self::assertSame([503, PaymentRikhtamController::ATTACH_DOWN], [$deciding->getStatusCode(), $this->decode($deciding)['message']], 'not Rikhtam\'s refusal: the shop may yet take it');

        // The shop's answer reached Rikhtam too late: it refused, but the deposit approved the payment.
        $this->rikhtam->onAttach = function (string $uid, string $invoice) use ($rial): int {
            $this->rikhtamSends($this->rikhtam->deposit($rial, $uid, ['invoice' => $invoice, 'manual' => '1']));

            return 504;
        };
        $late = $this->postJson("/api/admin/payments/{$payment->id}/rikhtam", ['uid' => 'dep-32']);

        self::assertSame([200, 'paid', RikhtamDeposits::TAKEN_UNHEARD], [$late->getStatusCode(), $this->decode($late)['payment']['status'] ?? null, $this->decode($late)['message'] ?? null], (string) $late->getBody());
        self::assertSame([PaymentStatus::Paid, $payment->id], [$payment->refresh()->status, Deposit::query()->where('external_id', 'dep-32')->value('payment_id')]);
    }

    public function testAKeyAnotherShopOfTheSiteHoldsIsRefusedBeforeRikhtamIsAsked(): void
    {
        $agentShop = $this->agentBot();
        CurrentBot::run($agentShop, fn() => $this->service(DepositSettings::class)->connectRikhtam(FakeRikhtam::KEY, "https://shop.example/webhooks/rikhtam/{$agentShop->id}", ['ok' => true]));

        $this->assertRefusedUnder('secret', RikhtamDeposits::KEY_ELSEWHERE, $this->putJson('/api/admin/deposits/settings/rikhtam', ['secret' => FakeRikhtam::KEY]));

        self::assertSame([], $this->rikhtam->calls, 'its address stays where the other shop registered it');
        self::assertFalse($this->decode($this->get('/api/admin/deposits/settings'))['rikhtam']['connected']);
        self::assertTrue(CurrentBot::run($agentShop, fn(): bool => $this->service(DepositSettings::class)->acceptsRikhtam(FakeRikhtam::KEY)), 'the other shop keeps it');
    }

    public function testRikhtamsDepositsSayNothingOfWhetherThePhoneAppsMessagesCome(): void
    {
        $this->connect();
        $this->openCardPayment();

        self::assertSame(404, $this->rikhtamSends($this->rikhtam->deposit(1_234_560, 'dep-40'))->getStatusCode());

        $settings = $this->decode($this->get('/api/admin/deposits/settings'));
        self::assertSame([null, null, 1], [$settings['last_received_at'], $settings['last_test_at'], $settings['waiting']], 'ForApp has sent nothing; the deposit that approved nothing is support\'s all the same');
    }

    /** Rikhtam connected through the panel, by its key. */
    private function connect(): void
    {
        self::assertSame(200, $this->putJson('/api/admin/deposits/settings/rikhtam', ['secret' => FakeRikhtam::KEY])->getStatusCode());
    }

    /** A card payment opened on a purchase of the plan, as the checkout opens one. */
    private function openCardPayment(): Payment
    {
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['amount' => '150000.00']);

        return $this->service(PaymentService::class)->createForOrder($order, $this->card)['payment'];
    }

    /**
     * Rikhtam's request to the shop's address: a form.
     *
     * @param array<string, string> $form
     */
    private function rikhtamSends(array $form): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', self::CALLBACK)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody((new StreamFactory())->createStream(http_build_query($form)));

        return $this->app()->http()->handle($request);
    }
}
