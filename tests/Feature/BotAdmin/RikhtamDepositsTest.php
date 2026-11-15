<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Http\Urls;
use App\Modules\Admin\Api\PaymentRikhtamController;
use App\Modules\Bots\Models\Bot;
use App\Modules\Payments\Deposits\Services\DepositSettings;
use App\Modules\Payments\Deposits\Services\RikhtamDeposits;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\BotTestCase;
use Tests\Support\FakeRikhtam;

/**
 * Rikhtam (rikhtam.com) in the bot's management: «⚡️ تایید خودکار واریز» connects it by its gateway's key — typed at a
 * question, taken away once read, refused over the question in Rikhtam's words, the approval switched on —, registers
 * the shop's address again and disconnects once asked; and a card payment takes «📎 پیوست واریز ریختم»: Rikhtam's
 * deposits as buttons, the exact sum marked, one asked first and attached — Rikhtam sending it to the shop's address in
 * the middle of the call, which approves the payment —, Rikhtam's refusal said and the deposits asked again; a stale or
 * second tap attaches nothing it was not asked, and what else comes of it (Rikhtam out of reach, the payment closed
 * meanwhile, an attach that paid nothing) is said over the payment as it stands; a shop's admin offered it only where
 * they may approve. Every write through the panel's own routes.
 */
final class RikhtamDepositsTest extends BotTestCase
{
    private const OWNER = 7001;

    private const STAFF = 7100;

    private FakeRikhtam $rikhtam;

