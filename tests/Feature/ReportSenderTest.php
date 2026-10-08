<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\Lease;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Models\ReportTopic;
use App\Modules\Telegram\Reports\GroupProblem;
use App\Modules\Telegram\Reports\ReceiptReview;
use App\Modules\Telegram\Reports\ReportGroup;
use App\Modules\Telegram\Reports\ReportGroupState;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Tasks\SendReportsTask;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Support\FakeTelegram;

/**
 * The queue to the report group: reports go out in order, to their topics, no faster than Telegram takes them in a
 * group; a flood limit, an unreachable Telegram or a group that turned the bot away holds them back without losing
 * one; a topic the group lacks is made by its first report — by one sender at a time, taken over from one that went
 * quiet, with its colour alone when no icon can be had —, one an admin deleted made again and a closed one opened; a
 * receipt is a copy of the customer's picture and its verdict a reply to it; two senders never send the same report,
 * and one whose senders keep going quiet is given up on; old rows are forgotten.
 */
final class ReportSenderTest extends DatabaseTestCase
{
    /** @var array<string, int> */
    private array $threads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->threads = $this->reportGroup();
    }

    public function testAReportGoesToItsTopicAsHtmlAndIsMarkedSent(): void
    {
        $this->reports()->notice(Topic::Errors, '<b>یک</b>');

        self::assertSame(1, $this->sender()->flush());

        self::assertSame(['sendMessage'], $this->telegram()->calls());
        $params = $this->telegram()->params(0);
        self::assertSame((string) self::REPORT_GROUP, $params['chat_id']);
        self::assertSame((string) $this->threads['errors'], $params['message_thread_id']);
        self::assertSame('HTML', $params['parse_mode']);
        self::assertSame('<b>یک</b>', $params['text']);
        self::assertSame(['is_disabled' => true], json_decode($params['link_preview_options'], true));

        $row = ReportMessage::query()->sole();
        self::assertNotNull($row->sent_at);
        self::assertSame(101, $row->message_id, "the fake's first message id");
        self::assertSame(self::REPORT_GROUP, $row->chat_id);
        self::assertSame(0, $this->sender()->flush(), 'sent once');
    }

    public function testNothingIsQueuedWithoutAGroupOrForATopicTheAdminSwitchedOff(): void
    {
        $this->botSettings('reports', ['report_users' => false] + array_fill_keys(array_map(static fn(Topic $topic): string => 'report_' . $topic->value, Topic::cases()), true));
        $this->reports()->newCustomer($this->customer(), null);
        self::assertSame(0, ReportMessage::query()->count(), 'the users topic is switched off');

        $this->reports()->serverUp($this->fakeServer());
        self::assertSame(1, ReportMessage::query()->count(), 'the others are on');

        $this->service(ReportGroup::class)->disconnect();
        self::assertSame(0, ReportMessage::query()->count(), 'what waited for the group is dropped with it');
        $this->reports()->serverUp($this->fakeServer('هلند'));
        self::assertSame(0, ReportMessage::query()->count(), 'no group, no reports');
        self::assertSame(0, $this->sender()->flush());
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAGroupThePanelDisconnectedWhileTheBotWaitedGetsNothingMore(): void
    {
        $this->reports()->notice(Topic::Errors, 'یک');
        // bot:poll looked at the group before its long wait; the panel, another process, disconnects meanwhile.
        self::assertSame(self::REPORT_GROUP, $this->service(ReportGroupState::class)->chatId());
        (new ReportGroupState())->forget();

        self::assertSame(0, $this->sender()->flush());
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAFloodLimitHoldsEveryReportBackUntilItPassesAndLosesNone(): void
    {
        $this->reports()->notice(Topic::Errors, 'یک');
        $this->reports()->notice(Topic::Errors, 'دو');
        $this->telegram()->raw(FakeTelegram::flood(30));

        self::assertSame(0, $this->sender()->flush());
        self::assertSame(['sendMessage'], $this->telegram()->calls(), 'stopped at the first refusal');
        self::assertSame(2, ReportMessage::waiting()->count());
        self::assertSame(0, ReportMessage::query()->min('attempts'), 'held back, not counted against them');
        self::assertNotNull($this->service(ReportGroupState::class)->pausedUntil());

        $this->telegram()->reset();
        self::assertSame(0, $this->sender()->flush(), 'still waiting');
        self::assertSame([], $this->telegram()->calls());

        Carbon::setTestNow(now()->addSeconds(31));
        self::assertSame(2, $this->sender()->flush());
        self::assertSame(['یک', 'دو'], array_column(array_column($this->telegram()->history, 'params'), 'text'), 'in order');
    }

    public function testAMinuteTakesNoMoreThanTelegramAllowsAGroup(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->reports()->notice(Topic::Purchases, "گزارش {$i}");
        }

        self::assertSame(ReportSender::PER_MINUTE, $this->sender()->flush(30));
        self::assertSame(2, ReportMessage::waiting()->count());

        Carbon::setTestNow(now()->addSeconds(61));
        self::assertSame(2, $this->sender()->flush());
        self::assertSame(0, ReportMessage::waiting()->count());
    }

    /**
     * What support must act on — a receipt, a delivery that failed — goes before the rest, whatever came first: a flood
     * of newcomers or of a ticket's messages never keeps a receipt waiting. Each topic keeps its own order.
     */
    public function testReceiptsAndFailedDeliveriesGoFirst(): void
    {
        $this->reports()->notice(Topic::Users, 'کاربر ۱');
        $this->reports()->notice(Topic::Tickets, 'تیکت');
        $this->reports()->notice(Topic::Errors, 'خطا');
        $this->reports()->notice(Topic::Users, 'کاربر ۲');
        $this->reports()->notice(Topic::Receipts, 'رسید');

        // The minute has room for three more.
        for ($i = 0; $i < ReportSender::PER_MINUTE - 3; $i++) {
            $this->reports()->notice(Topic::Purchases, "خرید {$i}");
        }
        ReportMessage::query()->where('topic', Topic::Purchases->value)->update(['sent_at' => now(), 'chat_id' => self::REPORT_GROUP, 'message_id' => 900]);

        self::assertSame(3, $this->sender()->flush());
        self::assertSame(['خطا', 'رسید', 'کاربر ۱'], array_column(array_column($this->telegram()->history, 'params'), 'text'), 'the urgent first, then the oldest of the rest');
    }

    /** A send that takes long — every call of it waiting out Telegram's timeout — is not taken over meanwhile, nor sent twice. */
    public function testASendThatTakesLongIsNotTakenOverMeanwhile(): void
    {
        $this->config(['app.http_timeout' => 30.0]);
        $this->reports()->notice(Topic::Errors, 'یک');
        $sender = $this->sender();
        $inner = null;
        $this->telegram()->on('sendMessage', static function () use ($sender, &$inner): array {
            if ($inner === null) {
                $inner = -1;
                // Ten minutes into this send, another sender (a cron run, a webhook request) looks at the queue.
                Carbon::setTestNow(now()->addMinutes(10));
                $inner = $sender->flush();
            }

            return ['message_id' => 900];
        });

        self::assertSame(1, $sender->flush());

        self::assertSame(0, $inner, 'the row was still held');
        self::assertSame(['sendMessage'], $this->telegram()->calls(), 'sent once');
        self::assertSame(900, ReportMessage::query()->sole()->message_id);
    }

    /** A sender whose hold was taken over while its send went on leaves the row to the one that holds it. */
    public function testASenderThatLostItsHoldDoesNotMarkTheRowSent(): void
    {
        $this->reports()->notice(Topic::Errors, 'یک');
        $logs = $this->logs();
        $this->telegram()->on('sendMessage', static function (): array {
            // Its hold ran out mid-send and another sender took the row.
            ReportMessage::query()->update([Lease::TOKEN => 'another-sender', Lease::UNTIL => now()->addMinutes(5)]);

            return ['message_id' => 900];
        });

        $this->sender()->flush();

        $row = ReportMessage::query()->sole();
        self::assertSame([null, null, 'another-sender'], [$row->sent_at, $row->message_id, $row->lease_token], 'the other sender marks it as it sends it');
        self::assertTrue($logs->hasWarningThatContains('another sender holds it now'));
    }

    public function testATopicAnAdminDeletedIsMadeAgainAndTheReportGoesThere(): void
    {
        $this->reports()->notice(Topic::Receipts, 'رسید');
        $sends = 0;
        $this->telegram()->on('sendMessage', static function () use (&$sends): mixed {
            return ++$sends === 1 ? FakeTelegram::error(400, 'Bad Request: message thread not found') : ['message_id' => 900];
        });
        $this->telegram()->on('createForumTopic', static fn(array $params): array => ['message_thread_id' => 777, 'name' => $params['name'], 'icon_color' => (int) $params['icon_color']]);

        self::assertSame(1, $this->sender()->flush());

        self::assertSame(['sendMessage', 'getForumTopicIconStickers', 'createForumTopic', 'sendMessage'], $this->telegram()->calls());
        self::assertSame((string) $this->threads['receipts'], $this->telegram()->params(0)['message_thread_id']);
        self::assertSame('رسیدها', $this->telegram()->params(2)['name']);
        self::assertSame('777', $this->telegram()->params(3)['message_thread_id']);
        self::assertSame(777, ReportTopic::query()->where('topic', Topic::Receipts->value)->value('thread_id'));
        self::assertSame(900, ReportMessage::query()->sole()->message_id);
    }

    public function testATopicAnotherSenderIsMakingIsWaitedForAndTakenOverOnceItsMakerGoesQuiet(): void
    {
        // Another sender holds the receipts topic's row: it is making the topic right now.
        ReportTopic::query()->where('topic', Topic::Receipts->value)->update(['thread_id' => null, Lease::TOKEN => 'another-sender', Lease::UNTIL => now()->addSeconds(30)]);
        $this->reports()->notice(Topic::Receipts, 'رسید');
        $this->reports()->notice(Topic::Errors, 'خطا');

        self::assertSame(0, $this->sender()->flush());
        self::assertSame([], $this->telegram()->calls(), 'not made twice — and what comes after it waits, in order');

        Carbon::setTestNow(now()->addSeconds(31));
        $this->telegram()->on('createForumTopic', static fn(array $params): array => ['message_thread_id' => 777, 'name' => $params['name'], 'icon_color' => (int) $params['icon_color']]);
        self::assertSame(2, $this->sender()->flush(), 'its maker went quiet: taken over');
        self::assertSame(['getForumTopicIconStickers', 'createForumTopic', 'sendMessage', 'sendMessage'], $this->telegram()->calls());
        self::assertSame(['777', (string) $this->threads['errors']], [$this->telegram()->params(2)['message_thread_id'], $this->telegram()->params(3)['message_thread_id']]);
        self::assertSame([777, null], [ReportTopic::query()->where('topic', Topic::Receipts->value)->value('thread_id'), ReportTopic::query()->where('topic', Topic::Receipts->value)->value(Lease::TOKEN)]);
    }

    /** Making a topic that takes long — every call of it as slow as Telegram's timeout lets it be — is not taken over meanwhile. */
    public function testATopicWhoseMakingTakesLongIsNotTakenOverMeanwhile(): void
    {
        $this->config(['app.http_timeout' => 30.0]);
        ReportTopic::query()->where('topic', Topic::Receipts->value)->delete();
        $this->reports()->notice(Topic::Receipts, 'یک');
        $this->reports()->notice(Topic::Receipts, 'دو');
        $sender = $this->sender();
        $inner = null;
        $this->telegram()->on('createForumTopic', static function (array $params) use ($sender, &$inner): array {
            if ($inner === null) {
                $inner = -1;
                // Two minutes into making it (Telegram's icons asked, its first try refused), another sender looks at the queue.
                Carbon::setTestNow(now()->addMinutes(2));
                $inner = $sender->flush();
            }

            return ['message_thread_id' => 777, 'name' => $params['name'], 'icon_color' => (int) $params['icon_color']];
        });

        self::assertSame(2, $sender->flush());

        self::assertSame(0, $inner, 'the topic was still being made: its other report waited');
        self::assertSame(['getForumTopicIconStickers', 'createForumTopic', 'sendMessage', 'sendMessage'], $this->telegram()->calls(), 'made once');
        self::assertSame(777, ReportTopic::query()->where('topic', Topic::Receipts->value)->value('thread_id'));
    }

    public function testATopicMadeTwiceInTheSameMomentKeepsTheOneMadeFirst(): void
    {
        ReportTopic::query()->where('topic', Topic::Receipts->value)->delete();
        $this->reports()->notice(Topic::Receipts, 'رسید');
        $logs = $this->logs();
        $this->telegram()->on('createForumTopic', static function (array $params): array {
            // This sender's hold ran out mid-call: another took the row over and made the topic meanwhile.
            ReportTopic::query()->where('topic', Topic::Receipts->value)->update(['thread_id' => 888] + Lease::FREE);

            return ['message_thread_id' => 777, 'name' => $params['name'], 'icon_color' => (int) $params['icon_color']];
        });

        self::assertSame(1, $this->sender()->flush());

        self::assertSame(888, ReportTopic::query()->where('topic', Topic::Receipts->value)->value('thread_id'), 'the first one made is kept');
        self::assertSame('888', $this->telegram()->params(2)['message_thread_id'], 'and the report goes there');
        self::assertTrue($logs->hasWarningThatContains('made twice'), 'the other topic is left for the admin to delete');
    }

    public function testATopicGetsItsColourAloneWhenTelegramHasNoIconForItOrRefusesOne(): void
    {
        ReportTopic::query()->whereIn('topic', [Topic::Receipts->value, Topic::Users->value])->delete();
        $this->reports()->notice(Topic::Receipts, 'رسید');
        $this->reports()->notice(Topic::Users, 'کاربر');
        $asked = 0;
        $this->telegram()->on('getForumTopicIconStickers', static function () use (&$asked): mixed {
            return ++$asked === 1 ? FakeTelegram::error(502, 'Bad Gateway') : [['type' => 'custom_emoji', 'emoji' => '👤', 'custom_emoji_id' => 'icon-user']];
        });
        $thread = 700;
        $this->telegram()->on('createForumTopic', static function (array $params) use (&$thread): mixed {
            return isset($params['icon_custom_emoji_id']) ? FakeTelegram::error(400, 'Bad Request: ICON_EMOJI_INVALID') : ['message_thread_id' => ++$thread, 'name' => $params['name'], 'icon_color' => (int) $params['icon_color']];
        });

        self::assertSame(2, $this->sender()->flush());

        $made = array_values(array_filter($this->telegram()->history, static fn(array $call): bool => $call['method'] === 'createForumTopic'));
        self::assertSame(
            [[Topic::Receipts->title(), null], [Topic::Users->title(), 'icon-user'], [Topic::Users->title(), null]],
            array_map(static fn(array $call): array => [$call['params']['name'], $call['params']['icon_custom_emoji_id'] ?? null], $made),
            'no icons to be had: the colour alone; an icon refused: made again without it',
        );
        self::assertSame([(string) Topic::Receipts->color(), (string) Topic::Users->color()], [$made[0]['params']['icon_color'], $made[2]['params']['icon_color']]);
        self::assertSame([701, 702], ReportTopic::query()->whereIn('topic', [Topic::Receipts->value, Topic::Users->value])->oldest('id')->pluck('thread_id')->all());
    }

    public function testATopicAnAdminClosedIsOpenedAgain(): void
    {
        $this->reports()->notice(Topic::Users, 'کاربر');
        $this->telegram()->fail(400, 'Bad Request: TOPIC_CLOSED');

        self::assertSame(1, $this->sender()->flush());

        self::assertSame(['sendMessage', 'reopenForumTopic', 'sendMessage'], $this->telegram()->calls());
        self::assertSame(['chat_id' => (string) self::REPORT_GROUP, 'message_thread_id' => (string) $this->threads['users']], $this->telegram()->params(1));
    }

    public function testABotTurnedAwayByTheGroupIsTheScreensProblemAndNothingIsLost(): void
    {
        $this->reports()->notice(Topic::Errors, 'یک');
        $this->reports()->notice(Topic::Errors, 'دو');
        $this->telegram()->fail(403, 'Forbidden: bot was kicked from the supergroup chat');

        self::assertSame(0, $this->sender()->flush());

        $state = $this->service(ReportGroupState::class);
        self::assertSame(GroupProblem::Removed, $state->problem());
        self::assertNotNull($state->pausedUntil());
        self::assertSame(['sendMessage'], $this->telegram()->calls(), 'the second is not tried against a group that refuses everything');
        self::assertSame(2, ReportMessage::waiting()->count());
    }

    public function testAnUnreachableTelegramHoldsTheReportsBack(): void
    {
        $this->reports()->notice(Topic::Errors, 'یک');
        $this->telegram()->raw(new Response(502, [], '<html>Bad Gateway</html>'));

        self::assertSame(0, $this->sender()->flush());

        self::assertSame(1, ReportMessage::waiting()->count());
        self::assertNotNull($this->service(ReportGroupState::class)->pausedUntil());
        self::assertNull($this->service(ReportGroupState::class)->problem(), 'nothing is wrong with the group');
    }

    public function testAReportTelegramRefusesForGoodIsDroppedAndTheNextOneGoes(): void
    {
        $this->reports()->notice(Topic::Errors, 'یک');
        $this->reports()->notice(Topic::Errors, 'دو');
        $this->telegram()->fail(400, 'Bad Request: message is too long');

        self::assertSame(1, $this->sender()->flush());

        $rows = ReportMessage::query()->oldest('id')->get();
        self::assertNotNull($rows[0]->failed_at);
        self::assertSame('Bad Request: message is too long', $rows[0]->error);
        self::assertNotNull($rows[1]->sent_at);
    }

    public function testBrokenMarkupDoesNotCostTheReport(): void
    {
        $this->reports()->notice(Topic::Errors, '<b>یک &amp; دو');
        $this->telegram()->fail(400, "Bad Request: can't parse entities: can't find end of the entity starting at byte offset 0");

        self::assertSame(1, $this->sender()->flush());

        self::assertSame(['sendMessage', 'sendMessage'], $this->telegram()->calls());
        $plain = $this->telegram()->params(1);
        self::assertSame('یک & دو', $plain['text']);
        self::assertArrayNotHasKey('parse_mode', $plain);
        self::assertSame((string) $this->threads['errors'], $plain['message_thread_id']);
    }

    public function testAReceiptIsACopyOfThePictureAndItsVerdictAReplyToIt(): void
    {
        $customer = $this->customer(['username' => 'ali']);
        $payment = $this->receipt($this->cardPayment($this->topUpOrder($customer, '250000'), $this->cardMethod(autoApproveAfter: 30)), 'از کارت پدرم');

        self::assertSame(1, $this->sender()->flush());
        self::assertSame(['copyMessage'], $this->telegram()->calls());
        $copy = $this->telegram()->params(0);
        self::assertSame((string) self::REPORT_GROUP, $copy['chat_id']);
        self::assertSame((string) self::TELEGRAM_ID, $copy['from_chat_id']);
        self::assertSame((string) self::RECEIPT_MESSAGE, $copy['message_id']);
        self::assertSame((string) $this->threads['receipts'], $copy['message_thread_id']);
        self::assertSame('HTML', $copy['parse_mode']);
        self::assertStringContainsString("🧾 <b>رسید جدید</b> · پرداخت #{$payment->id}", $copy['caption']);
        self::assertStringContainsString('<a href="tg://user?id=5151">Ali</a> · @ali · <code>5151</code>', $copy['caption']);
        self::assertStringContainsString('📝 توضیح مشتری: از کارت پدرم', $copy['caption']);
        self::assertStringContainsString('۳۰ دقیقه', $copy['caption'], 'the window before it is accepted by itself');
        self::assertSame(ReceiptReview::buttons($payment->id), FakeTelegram::markupOf($copy)['inline_keyboard'] ?? null, 'the bot admins\' buttons under it');

        $this->telegram()->reset();
        $this->service(PaymentService::class)->approve($payment, 'admin');
        $this->sender()->flush();

        self::assertSame(['sendMessage', 'editMessageReplyMarkup', 'sendMessage'], $this->telegram()->calls(), 'the verdict — the receipt losing its buttons with it — then the charged wallet');
        $verdict = $this->telegram()->params(0);
        self::assertSame("✅ رسید پرداخت #{$payment->id} تایید شد.", $verdict['text']);
        self::assertSame((string) $this->threads['receipts'], $verdict['message_thread_id']);
        self::assertSame(['message_id' => 101, 'allow_sending_without_reply' => true], json_decode($verdict['reply_parameters'], true), 'a reply to the copy');
        self::assertArrayNotHasKey('reply_markup', $verdict);
        self::assertSame(['chat_id' => (string) self::REPORT_GROUP, 'message_id' => '101', 'reply_markup' => '{"inline_keyboard":[]}'], $this->telegram()->params(1), 'decided here, on the screen or by the timer: nothing left to press');
        self::assertSame((string) $this->threads['wallet'], $this->telegram()->params(2)['message_thread_id']);
    }

    public function testAReceiptWhosePictureCannotBeCopiedGoesAsText(): void
    {
        $this->receipt($this->cardPayment($this->topUpOrder($this->customer(), '250000'), $this->cardMethod()));
        $this->telegram()->fail(400, 'Bad Request: message to copy not found');

        self::assertSame(1, $this->sender()->flush());

        self::assertSame(['copyMessage', 'sendMessage'], $this->telegram()->calls());
        $text = $this->telegram()->params(1);
        self::assertStringContainsString('🧾 <b>رسید جدید</b>', $text['text']);
        self::assertStringContainsString('تصویر کپی نشد', $text['text']);
        self::assertSame((string) $this->threads['receipts'], $text['message_thread_id']);
        self::assertCount(2, FakeTelegram::markupOf($text)['inline_keyboard'][0] ?? [], 'the buttons come with the words');
    }

    public function testAReportHeldByAnotherSenderIsNotSentTwice(): void
    {
        $this->reports()->notice(Topic::Errors, 'یک');
        ReportMessage::query()->update(['lease_token' => 'another-sender', 'leased_until' => now()->addSeconds(30)]);

        self::assertSame(0, $this->sender()->flush());
        self::assertSame([], $this->telegram()->calls());

        Carbon::setTestNow(now()->addSeconds(31));
        self::assertSame(1, $this->sender()->flush(), 'a sender that went quiet loses its hold');
        $row = ReportMessage::query()->sole();
        self::assertSame([1, null], [$row->attempts, $row->lease_token], 'its try counted, the hold let go');
    }

    public function testAReportWhoseSendersKeepGoingQuietIsGivenUpOnTheThirdTime(): void
    {
        $this->reports()->notice(Topic::Errors, 'یک');
        // Two senders went quiet holding it already, and a third has left its hold behind.
        ReportMessage::query()->update(['attempts' => 2, Lease::TOKEN => 'quiet-sender', Lease::UNTIL => now()->subSecond()]);

        self::assertSame(0, $this->sender()->flush());

        $row = ReportMessage::query()->sole();
        self::assertSame(3, $row->attempts);
        self::assertNotNull($row->failed_at, 'given up on');
        self::assertSame([], $this->telegram()->calls(), 'not tried a fourth time');
    }

    public function testTheTaskSendsAndForgetsOldRows(): void
    {
        $this->reports()->notice(Topic::Errors, 'فرستاده');
        $this->reports()->notice(Topic::Errors, 'ردشده');
        $this->telegram()->on('sendMessage', static fn(array $params): mixed => $params['text'] === 'ردشده' ? FakeTelegram::error(400, 'Bad Request: message is too long') : ['message_id' => 900]);
        $this->service(SendReportsTask::class)->run();
        self::assertSame(0, ReportMessage::waiting()->count(), 'one sent, one dropped');

        Carbon::setTestNow(now()->addDays(8));
        $this->reports()->notice(Topic::Errors, 'تازه');
        $this->service(ReportGroupState::class)->pause(600);
        $this->service(SendReportsTask::class)->run();
        self::assertSame(['تازه'], ReportMessage::query()->pluck('text')->all(), 'a week on, what was sent or dropped is forgotten');

        Carbon::setTestNow(now()->addHours(25));
        $this->sender()->prune();
        $stale = ReportMessage::query()->sole();
        self::assertSame([true, null], [$stale->failed_at !== null, $stale->sent_at], 'a day in the queue is too long: given up on, never sent');
        self::assertSame(0, ReportMessage::waiting()->count());
    }

    private function reports(): ShopReports
    {
        return $this->service(ShopReports::class);
    }

    private function sender(): ReportSender
    {
        return $this->service(ReportSender::class);
    }
}
