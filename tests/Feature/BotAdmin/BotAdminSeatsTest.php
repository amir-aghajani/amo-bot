<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Http\ErrorHandler;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Telegram\Admin\SeatKind;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * Who manages a shop from its bot, and nothing more (AdminSeats, OwnerLinks): the owner's link spent once whoever opens
 * it at the same moment, an agent whose agency ended managing nothing, and a forged button — another shop's payment, the
 * owner's shop switch — doing nothing the seat could not do in its own panel.
 */
final class BotAdminSeatsTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnersLinkIsSpentOnceWhenTwoOpenItAtOnce(): void
    {
        $links = $this->service(OwnerLinks::class);
        $payload = self::payload($links->link());
        // Another request (another update, its own process) read the settings while the link was open.
        $elsewhere = new OwnerLinks(new Settings(), $this->service(Bots::class));
        self::assertTrue($elsewhere->opens($payload));

        self::assertTrue($links->redeem($payload, 7001));
        self::assertFalse($elsewhere->redeem($payload, 7002), 'spent: the other opening links nothing');
        self::assertSame([7001], (new OwnerLinks(new Settings(), $this->service(Bots::class)))->ids());

        // A new link made while a stale reader still holds the old one: the old one opens nothing, the new one stays open.
        $stale = new OwnerLinks(new Settings(), $this->service(Bots::class));
        $old = self::payload($links->link());
        self::assertTrue($stale->opens($old));
        $new = self::payload($links->link());
        self::assertFalse($stale->redeem($old, 7003));
        self::assertTrue($links->opens($new), 'the new link is not spent by the old one\'s failure');
        self::assertSame([7001], $links->ids());
    }

    public function testAnAgentWhoseAgencyEndedManagesNothing(): void
    {
        $bot = $this->agentBot();
        $account = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => self::AGENT_TELEGRAM_ID]));
        $seats = $this->service(AdminSeats::class);
        $seat = CurrentBot::run($bot, static fn(): ?AdminSeat => $seats->of($account));
        self::assertSame(SeatKind::Agent, $seat?->kind);

        CurrentBot::run(Bot::MAIN, static fn(): bool => $bot->agent()->firstOrFail()->forceFill(['agency_level_id' => null])->save());
        $bot = $bot->refresh();
        self::assertSame(BotStatus::Disabled, $bot->status());

        self::assertNull(CurrentBot::run($bot, static fn(): ?AdminSeat => $seats->of($account)), 'as their panel refuses them');
        self::assertFalse(CurrentBot::run($bot, static fn(): bool => $seats->exempt($account)));
    }

    public function testAForgedTapNamingAnotherShopsPaymentChangesNothing(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, function (): Payment {
            $customer = $this->customer(['telegram_id' => 8800]);

            return $this->receipt($this->cardPayment($this->topUpOrder($customer, '50000.00'), $this->cardMethod()));
        });
        $ours = $this->receipt($this->cardPayment($this->topUpOrder($this->customer(['telegram_id' => 8801]), '70000.00'), $this->cardMethod()));

        // One of the main shop's admins, at an agent's payment.
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $this->send($this->tap(Nav::to('pyv', 'ok', $theirs->id), chat: 7100));
        self::assertSame(ErrorHandler::NOT_FOUND, $this->popup());
        self::assertSame(PaymentStatus::AwaitingReview, CurrentBot::run($bot, static fn(): PaymentStatus => $theirs->refresh()->status));

        // The agent, at the main shop's.
        $this->sendTo($bot, $this->tap(Nav::to('pyv', 'ok', $ours->id), chat: self::AGENT_TELEGRAM_ID));
        self::assertSame(ErrorHandler::NOT_FOUND, $this->popup());
        self::assertSame(PaymentStatus::AwaitingReview, $ours->refresh()->status);
    }

    public function testOnlyTheOwnerSwitchesShops(): void
    {
        $bot = $this->agentBot();
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('shop', 'pick', $bot->id), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'the home instead');
        self::assertStringNotContainsString('فروشگاه نماینده', $this->said()[0]);
        $fact = $this->service(SessionStore::class)->load(7100)->get(Admin::FACT);
        self::assertNull(is_array($fact) ? $fact['shop'] ?? null : null, 'no shop picked');

        // A pick the chat kept from when it was the owner's is no admin's: their seat works their bot's own shop.
        $session = $this->service(SessionStore::class)->load(7100);
        $session->put(Admin::FACT, ['shop' => $bot->id]);
        $session->save();
        $seat = $this->service(AdminSeats::class)->of(User::query()->where('telegram_id', 7100)->firstOrFail(), $bot->id);
        self::assertSame([SeatKind::Staff, Bot::MAIN], [$seat?->kind, $seat?->shop()->id]);
    }

    /**
     * The /start payload of a link OwnerLinks::link() made.
     *
     * @param array{url: string}|null $link
     */
    private static function payload(?array $link): string
    {
        self::assertNotNull($link);

        return substr($link['url'], (int) strpos($link['url'], '=') + 1);
    }
}
