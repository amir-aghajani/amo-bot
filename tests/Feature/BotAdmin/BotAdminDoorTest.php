<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Admin\SeatKind;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

/**
 * The bot's door to the panels' API (BotAdminDoor, PanelBridge): a seat's request runs the panel's very route in-process
 * — the owner's every section, an admin's daily work alone and its grants —, and nothing from outside gets in.
 */
final class BotAdminDoorTest extends DatabaseTestCase
{
    /** How many seats were made: each its own account. */
    private int $seats = 0;

    public function testTheOwnersSeatReadsThePanelsAnswer(): void
    {
        $this->plan(['name' => 'یک ماهه']);

        $plans = $this->bridge(SeatKind::Owner)->get('/plans');

        self::assertSame(['یک ماهه'], array_column($plans['plans'], 'name'));
    }

    public function testAnAdminsSeatHasTheDailyWorkAloneAndItsGrants(): void
    {
        $this->plan(['name' => 'یک ماهه']);
        $staff = $this->bridge(SeatKind::Staff);

        self::assertCount(1, $staff->get('/plans')['plans'], 'the daily work reads');
        $refused = self::refusal(fn() => $staff->get('/payment-methods'));
        self::assertSame(404, $refused->status, 'the configuration is no admin\'s');
        $refused = self::refusal(fn() => $staff->post('/plans', ['name' => 'x']));
        self::assertSame([403, BotAdminDoor::NOT_GRANTED], [$refused->status, $refused->getMessage()], 'the catalogue is a grant');

        $granted = $this->bridge(SeatKind::Staff, ['catalog']);
        $refused = self::refusal(fn() => $granted->post('/plans', []));
        self::assertSame(422, $refused->status, 'granted, the panel\'s own validation answers');
        self::assertNotSame([], $refused->errors);
    }

    public function testAGrantAServiceAsksIsRefusedInTheBotsWords(): void
    {
        $customer = $this->customer(['telegram_id' => 9001]);
        $colleagues = $this->customerGroup('همکاران', overrides: ['agent_pricing' => true]);

        $refused = self::refusal(fn() => $this->bridge(SeatKind::Staff)->put("/users/{$customer->id}/groups", ['group_ids' => [$colleagues->id]]));

        self::assertSame([403, BotAdminDoor::AGENT_GROUP_NOT_GRANTED], [$refused->status, $refused->getMessage()], 'the bot\'s admins, not the website\'s');
    }

    public function testASeatNeverTakesAnotherDoor(): void
    {
        $owner = new AdminSeat(SeatKind::Owner, new Principal(PrincipalKind::Owner, 'admin', CurrentBot::main()), $this->customer());
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://bot.internal' . BotAdminDoor::PATH . '/staff/plans')->withAttribute(BotAdminDoor::SEAT, $owner);

        self::assertSame(404, $this->app()->http()->handle($request)->getStatusCode());
    }

    public function testNothingFromOutsideGetsIn(): void
    {
        foreach (['owner/plans', 'staff/plans', 'agent/plans', 'owner/servers'] as $path) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . BotAdminDoor::PATH . '/' . $path);

            self::assertSame(404, $this->app()->http()->handle($request)->getStatusCode(), $path);
        }
    }

    /** @param list<string> $grants */
    private function bridge(SeatKind $kind, array $grants = []): PanelBridge
    {
        $user = $this->admin(['telegram_id' => 5151 + $this->seats++]);
        $principal = match ($kind) {
            SeatKind::Owner => new Principal(PrincipalKind::Owner, 'admin', CurrentBot::main()),
            SeatKind::Agent => new Principal(PrincipalKind::Agent, '@agent_bot', CurrentBot::main()),
            SeatKind::Staff => new Principal(PrincipalKind::Staff, 'tg:1', CurrentBot::main(), $user, $grants),
        };

        return $this->service(PanelBridge::class)->for(new AdminSeat($kind, $principal, $user));
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