    private User $ali;

    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example', 'telegram.username' => 'amo_shop_bot']);
        $this->rikhtam = $this->rikhtam();
        $this->ali = $this->customer(['first_name' => 'Ali']);
        $this->card = $this->cardMethod();
    }

    public function testRikhtamIsConnectedByItsKeyWhileTheApprovalIsOffRegisteredAgainAndDisconnectedOnceAsked(): void
    {
        $this->owner();

        $this->send($this->tap(Nav::to('pmd'), chat: self::OWNER));
        $screen = $this->said()[0];
        self::assertStringContainsString('🔗 اتصال ریختم', $screen);
        self::assertStringContainsString('وصل نیست', $screen);
        self::assertStringContainsString("\u{2068}shop.example\u{2069}", $screen, 'the gateway is made with the shop\'s domain');
        self::assertStringContainsString('با اتصال ریختم روشن می‌شود', $screen, 'offered while the approval is off');
        self::assertContains(Nav::to('pmd', 'rk'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('pmd', 'rk'), chat: self::OWNER));
        self::assertStringContainsString('کلید امنیتی درگاه ریختم', $this->said()[0]);
        self::assertStringContainsString('پیامتان بعد از خواندن پاک می‌شود', $this->said()[0]);

        $this->send($this->message('rk_wrong', chat: self::OWNER));
        self::assertStringContainsString(RikhtamDeposits::BAD_KEY, implode("\n", $this->said()), 'Rikhtam\'s refusal over the question, asked again');
        self::assertStringContainsString('دوباره بفرستید', implode("\n", $this->said()));
        self::assertFalse($this->service(DepositSettings::class)->rikhtamConnected());
        self::assertContains('deleteMessage', $this->calls(), 'the typed key taken away');

        $this->send($this->message(FakeRikhtam::KEY, chat: self::OWNER));
        self::assertTrue($this->service(DepositSettings::class)->rikhtamConnected(), 'connected through the panel\'s route');
        self::assertTrue($this->service(DepositSettings::class)->enabled(), 'the approval switched on with it');
        self::assertSame('https://shop.example' . str_replace('{bot:[0-9]+}', (string) Bot::MAIN, Urls::RIKHTAM_WEBHOOK), $this->rikhtam->hooked);
        self::assertContains('deleteMessage', $this->calls(), 'the typed key taken away');
        $screen = implode("\n", $this->said());
        self::assertStringContainsString('ریختم وصل شد و تایید خودکار واریز روشن شد', $screen);
        self::assertStringContainsString('🟢 وصل است', $screen);
        self::assertStringContainsString('(شماره ' . Fmt::digits(FakeRikhtam::PROJECT) . ' در ریختم)', $screen);
        self::assertStringContainsString('<code>' . $this->rikhtam->hooked . '</code>', $screen, 'the shop\'s address there, copied at a tap');
        self::assertStringNotContainsString(FakeRikhtam::KEY, $screen, 'the key is never shown');
        $labels = $this->buttonLabels($this->windowCall());
        self::assertContains('🔁 ثبت دوباره آدرس در ریختم', $labels, 'named for Rikhtam: ForApp\'s address is on the same screen');
        self::assertContains('✂️ قطع اتصال ریختم', $labels);

        $this->rikhtam->hooked = null;
        $this->send($this->tap(Nav::to('pmd', 'rh'), chat: self::OWNER));
        self::assertNotNull($this->rikhtam->hooked, 'registered again');
        self::assertSame('🔁 آدرس فروشگاه دوباره در ریختم ثبت شد', $this->popup());

        $this->send($this->tap(Nav::to('pmd', 'rx'), chat: self::OWNER));
        self::assertStringContainsString('اتصال ریختم قطع شود؟', $this->said()[0], 'asked first');
        self::assertTrue($this->service(DepositSettings::class)->rikhtamConnected());
        self::assertContains(Nav::to('pmd', 'rxy'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('pmd', 'rxy'), chat: self::OWNER));
        self::assertFalse($this->service(DepositSettings::class)->rikhtamConnected());
        self::assertSame('✂️ اتصال ریختم قطع شد', $this->popup());
        self::assertContains(Nav::to('pmd', 'rk'), $this->callbacks($this->windowCall()), 'its steps again');
    }

    public function testAShopNotOnHttpsIsToldRikhtamCannotBeConnectedNotAskedAKey(): void
    {
        $this->config(['app.url' => 'http://shop.example']);
        $this->owner();

        $this->send($this->tap(Nav::to('pmd'), chat: self::OWNER));

        self::assertStringContainsString('ریختم فقط به آدرس', $this->said()[0]);
        self::assertNotContains(Nav::to('pmd', 'rk'), $this->callbacks(0), 'no key asked that Rikhtam would refuse');
    }

    public function testAConnectedRikhtamTakesANewKeyAndARefusedRegistrationPointsToIt(): void
    {
        $this->owner();
        $this->connect();

        // Registering the address again refused (here the gateway was made for another domain): said, with the way on.
        $this->rikhtam->domain = 'other.example';
        $this->send($this->tap(Nav::to('pmd', 'rh'), chat: self::OWNER));
        $screen = implode("\n", $this->said());
        self::assertStringContainsString('⚠️', $screen);
        self::assertStringContainsString('کلید تازه را با «🔑 تعویض کلید ریختم» بفرستید', $screen);
        self::assertContains('🔑 تعویض کلید ریختم', $this->buttonLabels($this->windowCall()));

        $this->rikhtam->domain = null;
        $this->rikhtam->hooked = null;
        $this->send($this->tap(Nav::to('pmd', 'rk'), chat: self::OWNER));
        self::assertStringContainsString('کلید امنیتی تازه درگاه ریختم', $this->said()[0], 'asked as a replacement while connected');
        self::assertStringContainsString('جای کلید فعلی را می‌گیرد', $this->said()[0]);

        $this->send($this->message(FakeRikhtam::KEY, chat: self::OWNER));
        self::assertStringContainsString('🔑 کلید ریختم عوض شد و آدرس فروشگاه دوباره در ریختم ثبت شد', implode("\n", $this->said()));
        self::assertNotNull($this->rikhtam->hooked, 'the address registered with the new key');
        self::assertTrue($this->service(DepositSettings::class)->rikhtamConnected());
        self::assertContains('deleteMessage', $this->calls(), 'the typed key taken away');
    }

    public function testADepositShortOfTheOrdersOwnPriceIsMarkedAndNeverOffered(): void
    {
        $this->owner();
        $this->connect();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card, ['amount' => '50120.00']);
        $this->rikhtam->pending = [['uid' => 'dep_short_77aa01', 'amount' => 49990, 'time' => time() - 120, 'account' => '1234']];

        $this->send($this->tap(Nav::to('pyv', 'bg', $payment->id), chat: self::OWNER));
        self::assertStringStartsWith('⚠️ ' . Fmt::money(49990), $this->buttonLabels(0)[0], 'short of the order\'s own 50,000');
        self::assertStringContainsString('⚠️ یعنی کمتر از مبلغ خود سفارش', $this->said()[0]);

        $this->send($this->tap($this->button(0, 'bgp', $payment->id, 0), chat: self::OWNER));
        self::assertStringContainsString('پرداخت را تایید نمی‌کند', (string) $this->popup());
        self::assertStringNotContainsString('پیوست شود؟', implode("\n", $this->said()), 'nothing asked: it would pay nothing');
        self::assertSame([], $this->attached());
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testADepositOfRikhtamsIsPickedAskedFirstAndAttachedWhichApprovesThePayment(): void
    {
        $this->owner();
        $this->connect();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card, ['amount' => '50120.00']);
        $this->rikhtam->pending = [
            ['uid' => 'dep_round_5f1c9a', 'amount' => 50000, 'time' => time() - 600, 'account' => '1234'],
            ['uid' => 'dep_exact_0b7e2d', 'amount' => 50120, 'time' => time() - 300, 'account' => '5678'],
        ];
        $this->rikhtam->onAttach = fn(string $uid, string $invoice): int => $this->rikhtamSends($this->rikhtam->deposit(500000, $uid, ['invoice' => $invoice, 'manual' => '1']));

        $this->send($this->tap(Nav::to('pyv', 'show', $payment->id), chat: self::OWNER));
        self::assertContains(Nav::to('pyv', 'bg', $payment->id), $this->callbacks(0), 'an open card payment while Rikhtam is connected');

        $this->send($this->tap(Nav::to('pyv', 'bg', $payment->id), chat: self::OWNER));
        self::assertStringContainsString('📎 پیوست واریز ریختم', $this->said()[0]);
        self::assertStringContainsString(Fmt::money('50120.00'), $this->said()[0], 'the payment\'s sum beside them');
        $labels = $this->buttonLabels(0);
        self::assertStringStartsWith('🎯 ' . Fmt::money(50120), $labels[0], 'the closest first, the exact sum marked');
        self::assertStringContainsString("\u{2068}····5678\u{2069}", $labels[0], 'the account left to right');
        self::assertStringContainsString('۵ دقیقه قبل', $labels[0], 'how long ago, short enough for the button');
        self::assertStringStartsWith(Fmt::money(50000), $labels[1]);
        $asked = array_column($this->rikhtam->calls, 'a');
        self::assertSame('bg_pending', end($asked), 'asked of Rikhtam as the screen opened');
        foreach ($this->callbacks(0) as $data) {
            self::assertLessThanOrEqual(64, strlen($data));
        }

        $this->send($this->tap($this->button(0, 'bgp', $payment->id, 1), chat: self::OWNER));
        $asking = $this->said()[0];
        self::assertStringContainsString('پیوست شود؟', $asking, 'asked first');
        self::assertStringContainsString(Fmt::money(120) . ' کمتر از مبلغ پرداخت', $asking, 'how far from the payment\'s sum');
        self::assertStringContainsString('ریختم کارمزد معمولش را', $asking);
        self::assertStringContainsString('شارژ کیف پول انجام می‌شود', $asking);
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $confirm = $this->button(0, 'bgy', $payment->id, 1);
        $this->send($this->tap($confirm, chat: self::OWNER));
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'approved by the deposit Rikhtam sent back');
        self::assertSame('dep_round_5f1c9a', $this->lastAttached());
        self::assertSame('⏳ واریز در ریختم پیوست می‌شود…', $this->popup(), 'the tap answered before Rikhtam calls back');
        $screen = implode("\n", $this->said());
        self::assertStringContainsString('با واریز ریختم تایید شد و کیف پول شارژ شد. کارمزد ریختم: ' . Fmt::money(500) . '. واریز به صورتحساب پیوست شد', $screen, 'what came of it over the screen: the fee, Rikhtam\'s words');
        self::assertStringContainsString('پرداخت‌شده', $screen, 'the payment as it is now');
        self::assertNotContains(Nav::to('pyv', 'bg', $payment->id), $this->callbacks($this->windowCall()), 'nothing left to attach');

        // The same «✔️» tapped again (a second tap, waiting its turn): nothing attached twice, the payment as it stands.
        $this->send($this->tap($confirm, chat: self::OWNER));
        self::assertSame(['dep_round_5f1c9a'], $this->attached());
        self::assertSame('این پرداخت تایید شده است.', $this->popup());
        self::assertContains(Nav::to('pyv', 'show', $payment->id), $this->callbacks($this->windowCall()));
        self::assertStringContainsString('پرداخت‌شده', implode("\n", $this->said()));
    }

    public function testAStaleConfirmationAttachesNoOtherDepositThanTheOneAsked(): void
    {
        $this->owner();
        $this->connect();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card, ['amount' => '50120.00']);
        $this->rikhtam->pending = [
            ['uid' => 'dep_exact_0b7e2d', 'amount' => 50120, 'time' => time() - 300, 'account' => '5678'],
            ['uid' => 'dep_round_5f1c9a', 'amount' => 50000, 'time' => time() - 600, 'account' => '1234'],
        ];
        $this->send($this->tap(Nav::to('pyv', 'bg', $payment->id), chat: self::OWNER));
        $this->send($this->tap($this->button(0, 'bgp', $payment->id, 0), chat: self::OWNER));
        $confirm = $this->button(0, 'bgy', $payment->id, 0);

        // Rikhtam's list moved on (the exact one taken elsewhere), read again: another deposit is first now.
        array_shift($this->rikhtam->pending);
        $this->send($this->tap(Nav::to('pyv', 'bg', $payment->id), chat: self::OWNER));
        $this->send($this->tap($confirm, chat: self::OWNER));

        self::assertSame([], $this->attached(), 'the deposit in its place now was never asked');
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertStringContainsString('فهرست واریزهای ریختم عوض شده بود', (string) $this->popup());
        self::assertStringStartsWith(Fmt::money(50000), $this->buttonLabels($this->windowCall())[0], 'the deposits as they are now');
    }

    public function testAnAttachWhoseOutcomeIsNotKnownShowsThePaymentAsItStands(): void
    {
        $this->owner();
        $this->connect();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);
        $this->rikhtam->pending = [['uid' => 'dep_late_3c9e01', 'amount' => 50000, 'time' => time() - 60, 'account' => '1234']];
        $this->send($this->tap(Nav::to('pyv', 'bg', $payment->id), chat: self::OWNER));
        $confirm = $this->button(0, 'bgp', $payment->id, 0);
        $this->send($this->tap($confirm, chat: self::OWNER));
        $confirm = $this->button(0, 'bgy', $payment->id, 0);

        $this->rikhtam->down();
        $this->send($this->tap($confirm, chat: self::OWNER));

        $screen = implode("\n", $this->said());
        self::assertStringContainsString(PaymentRikhtamController::ATTACH_DOWN, $screen, 'said over the screen');
        self::assertStringContainsString('💳 پرداخت ' . Fmt::id($payment->id), $screen, 'the payment as it stands, not the question');
        self::assertContains(Nav::to('pyv', 'bg', $payment->id), $this->callbacks($this->windowCall()), 'to try again');
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testRikhtamTakingTheAttachWithoutPayingThePaymentIsNeverSaidDone(): void
    {
        $this->owner();
        $this->connect();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);
        $this->rikhtam->pending = [['uid' => 'dep_lost_8d2a77', 'amount' => 50000, 'time' => time() - 60, 'account' => '1234']];
        // Rikhtam says attached, though what it sent never reached the shop.
        $this->rikhtam->onAttach = static fn(): int => 200;

        $this->send($this->tap(Nav::to('pyv', 'bg', $payment->id), chat: self::OWNER));
        $this->send($this->tap($this->button(0, 'bgp', $payment->id, 0), chat: self::OWNER));
        $this->send($this->tap($this->button(0, 'bgy', $payment->id, 0), chat: self::OWNER));

        $screen = implode("\n", $this->said());
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertStringContainsString('هنوز تایید نشده است', $screen);
        self::assertStringNotContainsString('با واریز ریختم تایید شد', $screen);
        self::assertStringContainsString('در انتظار پرداخت', $screen, 'the payment as it stands');
    }

    public function testAPaymentClosedMeanwhileIsShownAsItStands(): void
    {
        $this->owner();
        $this->connect();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);
        $this->rikhtam->pending = [['uid' => 'dep_open_51be04', 'amount' => 50000, 'time' => time() - 60, 'account' => '1234']];
        $this->send($this->tap(Nav::to('pyv', 'bg', $payment->id), chat: self::OWNER));
        $this->send($this->tap($this->button(0, 'bgp', $payment->id, 0), chat: self::OWNER));
        $confirm = $this->button(0, 'bgy', $payment->id, 0);

        $payment->forceFill(['status' => PaymentStatus::Cancelled])->save();
        $this->send($this->tap($confirm, chat: self::OWNER));
        self::assertSame([], $this->attached(), 'never asked of Rikhtam');
        self::assertContains(Nav::to('pyv', 'show', $payment->id), $this->callbacks($this->windowCall()), 'the payment, not the question');
        $screen = implode("\n", $this->said());
        $why = $this->refusal($payment);
        self::assertStringContainsString('⚠️ ' . $why, $screen, 'why, over the screen');
        self::assertStringContainsString('لغوشده', $screen);

        // Its button on a screen drawn before: said in a popup, the payment shown as it is.
        $this->send($this->tap(Nav::to('pyv', 'bg', $payment->id), chat: self::OWNER));
        self::assertSame($why, $this->popup());
        self::assertStringContainsString('لغوشده', implode("\n", $this->said()));
        self::assertNotContains(Nav::to('pyv', 'bg', $payment->id), $this->callbacks($this->windowCall()));
    }

    public function testRikhtamsRefusalIsSaidAndItsDepositsAskedAgain(): void
    {
        $this->owner();
        $this->connect();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);
        $this->rikhtam->pending = [['uid' => 'dep_taken_77aa10', 'amount' => 50000, 'time' => time() - 60, 'account' => '1234']];
        $this->rikhtam->onAttach = static fn(): int => 409;

        $this->send($this->tap(Nav::to('pyv', 'bg', $payment->id), chat: self::OWNER));
        $this->send($this->tap($this->button(0, 'bgp', $payment->id, 0), chat: self::OWNER));
        $this->send($this->tap($this->button(0, 'bgy', $payment->id, 0), chat: self::OWNER));

        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $screen = implode("\n", $this->said());
        self::assertStringContainsString('⚠️ واریز پیدا نشد', $screen, 'Rikhtam\'s words, over the screen');
        self::assertStringContainsString('📎 پیوست واریز ریختم', $screen, 'its deposits again');
        self::assertStringStartsWith(Nav::to('pyv', 'bgp', $payment->id, 0) . ':', $this->button($this->windowCall(), 'bgp', $payment->id, 0));
    }

    public function testNothingToAttachIsSaidAndADisconnectedRikhtamOffersNone(): void
    {
        $this->owner();
        $this->connect();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);

        $this->send($this->tap(Nav::to('pyv', 'bg', $payment->id), chat: self::OWNER));
        self::assertStringContainsString('ریختم واریزی ندارد که به پرداختی وصل نشده باشد', $this->said()[0]);
        self::assertContains(Nav::to('pyv', 'bg', $payment->id), $this->callbacks(0), 'asked again');

        $this->service(RikhtamDeposits::class)->disconnect();
        $this->send($this->tap(Nav::to('pyv', 'show', $payment->id), chat: self::OWNER));
        self::assertNotContains(Nav::to('pyv', 'bg', $payment->id), $this->callbacks(0));
    }

    public function testAShopsAdminIsOfferedItOnlyWhereTheyMayApprove(): void
    {
        $this->connect();
        $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Boss', 'role' => UserRole::Admin]);
        $pending = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);
        $inReview = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '60000.00'), $this->card));

        $this->send($this->tap(Nav::to('pyv', 'show', $pending->id), chat: self::STAFF));
        self::assertContains(Nav::to('pyv', 'show', $pending->id), $this->callbacks($this->windowCall()), 'its screen');
        self::assertNotContains(Nav::to('pyv', 'bg', $pending->id), $this->callbacks($this->windowCall()), 'no receipt to review: not theirs to approve');

        $this->send($this->tap(Nav::to('pyv', 'show', $inReview->id), chat: self::STAFF));
        self::assertContains(Nav::to('pyv', 'bg', $inReview->id), $this->callbacks($this->windowCall()), 'a receipt in review is');

        $this->send($this->tap(Nav::to('pmd'), chat: self::STAFF));
        self::assertStringNotContainsString('اتصال ریختم', implode("\n", $this->said()), 'the settings are the owner\'s and an agent\'s');
    }

    /** The shop's Rikhtam connected, as the owner's key in the panel connects it. */
    private function connect(): void
    {
        $this->service(RikhtamDeposits::class)->connect(FakeRikhtam::KEY);
    }

    /**
     * Rikhtam sends the shop's address a deposit (a form, as its app does): the HTTP status the shop answered.
     *
     * @param array<string, string> $form
     */
    private function rikhtamSends(array $form): int
    {
        $path = str_replace('{bot:[0-9]+}', (string) Bot::MAIN, Urls::RIKHTAM_WEBHOOK);
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://shop.example' . $path)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody((new StreamFactory())->createStream(http_build_query($form)));

        // An installed shop's address, as HttpTestCase plays one: the bot's own requests never ask.
        touch((string) $this->app()->container()->get('installation.lock'));

        return $this->app()->http()->handle($request)->getStatusCode();
    }

    /** The deposit the shop last asked Rikhtam to attach. */
    private function lastAttached(): ?string
    {
        $attached = $this->attached();

        return $attached === [] ? null : $attached[count($attached) - 1];
    }

    /** Why the panel would not approve `$payment` as it stands. */
    private function refusal(Payment $payment): string
    {
        return $this->service(PaymentActions::class)->refusal($payment->refresh(), 'approve');
    }

    /**
     * Every deposit the shop asked Rikhtam to attach, in order.
     *
     * @return list<string>
     */
    private function attached(): array
    {
        $calls = array_filter($this->rikhtam->calls, static fn(array $call): bool => $call['a'] === 'bg_attach');

        return array_values(array_map(static fn(array $call): string => is_string($call['body']['uid'] ?? null) ? $call['body']['uid'] : '', $calls));
    }

    /**
     * The n-th call's button to the payment screen's `$action` for deposit `$n` of payment `$payment` — its data carrying
     * the deposit's mark after them.
     */
    private function button(int $call, string $action, int $payment, int $n): string
    {
        $prefix = Nav::to('pyv', $action, $payment, $n) . ':';
        foreach ($this->callbacks($call) as $data) {
            if (str_starts_with($data, $prefix)) {
                return $data;
            }
        }
        self::fail("No «{$action}» button for deposit {$n} of payment {$payment}.");
    }

    /** The account 7001 linked as the owner. */
    private function owner(): void
    {
        $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir']);
        $this->linkOwner(self::OWNER);
    }
}
