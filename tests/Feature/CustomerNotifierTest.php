<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Notifications\Services\CustomerChats;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Support\Money;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * What becomes of a notice the bot sends a customer on its own: it is composed and sent in the customer's own shop —
 * that bot's wording, from that bot —, whoever acted; a customer who turned the bot away — blocked it, or whose chat is
 * gone — is remembered and not written to again; Telegram out of reach costs the one message and a message Telegram
 * refuses is logged for the shop to see, the customer kept; a mistake of the code is no delivery problem and is never
 * swallowed as one.
 */
final class CustomerNotifierTest extends BotTestCase
{
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $server = $this->fakeServer();
        $this->subscription = $this->subscription($this->customer(), $this->plan([], $server), $server, 'ali_1');
    }

    public function testACustomerWhoBlockedTheBotIsRememberedAndNotWrittenToAgain(): void
    {
        $logs = $this->logs();
        $this->telegram()->fail(403, 'Forbidden: bot was blocked by the user');

        $this->notifier()->serviceEnabled($this->subscription);

        self::assertSame(['sendMessage'], $this->calls());
        self::assertTrue($this->subscription->user->refresh()->bot_blocked);
        self::assertTrue($logs->hasInfoThatContains('turned the bot away'));
        self::assertFalse($logs->hasErrorThatContains('Could not tell user'), 'nothing for the shop to fix');

        $this->telegram()->reset();
        $this->notifier()->serviceDisabled($this->subscription->refresh(), 'تست');
        self::assertSame([], $this->calls(), 'not written to again until they write to the bot');
    }

    public function testAChatThatIsGoneCountsAsTurnedAway(): void
    {
        $this->telegram()->fail(400, 'Bad Request: chat not found');

        $this->notifier()->serviceEnabled($this->subscription);

        self::assertTrue($this->subscription->user->refresh()->bot_blocked);
    }

    public function testTelegramOutOfReachCostsTheOneMessageAndARefusedOneIsTheShopsToSee(): void
    {
        $logs = $this->logs();

        $this->telegram()->fail(502, 'Bad Gateway');
        $this->notifier()->serviceEnabled($this->subscription);
        self::assertTrue($logs->hasWarningThatContains('Could not tell user'));

        $this->telegram()->fail(400, 'Bad Request: message is too long');
        $this->notifier()->serviceEnabled($this->subscription);
        self::assertTrue($logs->hasErrorThatContains('Could not tell user'));

        self::assertFalse($this->subscription->user->refresh()->bot_blocked, 'still there to hear the next one');
    }

    public function testAMistakeOfTheCodeIsNotSwallowedAsADeliveryProblem(): void
    {
        $this->expectExceptionObject(new \LogicException('A text was filled wrong.'));

        $this->service(CustomerChats::class)->send($this->subscription->user, static fn(): never => throw new \LogicException('A text was filled wrong.'), 'a test');
    }

    public function testARefundedTopUpSaysTheChargeLeftTheWallet(): void
    {
        $customer = $this->subscription->user;
        $payment = $this->paidByCard($this->topUpOrder($customer, '250000'));
        $this->telegram()->reset();

        $this->service(PaymentActions::class)->refund($payment, $this->panelActor(), 'اشتباه');

        self::assertSame([self::text(BotText::TopupRefunded, [
            'amount' => Money::format('250000'),
            'order' => $payment->order_id,
            'note' => self::text(BotText::AdminNote, ['comment' => 'اشتباه']),
            'balance' => Messages::balance($customer->refresh()->balance()),
        ])], $this->said(), 'not the purchase wording: the charge went out of the wallet again');
    }

    public function testAnAgentsCustomerHearsTheAgentsWordsFromTheAgentsBotWhoeverActs(): void
    {
        $bot = $this->agentBot();
        $server = $this->fakeServer('فرانسه');
        $theirs = CurrentBot::run($bot, function () use ($server): Subscription {
            $this->service(BotTexts::class)->save(BotText::ServiceEnabledBySupport, '✅ <code>%client%</code> دوباره روشن شد؛ خوش برگشتید!');

            return $this->subscription($this->customer(), $this->plan(['traffic_gb' => 10], $server), $server, 'reza_1');
        });

        // Support acts from the shop's own panel, in the main bot's shop: each customer hears it from their own bot.
        $this->notifier()->serviceEnabled($theirs);
        $this->notifier()->serviceEnabled($this->subscription);

        self::assertSame([FakeTelegram::AGENT_TOKEN, FakeTelegram::TOKEN], [$this->telegram()->tokenOf(0), $this->telegram()->tokenOf(1)]);
        self::assertSame([
            '✅ <code>reza_1</code> دوباره روشن شد؛ خوش برگشتید!',
            self::text(BotText::ServiceEnabledBySupport, ['client' => 'ali_1']),
        ], $this->said(), "the agent's wording for theirs, the shop's for its own");
    }

    private function notifier(): CustomerNotifier
    {
        return $this->service(CustomerNotifier::class);
    }
}
