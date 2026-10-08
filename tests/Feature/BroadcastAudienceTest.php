<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Telegram\Broadcasts\Audience;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Tests\DatabaseTestCase;

/**
 * Whom each audience of «ارسال همگانی» reaches — never a banned customer: buyers are those with a purchase sold (a
 * wallet top-up alone is not one), the inactive ones those with no service running now, a group its members, a
 * server's those with a service running there — of the servers the bot's own customers are on.
 */
final class BroadcastAudienceTest extends DatabaseTestCase
{
    public function testEachAudienceReachesItsCustomers(): void
    {
        $germany = $this->fakeServer('آلمان');
        $finland = $this->fakeServer('فنلاند');
        $plan = $this->plan([], [$germany, $finland]);

        // Ali: bought, a service running in Germany.
        $ali = $this->customer(['telegram_id' => 1001, 'first_name' => 'Ali']);
        $this->purchaseOrder($ali, $plan, $germany, ['status' => OrderStatus::Fulfilled]);
        $this->subscription($ali, $plan, $germany, 'ali_1');
        // Sara: bought, her service ended.
        $sara = $this->customer(['telegram_id' => 1002, 'first_name' => 'Sara']);
        $this->purchaseOrder($sara, $plan, $germany, ['status' => OrderStatus::Fulfilled]);
        $this->subscription($sara, $plan, $germany, 'sara_1', ['status' => SubscriptionStatus::Expired]);
        // Reza: never bought; has blocked the bot.
        $reza = $this->customer(['telegram_id' => 1003, 'first_name' => 'Reza', 'bot_blocked' => true]);
        // Mina: an agent, a service running in Finland.
        $mina = $this->agent(overrides: ['telegram_id' => 1004, 'first_name' => 'Mina']);
        $this->purchaseOrder($mina, $plan, $finland, ['status' => OrderStatus::Fulfilled]);
        $this->subscription($mina, $plan, $finland, 'mina_1');
        // Kaveh: only charged his wallet; an unpaid purchase is no purchase either.
        $kaveh = $this->customer(['telegram_id' => 1005, 'first_name' => 'Kaveh']);
        $this->topUpOrder($kaveh, '50000.00', ['status' => OrderStatus::Fulfilled]);
        $this->purchaseOrder($kaveh, $plan, $germany);
        // Bano: banned — in no audience, whatever she bought.
        $bano = $this->customer(['telegram_id' => 1006, 'first_name' => 'Bano', 'status' => UserStatus::Banned]);
        $this->purchaseOrder($bano, $plan, $germany, ['status' => OrderStatus::Fulfilled]);
        $this->subscription($bano, $plan, $germany, 'bano_1');

        $vip = $this->customerGroup('VIP', [$ali, $reza, $bano]);

        $reach = static fn(string $key, ?int $id = null): array => Audience::of($key, $id)?->users()->oldest('id')->pluck('first_name')->all() ?? [];
        self::assertSame(['Ali', 'Sara', 'Reza', 'Mina', 'Kaveh'], $reach(Audience::ALL));
        self::assertSame(['Ali', 'Sara', 'Mina'], $reach(Audience::BUYERS));
        self::assertSame(['Reza', 'Kaveh'], $reach(Audience::NON_BUYERS));
        self::assertSame(['Sara', 'Reza', 'Kaveh'], $reach(Audience::INACTIVE), 'no service running now: never bought, or every one over');
        self::assertSame(['Ali', 'Reza'], $reach(Audience::GROUP, $vip->id));
        self::assertSame(['Mina'], $reach(Audience::AGENTS));
        self::assertSame(['Ali'], $reach(Audience::SERVER, $germany->id), 'a service running there');
        self::assertSame(['Mina'], $reach(Audience::SERVER, $finland->id));

        self::assertSame(['total' => 5, 'blocked' => 1], Audience::of(Audience::ALL)?->size(), 'the blocker counted, never sent to');
        self::assertSame('گروه «VIP»', Audience::of(Audience::GROUP, $vip->id)?->label());
        self::assertSame('مشتری‌های سرور «فنلاند»', Audience::labelOf(Audience::SERVER, $finland->id));

        // A server none of this bot's customers runs a service on is not this bot's to name, nor to reach.
        $holland = $this->fakeServer('هلند');
        $this->subscription($sara, $plan, $holland, 'sara_2', ['status' => SubscriptionStatus::Expired]);
        self::assertSame([$germany->id, $finland->id], Audience::servers()->modelKeys());
        self::assertNull(Audience::of(Audience::SERVER, $holland->id));

        $vip->delete();
        self::assertNull(Audience::of(Audience::GROUP, $vip->id), 'a group that is gone names nobody');
        self::assertSame('گروهی که حذف شده', Audience::labelOf(Audience::GROUP, $vip->id));
        self::assertNull(Audience::of('everyone'));
        self::assertSame(1, User::query()->where('status', UserStatus::Banned->value)->count());
    }

    public function testARunsAudienceSaysSoOnceItsServerIsGone(): void
    {
        $server = $this->fakeServer('آلمان');
        $service = $this->subscription($this->customer(), $this->plan(), $server, 'ali_1');
        self::assertSame('مشتری‌های سرور «آلمان»', Audience::labelOf(Audience::SERVER, $server->id));

        // Its services deleted, then the server itself.
        $service->delete();
        Server::query()->whereKey($server->id)->delete();

        self::assertNull(Audience::of(Audience::SERVER, $server->id), 'nobody to reach');
        self::assertSame('مشتری‌های سروری که حذف شده', Audience::labelOf(Audience::SERVER, $server->id));
    }
}
