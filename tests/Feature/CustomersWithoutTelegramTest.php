<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Notifications\Services\CustomerChats;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Telegram\Broadcasts\Audience;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * A customer who signed up on the shop's website has no Telegram account: the bot writes them nothing — a notice says
 * so (`no_telegram`) and asks Telegram nothing —, no broadcast counts or reaches them, their service's client on a panel
 * points back at their account by their number in the shop, the report group names them by their email and that number,
 * and the panels find them by their email and show it where the Telegram identifiers would be.
 */
final class CustomersWithoutTelegramTest extends HttpTestCase
{
    public function testTheBotWritesThemNothingAndSaysSo(): void
    {
        $telegram = $this->telegram();
        $sara = $this->webCustomer();
        $chats = $this->service(CustomerChats::class);

        self::assertSame(Delivery::NoTelegram, $chats->send($sara, static fn(): never => self::fail('nothing is sent'), 'their account'));

        $this->loginAsAdmin();
        $payment = $this->cardPayment($this->topUpOrder($sara, '50000.00'), $this->cardMethod());
        $reminder = $this->postJson("/api/admin/payments/{$payment->id}/remind");
        self::assertSame(200, $reminder->getStatusCode(), (string) $reminder->getBody());
        self::assertSame('no_telegram', $this->decode($reminder)['delivery'], 'the panel says why the reminder did not go');
        self::assertSame([], $telegram->calls(), 'Telegram is not asked');
        self::assertFalse($sara->refresh()->bot_blocked, 'nor is she taken for someone who turned the bot away');
    }

    public function testNoBroadcastCountsOrReachesThem(): void
    {
        $ali = $this->customer();
        $this->webCustomer();

        $everyone = Audience::of(Audience::ALL) ?? self::fail('No audience.');

        self::assertSame([$ali->id], $everyone->users()->pluck('id')->all());
        self::assertSame(['total' => 1, 'blocked' => 0], $everyone->size());
        self::assertSame([], (Audience::of(Audience::NON_BUYERS) ?? self::fail('No audience.'))->users()->where('telegram_id', null)->pluck('id')->all());
    }

    public function testTheirServicesClientPointsBackAtTheirAccountByTheirNumber(): void
    {
        $server = $this->sellingServer();
        $sara = $this->webCustomer();

        self::assertSame("web#{$sara->id} | USER", $this->service(ClientNaming::class)->comment($sara));

        $this->buy($sara, $this->plan([], $server), $server);
        $spec = FakeProvider::$created[0]['spec'];
        self::assertSame(["web#{$sara->id} | USER", null], [$spec->comment, $spec->telegramId], 'no Telegram id on the panel: it has none');
        self::assertStringStartsWith('USER_', $spec->name, 'no handle to name the client after');
    }

    public function testTheReportGroupNamesThemByTheirEmailAndNumber(): void
    {
        $this->reportGroup();
        $server = $this->sellingServer();
        $sara = $this->webCustomer();

        $this->buy($sara, $this->plan([], $server), $server);

        $sale = (string) ReportMessage::query()->where('topic', Topic::Purchases)->value('text');
        self::assertStringContainsString("👤 Sara Ahmadi · sara@example.com · <code>#{$sara->id}</code>", $sale);
        self::assertStringNotContainsString('tg://user', $sale, 'no Telegram profile to open');
    }

    public function testThePanelsFindThemByTheirEmailAndShowIt(): void
    {
        $this->loginAsAdmin();
        $sara = $this->webCustomer();
        $this->customer(['first_name' => 'Ali']);

        $rows = $this->decode($this->get('/api/admin/users?search=' . rawurlencode('SARA@example')))['users'];

        self::assertSame([$sara->id], array_column($rows, 'id'), 'by a piece of the address, however it is typed');
        self::assertSame(['id' => $sara->id, 'name' => 'Sara Ahmadi', 'username' => null, 'telegram_id' => null, 'email' => self::WEB_EMAIL], array_intersect_key($rows[0], array_flip(['id', 'name', 'username', 'telegram_id', 'email'])));
        self::assertSame(self::WEB_EMAIL, $this->decode($this->get("/api/admin/users/{$sara->id}"))['user']['email'], 'on their page too');
    }
}
