<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * A button of the report group is a decision, and callback data is whatever the pressing client sends: a modified
 * client can write any `ag:`/`rv:`/`rt:` data under any message of a bot's it can see. An agent is the admin of their
 * own bot, so in their bot's group they pass the "bot admins only" rule — and still decide nothing but their own shop's
 * matters: never a request to become an agent (the main bot's alone), never a payment or an order of another shop.
 */
final class GroupButtonsAcrossShopsTest extends BotTestCase
{
    /** Some message of the agent's bot in their group, under which the forged data is pressed. */
    private const POST = 4141;

    private const THREAD = 11;

    private Bot $bot;
    private User $friend;
    private AgencyLevel $level;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyProgram(credit: '5000000');
        $this->level = $this->agencyLevel();
        $this->friend = $this->customer(['telegram_id' => 1001, 'username' => 'friend']);
        $this->bot = $this->agentBot();
        // The agent is their own bot's admin, as their first update there makes them.
        CurrentBot::run($this->bot, fn() => $this->admin(['telegram_id' => self::AGENT_TELEGRAM_ID, 'username' => 'agent']));
    }

    public function testAnAgentCannotDecideARequestToBecomeAnAgentFromTheirBotsGroup(): void
    {
        $request = $this->agencyRequest($this->friend);

        foreach (["ag:ok:{$request->id}", "ag:lv:{$request->id}:{$this->level->id}", "ag:no:{$request->id}"] as $forged) {
            $this->sendTo($this->bot, $this->groupTap($forged, self::POST, self::THREAD, self::AGENT_TELEGRAM_ID));

            self::assertSame(['answerCallbackQuery'], $this->calls(), "{$forged}: only acknowledged");
            self::assertArrayNotHasKey('text', $this->params(0), "{$forged}: nothing said about the request");
        }

        $request->refresh();
        self::assertSame([AgencyRequestStatus::Pending, null, null], [$request->status, $request->level_id, $request->reviewer], 'still the main bot\'s to decide');
        self::assertFalse($this->friend->refresh()->isAgent(), 'no agency, no credit');
        self::assertSame(1, AgencyRequest::query()->count());
    }

    public function testAnAgentCannotDecideAnotherShopsReceiptOrRetryItsOrder(): void
    {
        $payment = $this->receipt($this->cardPayment($this->topUpOrder($this->friend, '50000.00'), $this->cardMethod()));
        $failed = $this->topUpOrder($this->friend, '70000.00', ['status' => OrderStatus::Failed]);

        foreach (["rv:ok:{$payment->id}", "rv:nx:{$payment->id}", "rt:{$failed->id}"] as $forged) {
            $this->sendTo($this->bot, $this->groupTap($forged, self::POST, self::THREAD, self::AGENT_TELEGRAM_ID));

            self::assertSame('true', $this->params(1)['show_alert'] ?? null, "{$forged}: not found in the agent's shop");
        }

        self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status);
        self::assertNull($payment->reviewer);
        self::assertSame(OrderStatus::Failed, $failed->refresh()->status);
        self::assertSame('0.00', $this->friend->balance(), 'no top-up landed');
    }
}
