<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Telegram\Admin\SeatKind;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

/**
 * The bot's door to the panels' API (BotAdminDoor, PanelBridge): a seat's request runs the panel's very route in-process
 * — the owner's every section, for its linked accounts and the main shop's admins alike; the agent's panel's in an
 * agent's bot —, each seat at its own panel's door alone, and nothing from outside gets in.
 */
final class BotAdminDoorTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnersSeatReadsThePanelsAnswer(): void
    {
        $this->plan(['name' => 'یک ماهه']);

        $plans = $this->bridge($this->linkedOwner())->get('/plans');

        self::assertSame(['یک ماهه'], array_column($plans['plans'], 'name'));
    }

    public function testAnAdminsSeatInTheMainBotHasEveryOneOfTheOwnersSections(): void
    {
        $this->plan(['name' => 'یک ماهه']);
        $admin = $this->admin(['telegram_id' => 5151, 'username' => 'sara_admin']);
        $bridge = $this->bridge($admin);

        self::assertCount(1, $bridge->get('/plans')['plans'], 'the daily work');
        self::assertArrayHasKey('methods', $bridge->get('/payment-methods'), 'the configuration');
        self::assertArrayHasKey('servers', $bridge->get('/servers'), 'the shop\'s own sections, the servers among them');
        self::assertArrayHasKey('summary', $bridge->get('/agency'));
        self::assertArrayHasKey('system', $bridge->get('/system'), 'the installation');
        $refused = self::refusal(fn() => $bridge->post('/plans', []));
        self::assertSame(422, $refused->status, 'the catalogue: the panel\'s own validation answers, no grant asked');
        self::assertNotSame([], $refused->errors);

        // Their decisions keep their own name, not the owner's login.
        $review = $this->review();
        $bridge->post("/reviews/{$review->id}/approve");
        self::assertSame(['approved', Reviewers::forAdmin($admin)], [$review->refresh()->status->value, $review->reviewer]);
        self::assertSame('@sara_admin', $review->reviewer);
    }

    public function testAnAdminPutsACustomerInAPartnerGroupAsTheOwnerDoes(): void
    {
        $customer = $this->customer(['telegram_id' => 9001]);
        $colleagues = $this->customerGroup('همکاران', overrides: ['agent_pricing' => true]);

        $this->bridge($this->admin(['telegram_id' => 5151]))->put("/users/{$customer->id}/groups", ['group_ids' => [$colleagues->id]]);

        self::assertSame([$colleagues->id], $customer->groups()->get()->modelKeys(), 'no grant of the catalogue asked');
    }

    public function testTheOwnersTelegramAccountsAreTheOwnerThemselvesAlone(): void
    {
        $owner = $this->bridge($this->linkedOwner());
        $admin = $this->bridge($this->admin(['telegram_id' => 5151]));

        foreach ([
            'GET' => static fn(PanelBridge $bridge) => $bridge->get('/settings/owner-telegram'),
            'POST' => static fn(PanelBridge $bridge) => $bridge->post('/settings/owner-telegram/link'),
            'DELETE' => static fn(PanelBridge $bridge) => $bridge->delete('/settings/owner-telegram/7001'),
        ] as $method => $call) {
            $refused = self::refusal(static fn() => $call($admin));
            self::assertSame([403, ActorRefusedException::OWNER_ONLY], [$refused->status, $refused->getMessage()], "{$method}: an admin on the owner's seat is not the owner");
        }
        self::assertSame([7001], $this->service(OwnerLinks::class)->ids(), 'nothing changed');
        self::assertNull($this->service(OwnerLinks::class)->openUntil(), 'no link made');

        self::assertSame([7001], array_column($owner->get('/settings/owner-telegram')['accounts'], 'telegram_id'), 'the owner reads them');
        self::assertIsString($owner->post('/settings/owner-telegram/link')['link']['url'] ?? null, 'and makes a link');
    }

    public function testAnAgentsBotAdminTakesTheAgentsDoorAlone(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn() => $this->plan(['name' => 'پلن نماینده']));
        $this->plan(['name' => 'پلن فروشگاه اصلی']);
        $admin = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 5252, 'role' => UserRole::Admin]));

        $seat = CurrentBot::run($bot, fn(): ?AdminSeat => $this->service(AdminSeats::class)->of($admin));
        self::assertSame(SeatKind::Agent, $seat?->kind);
        $bridge = $this->service(PanelBridge::class)->for($seat);

        self::assertSame([$theirs->name], array_column($bridge->get('/plans')['plans'], 'name'), 'their bot\'s shop');
        self::assertArrayHasKey('account', $bridge->get('/account'), 'the agent\'s panel\'s own');
        foreach (['/servers', '/system', '/agency'] as $path) {
            self::assertSame(404, self::refusal(fn() => $bridge->get($path))->status, "{$path}: the owner's, never an agent's");
        }
    }

    public function testASeatNeverTakesAnotherDoor(): void
    {
        $owner = $this->seat($this->admin(['telegram_id' => 5151]));
        $bot = $this->agentBot();
        $agent = CurrentBot::run($bot, fn(): ?AdminSeat => $this->service(AdminSeats::class)->of(CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => self::AGENT_TELEGRAM_ID]))));
        self::assertNotNull($agent);

        foreach ([[$owner, 'agent/plans'], [$agent, 'owner/plans'], [$agent, 'owner/servers']] as [$seat, $path]) {
            self::assertSame(404, $this->ask($path, $seat), "{$seat->kind->value} at {$path}");
        }
    }

    public function testTheStaffDoorIsGone(): void
    {
        $seat = $this->seat($this->admin(['telegram_id' => 5151]));

        foreach (['staff/plans', 'staff/payments', 'staff/users'] as $path) {
            self::assertSame(404, $this->ask($path, $seat), "{$path}: an admin's seat is the owner's, at the owner's door");
            self::assertSame(404, $this->ask($path), "{$path}: from outside");
        }
    }

    public function testNothingFromOutsideGetsIn(): void
    {
        foreach (['owner/plans', 'staff/plans', 'agent/plans', 'owner/servers'] as $path) {
            self::assertSame(404, $this->ask($path), $path);
        }
    }

    /** The owner's linked Telegram account in the main bot (OwnerLinks), as opening the panel's one-time link makes it. */
    private function linkedOwner(): User
    {
        $owner = $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $links = $this->service(OwnerLinks::class);
        $link = $links->link();
        self::assertNotNull($link);
        $links->redeem(substr($link['url'], (int) strpos($link['url'], '=') + 1), 7001);

        return $owner;
    }

    /** `$user`'s seat in the main bot, as the bot gives it on their next update (AdminSeats::of()). */
    private function seat(User $user): AdminSeat
    {
        $seat = $this->service(AdminSeats::class)->of($user);
        self::assertNotNull($seat, 'a seat');

        return $seat;
    }

    private function bridge(User $user): PanelBridge
    {
        return $this->service(PanelBridge::class)->for($this->seat($user));
    }

    /** The door's status for a GET of `$path` under it, with `$seat` put on the request in-process — or none, from outside. */
    private function ask(string $path, ?AdminSeat $seat = null): int
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://bot.internal' . BotAdminDoor::PATH . '/' . $path);

        return $this->app()->http()->handle($seat === null ? $request : $request->withAttribute(BotAdminDoor::SEAT, $seat))->getStatusCode();
    }

    private static function refusal(\Closure $call): ApiRefusal
    {
        try {
            $call();
        } catch (ApiRefusal $refusal) {
            return $refusal;
        }

        self::fail('the API answered');
    }
}
