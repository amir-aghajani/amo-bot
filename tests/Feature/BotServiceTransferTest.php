<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Telegram\Handlers\ExtrasHandler;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\ServiceTransferHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «🔁 انتقال سرویس» in the bot: the button on a service's screen while the shop lets customers and the service may go;
 * the recipient's numeric Telegram id asked for and typed (Persian digits too); who that is, to confirm or cancel; the
 * service handed over once confirmed, the question answered in place and the recipient told. A refused recipient says
 * why and another id is awaited; a service that may not go says why; a confirmation is claimed once.
 */
final class BotServiceTransferTest extends BotTestCase
{
    private const SARA = 7070;

    private User $ali;
    private User $sara;
    private Server $server;
    private Plan $plan;
    private Subscription $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 12:00:00');
        $this->withoutQr();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->sara = $this->customer(['telegram_id' => self::SARA, 'first_name' => 'Sara', 'last_name' => 'Ahmadi', 'username' => 'sara']);
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
        $this->service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['auto_renew' => true]));
    }

    public function testTheScreenOffersTheTransferOnlyWhileTheShopDoesAndTheServiceMayGo(): void
    {
        $ask = ServiceTransferHandler::askCallback($this->service->id);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($this->service->id)));
        self::assertNotContains($ask, $this->callbacks(0), 'off by default');

        $this->transfersOn();
        $this->send($this->tap(SubscriptionHandler::serviceCallback($this->service->id)));
        $keyboard = $this->inlineKeyboard(0);
        self::assertSame([['text' => self::text(BotText::ServiceTransfer), 'callback_data' => $ask]], $keyboard[count($keyboard) - 2], 'a row of its own, above «بازگشت»');

        $test = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'test_ali_1', ['is_test' => true, 'plan_id' => null]));
        $this->send($this->tap(SubscriptionHandler::serviceCallback($test->id)));
        self::assertNotContains(ServiceTransferHandler::askCallback($test->id), $this->callbacks(0), 'a free test stays its customer\'s');
    }

    public function testTheIdTypedIsConfirmedAndTheServiceHandedOver(): void
    {
        $this->transfersOn();

        $this->send($this->tap(ServiceTransferHandler::askCallback($this->service->id)));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'the question in place of the screen');
        self::assertSame([self::text(BotText::TransferAsk, ['client' => 'ali_1'])], $this->said());
        self::assertSame([SubscriptionHandler::serviceCallback($this->service->id)], $this->callbacks(0), '«بازگشت» to the service');

        $this->send($this->message('۷۰۷۰'));
        self::assertSame([self::text(BotText::TransferConfirm, ['client' => 'ali_1', 'name' => 'Sara Ahmadi (@sara)'])], $this->said());
        $yes = ServiceTransferHandler::PREFIX . $this->service->id . ':' . $this->sara->id;
        self::assertSame([[
            ['text' => self::text(BotText::Cancel), 'callback_data' => SubscriptionHandler::serviceCallback($this->service->id)],
            ['text' => self::text(BotText::TransferYes), 'callback_data' => $yes, 'style' => 'danger'],
        ]], $this->inlineKeyboard(0));
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id, 'nothing handed over yet');

        $this->send($this->tap($yes));
        $given = $this->service->fresh() ?? self::fail('gone');
        self::assertSame([$this->sara->id, false], [$given->user_id, $given->auto_renew]);
        self::assertContains(self::text(BotText::TransferDone, ['client' => 'ali_1', 'name' => 'Sara Ahmadi (@sara)']), $this->said(), 'said in place of the question');
        $edit = (int) array_search('editMessageText', $this->calls(), true);
        self::assertSame([MenuHandler::subscriptionsCallback(1)], $this->callbacks($edit), '«بازگشت» to the list');
        self::assertCount(1, $this->telegram()->sentTo(self::SARA), 'the recipient told');
        self::assertSame([], $this->telegram()->sentTo(self::CHAT), 'the giver is answered in place, no notice beside it');

        $this->send($this->tap($yes));
        self::assertSame([self::text(BotText::ServiceNotFound)], $this->said(), 'a second tap: the service is not theirs any more');
        self::assertSame($this->sara->id, $this->service->fresh()?->user_id);
    }

    public function testARefusedRecipientIsSaidAndAnotherIdAwaited(): void
    {
        $this->transfersOn();
        $this->customer(['telegram_id' => 7171, 'status' => UserStatus::Banned]);
        $this->send($this->tap(ServiceTransferHandler::askCallback($this->service->id)));

        foreach (['sara' => ServiceTransfers::NOT_AN_ID, '9999' => ServiceTransfers::UNKNOWN, (string) self::CHAT => ServiceTransfers::SAME, '7171' => ServiceTransfers::BANNED] as $typed => $reason) {
            $this->send($this->message((string) $typed));
            self::assertSame([self::text(BotText::TransferRecipientRefused, ['reason' => $reason])], $this->said(), (string) $typed);
            self::assertSame([SubscriptionHandler::serviceCallback($this->service->id)], $this->callbacks(0));
        }

        $this->send($this->message((string) self::SARA));
        self::assertSame([self::text(BotText::TransferConfirm, ['client' => 'ali_1', 'name' => 'Sara Ahmadi (@sara)'])], $this->said(), 'still awaited');
    }

    public function testAnotherIdTypedAtTheQuestionAsksAboutThatOneInstead(): void
    {
        $this->transfersOn();
        $reza = $this->customer(['telegram_id' => 7272, 'username' => 'reza', 'first_name' => null]);
        $this->send($this->tap(ServiceTransferHandler::askCallback($this->service->id)));
        $this->send($this->message((string) self::SARA));

        $this->send($this->message('7272'));
        self::assertSame([self::text(BotText::TransferConfirm, ['client' => 'ali_1', 'name' => '@reza'])], $this->said());

        $this->send($this->tap(ServiceTransferHandler::PREFIX . $this->service->id . ':' . $reza->id));
        self::assertSame($reza->id, $this->service->fresh()?->user_id);
        self::assertSame([], $this->telegram()->sentTo(self::SARA), 'Sara was only asked about');
    }

    public function testAQuestionTappedOutOfTurnEndsTheStep(): void
    {
        $this->transfersOn();
        $this->customer(['telegram_id' => 7272, 'username' => 'reza']);
        $this->send($this->tap(ServiceTransferHandler::askCallback($this->service->id)));
        $this->send($this->message((string) self::SARA));
        $this->send($this->message('7272'));

        $this->send($this->tap(ServiceTransferHandler::PREFIX . $this->service->id . ':' . $this->sara->id));

        self::assertSame(self::text(BotText::TransferExpired), $this->popup(), 'the question about Sara is over');
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id);
    }

    public function testCancellingShowsTheServiceAgainAndHandsNothingOver(): void
    {
        $this->transfersOn();
        $this->send($this->tap(ServiceTransferHandler::askCallback($this->service->id)));
        $this->send($this->message((string) self::SARA));

        $this->send($this->tap(SubscriptionHandler::serviceCallback($this->service->id)));
        self::assertStringContainsString('ali_1', $this->said()[0], 'the service\'s screen');

        $this->send($this->tap(ServiceTransferHandler::PREFIX . $this->service->id . ':' . $this->sara->id));
        self::assertSame(self::text(BotText::TransferExpired), $this->popup());
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id);
        $this->send($this->message((string) self::SARA));
        self::assertNotContains(self::text(BotText::TransferConfirm, ['client' => 'ali_1', 'name' => 'Sara Ahmadi (@sara)']), $this->said(), 'no id awaited any more');
    }

    public function testAServiceThatMayNotGoNowSaysWhy(): void
    {
        $this->transfersOn();
        $this->service(OrderService::class)->createRenewal($this->ali, $this->service, $this->plan);

        $this->send($this->tap(ServiceTransferHandler::askCallback($this->service->id)));

        self::assertSame([self::text(BotText::TransferRefused, ['reason' => ServiceTransfers::OPEN_ORDER])], $this->said());
        self::assertSame([SubscriptionHandler::serviceCallback($this->service->id)], $this->callbacks(0));
        $this->send($this->message((string) self::SARA));
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id, 'no id awaited');
    }

    public function testTheShopSwitchingItOffMeanwhileEndsTheStep(): void
    {
        $this->transfersOn();
        $this->send($this->tap(ServiceTransferHandler::askCallback($this->service->id)));
        $this->send($this->message((string) self::SARA));
        $this->transfersOn(false);

        $this->send($this->tap(ServiceTransferHandler::PREFIX . $this->service->id . ':' . $this->sara->id));

        self::assertSame([self::text(BotText::TransferRefused, ['reason' => ServiceTransfers::OFF])], $this->said());
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id);
    }

    public function testTooManyLookupsEndTheStepWithTheWait(): void
    {
        $this->transfersOn();
        $this->send($this->tap(ServiceTransferHandler::askCallback($this->service->id)));
        for ($i = 0; $i < ServiceTransfers::LOOKUPS; $i++) {
            $this->send($this->message('9999'));
        }

        $this->send($this->message((string) self::SARA));

        self::assertStringStartsWith('⚠️ این سرویس الان منتقل نمی‌شود: شناسه‌های زیادی را بررسی کرده‌اید؛ ', $this->said()[0]);
        $this->send($this->message((string) self::SARA));
        self::assertNotContains(self::text(BotText::TransferConfirm, ['client' => 'ali_1', 'name' => 'Sara Ahmadi (@sara)']), $this->said(), 'the step is over');
    }

    public function testTheGiversOldButtonsFindTheServiceGone(): void
    {
        $this->transfersOn();
        $wallet = $this->walletMethod();
        $id = $this->service->id;
        $this->send($this->tap(ServiceTransferHandler::askCallback($id)));
        $this->send($this->message((string) self::SARA));
        $this->send($this->tap(ServiceTransferHandler::PREFIX . $id . ':' . $this->sara->id));

        foreach ([
            'its screen' => SubscriptionHandler::serviceCallback($id),
            'its link changed' => SubscriptionHandler::serviceCallback($id, 'rotate', 'yes'),
            'its renewal' => RenewalHandler::checkoutCallback($id),
            'paying its renewal' => CallbackData::build(RenewalHandler::checkoutCallback($id), $wallet->id),
            'its extra traffic' => ExtrasHandler::checkoutCallback($id, ExtraKind::Traffic, 10),
            'handing it on again' => ServiceTransferHandler::askCallback($id),
        ] as $case => $tap) {
            $this->send($this->tap($tap));
            self::assertSame([self::text(BotText::ServiceNotFound)], $this->said(), $case);
        }
        self::assertSame([0, $this->sara->id], [Order::query()->count(), $this->service->fresh()?->user_id]);
    }

    public function testAnotherCustomersServiceIsNotFound(): void
    {
        $this->transfersOn();
        $theirs = $this->subscription($this->sara, $this->plan, $this->server, 'sara_1');

        $this->send($this->tap(ServiceTransferHandler::askCallback($theirs->id)));

        self::assertSame([self::text(BotText::ServiceNotFound)], $this->said());
        self::assertSame($this->sara->id, $theirs->fresh()?->user_id);
    }
}
