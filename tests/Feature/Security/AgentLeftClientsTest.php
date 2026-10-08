<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionActions;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * A move that leaves the old client where it was, or a delete that leaves the client on its panel, is for a panel out
 * of reach. In an agent's shop it is refused while the panel answers: the client left behind keeps working on the
 * shop's server, so a "move" would be a second service — and a third, on the next server — that no traffic of the
 * agent's paid for. Once the shop has seen the panel fail (the screen's first try), the agent may leave it out as the
 * owner may.
 */
final class AgentLeftClientsTest extends HttpTestCase
{
    private Bot $bot;
    private Server $server;
    private Server $target;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePanel();
        $this->telegram();
        $this->server = $this->fakeServer();
        $this->target = $this->fakeServer('هلند');
        $this->inbound($this->target, '7');
        $this->bot = $this->agentBot();
        CurrentBot::run($this->bot, fn() => $this->withoutQr());
        $this->subscription = CurrentBot::run($this->bot, function (): Subscription {
            $customer = $this->customer(['telegram_id' => 2001, 'username' => 'reza']);

            return $this->subscription($customer, $this->plan(['name' => 'پلن نماینده']), $this->server, 'reza_1');
        });
        FakeProvider::put($this->server, new ClientInfo(name: 'reza_1', enabled: true, subscriptionUrl: 'https://fake.test/sub/reza_1'));
        $this->loginAsAgent($this->bot);
    }

    public function testAnAgentCannotMoveAServiceAndLeaveItsClientOnAPanelThatAnswers(): void
    {
        $response = $this->postJson("/api/agent/subscriptions/{$this->subscription->id}/move", ['server_id' => $this->target->id, 'leave_previous' => true]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([sprintf(SubscriptionActions::LEAVE_REFUSED, 'آلمان')], $this->decode($response)['errors']['status']);
        self::assertSame([], FakeProvider::$created, 'no second client on the target');
        self::assertArrayHasKey('reza_1', FakeProvider::$clients[$this->server->id]);
        self::assertSame($this->server->id, $this->subscription->refresh()->server_id);
    }

    public function testAnAgentCannotDeleteAServiceAndLeaveItsClientOnAPanelThatAnswers(): void
    {
        $response = $this->postJson("/api/agent/subscriptions/{$this->subscription->id}/delete", ['leave_panel' => true]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([sprintf(SubscriptionActions::LEAVE_REFUSED, 'آلمان')], $this->decode($response)['errors']['status']);
        self::assertTrue(Subscription::query()->withoutGlobalScopes()->whereKey($this->subscription->id)->exists());

        self::assertSame(204, $this->postJson("/api/agent/subscriptions/{$this->subscription->id}/delete", [])->getStatusCode(), 'a delete that takes the client off the panel is theirs to make');
        self::assertSame([['server' => $this->server->id, 'name' => 'reza_1']], FakeProvider::$deleted);
    }

    public function testOnceThePanelWasSeenFailingTheAgentMayLeaveItOut(): void
    {
        FakeProvider::$down = [$this->server->id];

        $first = $this->postJson("/api/agent/subscriptions/{$this->subscription->id}/move", ['server_id' => $this->target->id]);
        self::assertSame(502, $first->getStatusCode());
        self::assertArrayHasKey('previous', $this->decode($first)['errors'], 'the cue to offer leaving it out');

        $response = $this->postJson("/api/agent/subscriptions/{$this->subscription->id}/move", ['server_id' => $this->target->id, 'leave_previous' => true]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($this->target->id, $this->subscription->refresh()->server_id);
        self::assertSame([], FakeProvider::$deleted, 'the panel out of reach was not contacted');
    }
}
