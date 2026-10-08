<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Auth\Actor;
use App\Modules\Auth\CurrentPrincipal;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Telegram\Broadcasts\Audience;
use App\Modules\Telegram\Broadcasts\BroadcastMode;
use App\Modules\Telegram\Broadcasts\BroadcastService;
use App\Modules\Telegram\Models\Broadcast;
use Tests\HttpTestCase;

/**
 * The owner's login is half of what opens the panel, and SignInThrottle counts failed sign-ins per username: whoever
 * has the name can lock the owner out. Nobody but the owner reads it — not an agent, not a shop's admins on its website.
 * What the owner decided in the agent's shop — a receipt approved, their traffic set right, the pins of a broadcast taken
 * off — is «پشتیبانی» in every answer of the agent's panel, and what they decided in the main bot's shop in every answer
 * of its website's admin API, while the shop's own people show as kept: the agent, their bot's admins. The owner's panel
 * shows it as kept.
 */
final class OwnerLoginHiddenFromAgentsTest extends HttpTestCase
{
    private const OWNER = 'qa-owner-7';

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureAdmin(self::OWNER);
        $this->telegram();
        $this->bot = $this->agentBot();
    }

    public function testNoAnswerOfTheAgentsPanelNamesTheOwnersLogin(): void
    {
        [$approved, $byHelper, $byAgent, $pinned] = CurrentBot::run($this->bot, function (): array {
            $card = $this->cardMethod();
            $customer = $this->customer(['telegram_id' => 31]);
            $helper = $this->admin(['telegram_id' => 32, 'username' => 'helper']);
            $receipt = fn(string $amount): Payment => $this->receipt($this->cardPayment($this->topUpOrder($customer, $amount), $card));

            $approved = $receipt('10000.00');
            $byHelper = $receipt('20000.00');
            $this->service(PaymentActions::class)->reject($byHelper, Actor::groupAdmin($helper), 'ناخوانا');
            $broadcasts = $this->service(BroadcastService::class);
            $pinned = $broadcasts->start($helper, ['message_id' => 5, 'mode' => BroadcastMode::Copy, 'audience' => Audience::ALL, 'pin' => true], 77);
            $broadcasts->process($pinned, 50);

            return [$approved, $byHelper, $receipt('30000.00'), $pinned];
        });

        // The owner works in the agent's shop, and sets the agent's traffic right from the agents page.
        $this->loginAsAdmin();
        $this->openShop($this->bot);
        self::assertSame(200, $this->postJson("/api/admin/payments/{$approved->id}/approve")->getStatusCode());
        self::assertSame(201, $this->postJson("/api/admin/broadcasts/{$pinned->id}/unpin")->getStatusCode());
        $agent = $this->bot->agent ?? self::fail('The bot has no agent.');
        self::assertSame(200, $this->postJson("/api/admin/agency/agents/{$agent->id}/traffic", ['gb' => 5, 'note' => 'جبران قطعی'])->getStatusCode());
        self::assertSame(self::OWNER, $this->decode($this->get("/api/admin/agency/agents/{$agent->id}/traffic"))['lines'][0]['reviewer'], "the owner's own page, in the main bot's shop");

        // The agent decides one of their own.
        $this->loginAsAgent($this->bot);
        self::assertSame(200, $this->postJson("/api/agent/payments/{$byAgent->id}/cancel", [])->getStatusCode());

        $payments = array_column($this->decode($this->get('/api/agent/payments'))['payments'], 'reviewer', 'id');
        self::assertSame([$byAgent->id => '@agent_shop_bot', $byHelper->id => '@helper', $approved->id => Reviewers::SUPPORT], array_intersect_key($payments, [$byAgent->id => 0, $byHelper->id => 0, $approved->id => 0]));
        self::assertSame(Reviewers::SUPPORT, $this->decode($this->get("/api/agent/payments/{$approved->id}"))['payment']['reviewer']);
        self::assertSame(Reviewers::SUPPORT, $this->decode($this->get('/api/agent/account/traffic'))['lines'][0]['reviewer']);
        $unpin = Broadcast::query()->withoutGlobalScope(CurrentBot::SCOPE)->where('source_id', $pinned->id)->sole();
        self::assertSame(Reviewers::SUPPORT, array_column($this->decode($this->get('/api/agent/broadcasts'))['broadcasts'], 'reviewer', 'id')[$unpin->id]);

        foreach (['/api/agent/payments', "/api/agent/payments/{$approved->id}", '/api/agent/orders', '/api/agent/broadcasts', '/api/agent/account', '/api/agent/account/traffic', '/api/agent/dashboard', '/api/agent/queues', '/api/agent/auth/me'] as $path) {
            $response = $this->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertStringNotContainsString(self::OWNER, (string) $response->getBody(), $path);
        }
    }

    /**
     * The main bot's shop is no longer the owner's alone to read: its admins work it from its website. What the owner
     * decided there — a receipt approved, a ticket answered, a wallet credited, a broadcast's pins taken off — is
     * «پشتیبانی» in every answer of the website's admin API, the admins themselves as kept; the owner's panel shows it all.
     */
    public function testNoAnswerOfTheWebsitesAdminApiNamesTheOwnersLogin(): void
    {
        $customer = $this->customer(['telegram_id' => 31]);
        $card = $this->cardMethod();
        $approved = $this->receipt($this->cardPayment($this->topUpOrder($customer, '10000.00'), $card));
        $ticket = $this->ticket($customer);
        $broadcasts = $this->service(BroadcastService::class);
        $pinned = $broadcasts->start($this->admin(['telegram_id' => 32, 'username' => 'helper']), ['message_id' => 5, 'mode' => BroadcastMode::Copy, 'audience' => Audience::ALL, 'pin' => true], 77);
        $broadcasts->process($pinned, 50);

        $this->loginAsAdmin();
        self::assertSame(200, $this->postJson("/api/admin/payments/{$approved->id}/approve")->getStatusCode());
        self::assertSame(200, $this->postJson("/api/admin/tickets/{$ticket->id}/messages", ['body' => 'درست شد.'])->getStatusCode());
        self::assertSame(201, $this->postJson("/api/admin/users/{$customer->id}/wallet", ['type' => 'credit', 'amount' => 5000])->getStatusCode());
        self::assertSame(201, $this->postJson("/api/admin/broadcasts/{$pinned->id}/unpin")->getStatusCode());
        self::assertSame(self::OWNER, $this->decode($this->get("/api/admin/payments/{$approved->id}"))['payment']['reviewer'], "the owner's panel shows it as kept");

        $website = $this->website();
        $this->loginAsStaff($website, $this->customer(['telegram_id' => 33, 'username' => 'sara']));
        $admin = fn(string $path): string => $this->storeApi($website, '/admin' . $path);
        self::assertSame(Reviewers::SUPPORT, $this->decode($this->get($admin("/payments/{$approved->id}")))['payment']['reviewer']);
        self::assertSame(Reviewers::SUPPORT, array_column($this->decode($this->get($admin("/tickets/{$ticket->id}")))['ticket']['messages'], 'reviewer')[1]);
        self::assertSame(Reviewers::SUPPORT, $this->decode($this->get($admin("/users/{$customer->id}/wallet")))['transactions'][0]['reviewer']);
        $unpin = Broadcast::query()->where('source_id', $pinned->id)->sole();
        self::assertSame(Reviewers::SUPPORT, array_column($this->decode($this->get($admin('/broadcasts')))['broadcasts'], 'reviewer', 'id')[$unpin->id]);

        // The admin's own decision shows as kept.
        self::assertSame(200, $this->postJson($admin("/tickets/{$ticket->id}/messages"), ['body' => 'بررسی شد.'])->getStatusCode());
        self::assertSame([Reviewers::SUPPORT, '@sara'], array_slice(array_column($this->decode($this->get($admin("/tickets/{$ticket->id}")))['ticket']['messages'], 'reviewer'), 1));

        foreach (['/payments', "/payments/{$approved->id}", "/tickets/{$ticket->id}", "/users/{$customer->id}/wallet", '/broadcasts', '/dashboard', '/queues', '/orders'] as $path) {
            $response = $this->get($admin($path));
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertStringNotContainsString(self::OWNER, (string) $response->getBody(), $path);
        }
    }

    /** By whoever reads it: the owner reads every name as kept; anyone else — an agent, the shop's admins, nobody's request — the owner's as «پشتیبانی». */
    public function testTheOwnersLoginIsKnownWhateverItLooksLike(): void
    {
        // A login may start with "@", like a bot admin's handle; one the owner had before is no handle of the shop's.
        $this->configureAdmin('@boss');
        $reviewers = $this->service(Reviewers::class);
        $read = static fn(): array => array_map($reviewers->present(...), ['@boss', 'old-login', '@helper', 'tg:31', 'user#12', 'agent#9', null]);
        $hidden = [Reviewers::SUPPORT, Reviewers::SUPPORT, '@helper', 'tg:31', 'user#12', 'agent#9', null];

        self::assertSame($hidden, CurrentPrincipal::run(new Principal(PrincipalKind::Agent, '@agent_shop_bot', $this->bot), $read), 'an agent');
        self::assertSame($hidden, CurrentPrincipal::run(new Principal(PrincipalKind::Staff, '@sara', CurrentBot::main(), $this->customer()), $read), "the main bot's shop's admins on its website");
        self::assertSame($hidden, $read(), "nobody's request");
        self::assertSame(['@boss', 'old-login', '@helper', 'tg:31', 'user#12', 'agent#9', null], CurrentPrincipal::run(new Principal(PrincipalKind::Owner, '@boss', $this->bot), $read), 'the owner, in any shop');
    }
}
