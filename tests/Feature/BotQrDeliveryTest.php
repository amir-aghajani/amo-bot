<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Qr\QrBackground;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Users\Models\User;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * A delivered service comes as a QR card of its link with the service text as the caption — after a
 * wallet payment in the bot and after an admin's approval alike, and the link a customer asks for from
 * the service screen takes the screen's place as one — unless the bot is set not to, the card cannot be
 * drawn, or the words outgrow what Telegram takes under a picture: then the words alone, the link always.
 */
#[RequiresPhpExtension('gd')]
final class BotQrDeliveryTest extends BotTestCase
{
    /** The link the fake panel hands out for the first service it makes. */
    private const FIRST_LINK = 'https://fake.test/sub/sub-1';

    private User $user;
    private Plan $plan;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->sellingServer();
        $this->plan = $this->plan(on: $this->server);
        $this->user = $this->wallet($this->customer(), '200000.00');
    }

    public function testAWalletPurchaseArrivesAsACardWithTheServiceTextAsCaption(): void
    {
        $this->buyInTheBot();
        $order = Order::query()->sole();

        self::assertSame(['deleteMessage', 'sendPhoto', 'answerCallbackQuery'], $this->calls(), 'the checkout goes, the card comes');
        $params = $this->params(1);
        self::assertSame('service.jpg', $params['photo']);
        self::assertSame('HTML', $params['parse_mode']);
        self::assertSame($this->delivered('USER_1', self::FIRST_LINK), $params['caption'], 'the service text is the caption — named as Telegram shows the account: the test update carries no username');
        self::assertArrayNotHasKey('reply_markup', $params);
        self::assertArrayNotHasKey('text', $params);

        $photo = $this->telegram()->files(1)['photo'];
        $size = getimagesizefromstring($photo);
        self::assertNotFalse($size);
        self::assertSame(['image/jpeg', 1024, 1024], [$size['mime'], $size[0], $size[1]], 'the link drawn on the shipped background');
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
    }

    public function testAnApprovedReceiptDeliversTheCardAsAReplyToTheReceipt(): void
    {
        $order = $this->purchaseOrder($this->user, $this->plan, $this->server);
        $payment = $this->receipt($this->cardPayment($order, $this->cardMethod()));
        $payments = $this->service(PaymentService::class);

        $this->telegram()->reset();
        $payments->approve($payment, 'root');
        $this->service(CustomerNotifier::class)->paymentSettled($payment->refresh());

        self::assertSame(['sendPhoto'], $this->calls());
        self::assertSame([$this->delivered('USER_1', self::FIRST_LINK)], $this->said());
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0), 'still a reply to the receipt');
        self::assertArrayNotHasKey('reply_markup', $this->params(0));
    }

    public function testTheLinkButtonTurnsTheScreenIntoTheCard(): void
    {
        $subscription = $this->subscription($this->user, $this->plan, $this->server, 'ali_1');

        $this->send($this->tap(SubscriptionHandler::serviceCallback($subscription->id, 'link')));

        self::assertSame(['editMessageMedia', 'answerCallbackQuery'], $this->calls(), 'the screen message takes the card — one message');
        $params = $this->params(0);
        self::assertSame('77', $params['message_id']);
        $media = json_decode($params['media'], true);
        self::assertSame(['photo', 'attach://photo', 'HTML'], [$media['type'], $media['media'], $media['parse_mode']]);
        self::assertSame([$this->linkShown('ali_1')], $this->said());
        self::assertSame([[['text' => self::text(BotText::Back), 'callback_data' => SubscriptionHandler::serviceCallback($subscription->id)]]], $this->inlineKeyboard(0), 'back to managing the service');

        $size = getimagesizefromstring($this->telegram()->files(0)['photo']);
        self::assertNotFalse($size);
        self::assertSame(['image/jpeg', 1024, 1024], [$size['mime'], $size[0], $size[1]], 'the kept link drawn on the background');
    }

    public function testWhereTelegramWillNotPutTheCardOnTheScreenTheCardReplacesIt(): void
    {
        $subscription = $this->subscription($this->user, $this->plan, $this->server, 'ali_1');
        // An older Bot API server refuses media on a text message.
        $this->telegram()->fail(400, 'Bad Request: there is no media in the message to edit');

        $this->send($this->tap(SubscriptionHandler::serviceCallback($subscription->id, 'link')));

        self::assertSame(['editMessageMedia', 'deleteMessage', 'sendPhoto', 'answerCallbackQuery'], $this->calls(), 'the screen goes, the card comes — still one message');
        self::assertSame($this->linkShown('ali_1'), $this->params(2)['caption']);
        self::assertSame([SubscriptionHandler::serviceCallback($subscription->id)], $this->callbacks(2));
    }

    public function testWithTheQrSwitchOffTheServiceIsTheTextAlone(): void
    {
        $this->botSettings('qr', ['qr_enabled' => false]);

        $this->buyInTheBot();

        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls());
        self::assertSame([$this->delivered('USER_1', self::FIRST_LINK)], $this->said());
    }

    public function testABackgroundGdCannotReadCostsTheCardButNeverTheLink(): void
    {
        $dir = (string) $this->app()->container()->get('qr.backgrounds');
        mkdir($dir);
        file_put_contents("{$dir}/background.png", 'not a picture');
        $this->service(Settings::class)->set(QrBackground::KEY, 'background.png');

        $this->buyInTheBot();

        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'no picture: the text alone');
        self::assertSame([$this->delivered('USER_1', self::FIRST_LINK)], $this->said());
    }

    public function testWordsLongerThanTelegramTakesUnderAPictureGoAsAMessageWithTheLink(): void
    {
        // The admin's long wording (within what a caption may be worded), and a panel whose links are long.
        $this->service(BotTexts::class)->save(BotText::PaySuccess, str_repeat('توضیح ', 130) . "\n<code>%subscription%</code>");
        FakeProvider::$subscriptionBase = 'https://fake.test/' . str_repeat('s', 300) . '/';
        $link = FakeProvider::$subscriptionBase . 'sub-1';

        $this->buyInTheBot();

        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'the words alone, never cut short');
        $text = $this->params(1)['text'];
        self::assertGreaterThan(Limits::CAPTION, TelegramHtml::visibleLength($text));
        self::assertStringEndsWith("<code>{$link}</code>", $text, 'the link and all');
    }

    /** The plan on the server, paid from the wallet at its checkout: the calls are the payment's. */
    private function buyInTheBot(): void
    {
        $this->send($this->tap(PurchaseHandler::serverCallback($this->plan->id, $this->server->id)));
        $pay = array_column(array_merge(...$this->inlineKeyboard(0)), 'callback_data', 'text')[$this->walletMethod()->label] ?? self::fail('The checkout offers no wallet.');

        $this->send($this->tap($pay));
    }

    /** What the customer reads when the plan is delivered: the service named `$client` on the panel, its link. */
    private function delivered(string $client, string $link): string
    {
        return self::text(BotText::PaySuccess, [
            'client' => $client,
            'plan' => $this->plan->name,
            'server' => $this->server->name,
            'duration' => Messages::duration($this->plan->duration_days),
            'traffic' => Messages::traffic($this->plan->trafficBytes()),
            'subscription' => $link,
        ]);
    }

    /** What «لینک اشتراک» shows of the service named `$client`, whose link the fixture kept. */
    private function linkShown(string $client): string
    {
        return self::text(BotText::LinkRequested, [
            'client' => $client,
            'plan' => $this->plan->name,
            'server' => $this->server->name,
            'subscription' => 'https://fake.test/sub/' . $client,
        ]);
    }
}
