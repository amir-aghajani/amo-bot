<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Core\Scheduling\Budget;
use App\Modules\Bots\CurrentBot;
use App\Modules\Notifications\Services\CustomerChats;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Pacer;
use App\Modules\Telegram\Broadcasts\Audience;
use App\Modules\Telegram\Broadcasts\BroadcastControl;
use App\Modules\Telegram\Broadcasts\BroadcastKind;
use App\Modules\Telegram\Broadcasts\BroadcastMode;
use App\Modules\Telegram\Broadcasts\BroadcastService;
use App\Modules\Telegram\Broadcasts\BroadcastStatus;
use App\Modules\Telegram\Handlers\BroadcastHandler;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\Broadcast;
use App\Modules\Telegram\Models\BroadcastPin;
use App\Modules\Telegram\Tasks\SendBroadcastsTask;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerGroups;
use App\Support\Persian;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Tests\BotTestCase;
use Tests\Fakes\RecordingSleeper;
use Tests\Support\FakeTelegram;

/**
 * /broadcast: silent for customers; for a bot admin, the message, then a draft card — copy or forward, the audience,
 * pin, link buttons — then the run, whose live progress message carries its controls: pause, resume, cancel, and once
 * a pinned one is over, «لغو پین» (a pin Telegram no longer has counts as off). One worker at a time holds a run, each
 * customer claimed before their message goes, so a pause, a cancel or a takeover stops it at the next customer and
 * nobody gets it twice; the sends are paced; known blockers are counted at no call, new ones remembered, a refusal
 * counted and the run goes on; a flood limit holds the customer for the next batch. The bot sends the first few itself
 * (again after a resume), the scheduler the rest within its time, and tells the admin once a message went to everyone.
 * An audience that is gone reaches nobody, and an agent's bot names only the servers its own customers are on.
 */
final class BotBroadcastTest extends BotTestCase
{
    private User $boss;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        // Everyone (four): the admin, Ali, Sara and Gone, who blocked the bot; Banned is in no audience.
        $this->boss = $this->admin();
        $this->ali = $this->customer(['telegram_id' => 1001, 'first_name' => 'Ali']);
        $this->customer(['telegram_id' => 1002, 'first_name' => 'Sara']);
        $this->customer(['telegram_id' => 1003, 'first_name' => 'Banned', 'status' => UserStatus::Banned]);
        $this->customer(['telegram_id' => 1004, 'first_name' => 'Gone', 'bot_blocked' => true]);
    }

    public function testACustomerGetsNoAnswerAtAll(): void
    {
        $this->send($this->message('/broadcast', chat: 1001));

        self::assertSame([], $this->calls(), 'not even an error: the command does not exist for them');
    }

    public function testTheDraftCardThenEveryoneGetsACopyWithLiveProgress(): void
    {
        $this->send($this->message('/broadcast'));
        self::assertSame([Messages::BROADCAST_ASK], $this->said());
        self::assertSame([self::step('drop')], $this->callbacks(0));

        // The message to send: any message, here a photo with a caption (message id 70).
        $this->send($this->message(null, ['message_id' => 70, 'photo' => [['file_id' => 'p1']], 'caption' => 'تخفیف پاییزی']));
        self::assertSame([self::card(self::content('photo', 'تخفیف پاییزی'))], $this->said(), 'everyone active, the known blocker counted, the banned one not');
        self::assertSame([self::step('aud'), self::step('mode'), self::step('btn'), self::step('pin'), self::step('drop'), self::step('send')], $this->callbacks(0));

        // Sent: the card becomes the progress message; Sara has blocked the bot since.
        $this->telegram()->on('copyMessage', static fn(array $params): mixed => $params['chat_id'] === '1002' ? FakeTelegram::error(403, 'Forbidden: bot was blocked by the user') : ['message_id' => 500]);
        $this->send($this->tap(self::step('send')));

        $broadcast = Broadcast::query()->sole();
        self::assertSame('answerCallbackQuery', $this->calls()[0], 'the tap answered before the first batch goes');
        $copies = $this->paramsOf('copyMessage');
        self::assertSame([(string) self::CHAT, '1001', '1002'], array_column($copies, 'chat_id'), 'the known blocker (1004) is skipped without a call');
        self::assertSame([(string) self::CHAT, '70'], [$copies[0]['from_chat_id'], $copies[0]['message_id']], 'copied from the admin chat');
        self::assertSame(['77', '77'], array_column($this->paramsOf('editMessageText'), 'message_id'), 'the card, now the progress');
        self::assertSame([
            self::progress($broadcast, BroadcastStatus::Sending, sent: 0, blocked: 0),
            self::progress($broadcast, BroadcastStatus::Done, sent: 2, blocked: 2),
        ], $this->said(), 'Sara who just blocked, and Gone who had');
        self::assertSame('{"inline_keyboard":[]}', $this->lastEdit()['reply_markup'], 'nothing left to control');

        self::assertSame(BroadcastStatus::Done, $broadcast->status);
        self::assertSame([4, 2, 2, 0], [$broadcast->total, $broadcast->sent, $broadcast->blocked, $broadcast->failed]);
        self::assertSame([BroadcastMode::Copy, Audience::ALL, 'photo', 'تخفیف پاییزی', 77], [$broadcast->mode, $broadcast->audience, $broadcast->content, $broadcast->excerpt, $broadcast->progress_message_id]);
        self::assertTrue(User::query()->where('telegram_id', 1002)->firstOrFail()->bot_blocked, 'Sara is remembered as a blocker');

        // «ارسال» pressed again on what is now the progress message: claimed once, nothing starts twice.
        $this->send($this->tap(self::step('send')));
        self::assertSame(1, Broadcast::query()->count());
        self::assertSame([], $this->paramsOf('copyMessage'));
    }

    public function testDroppingTheDraftLeavesNothingToSend(): void
    {
        $this->send($this->message('/broadcast'));
        $this->send($this->message('سلام به همه', ['message_id' => 72]));

        $this->send($this->tap(self::step('drop')));
        self::assertSame([Messages::BROADCAST_CANCELLED], $this->said(), 'the card says so in its place');

        $this->send($this->tap(self::step('send')));
        self::assertSame([Messages::BROADCAST_EXPIRED], $this->said(), 'a button of the dropped card');
        self::assertSame(0, Broadcast::query()->count());
    }

    public function testEachBroadcastCommandStartsAfresh(): void
    {
        $this->send($this->message('/broadcast'));
        $this->send($this->message('اولی', ['message_id' => 72]));
        $this->send($this->tap(self::step('pin')));

        $this->send($this->message('/broadcast'));
        self::assertSame([Messages::BROADCAST_ASK], $this->said());
        $this->send($this->message('دومی', ['message_id' => 73]));
        self::assertSame([self::card(self::content('text', 'دومی'))], $this->said(), 'a new draft: unpinned, as every draft starts');

        $this->send($this->tap(self::step('send')));
        self::assertSame(['73'], array_unique(array_column($this->paramsOf('copyMessage'), 'message_id')));
        self::assertSame([], $this->paramsOf('pinChatMessage'));
    }

    public function testAForwardedChannelPostGoesToTheBuyersPinnedAndThePinsComeOffAgain(): void
    {
        $server = $this->fakeServer();
        $this->purchaseOrder($this->ali, $this->plan([], $server), $server, ['status' => OrderStatus::Fulfilled]);

        $this->send($this->message('/broadcast'));
        // A channel post forwarded to the bot goes on as a forward unless the admin says otherwise.
        $this->send($this->message('خبر تازه', ['message_id' => 71, 'forward_origin' => ['type' => 'channel', 'chat' => ['id' => -100123, 'type' => 'channel', 'title' => 'News'], 'message_id' => 9, 'date' => 1]]));
        $forward = ['mode' => Messages::BROADCAST_MODE_FORWARD, 'buttons' => Messages::BROADCAST_FORWARD_NO_BUTTONS];
        self::assertSame([self::card(self::content('text', 'خبر تازه'), $forward)], $this->said());
        self::assertNotContains(self::step('btn'), $this->callbacks(0), 'a forward takes no buttons of its own');

        $this->send($this->tap(self::step('aud')));
        self::assertSame([Messages::BROADCAST_AUDIENCE_ASK], $this->said());
        $labels = array_column(array_merge(...$this->inlineKeyboard(0)), 'text', 'callback_data');
        self::assertSame(self::option(Audience::LABELS[Audience::ALL], 4), $labels[self::step('aud', Audience::ALL)]);
        self::assertSame(self::option(Audience::LABELS[Audience::BUYERS], 1), $labels[self::step('aud', Audience::BUYERS)]);
        self::assertSame(self::option(Audience::LABELS[Audience::NON_BUYERS], 3), $labels[self::step('aud', Audience::NON_BUYERS)]);
        self::assertSame(self::option(Audience::LABELS[Audience::INACTIVE], 4), $labels[self::step('aud', Audience::INACTIVE)]);
        self::assertSame(self::option(Audience::LABELS[Audience::AGENTS], 0), $labels[self::step('aud', Audience::AGENTS)]);
        self::assertArrayHasKey(self::step('aud', Audience::GROUP), $labels);
        self::assertArrayHasKey(self::step('aud', Audience::SERVER), $labels);

        $this->send($this->tap(self::step('aud', Audience::BUYERS)));
        $this->send($this->tap(self::step('pin')));
        $buyersPinned = ['audience' => Audience::LABELS[Audience::BUYERS], 'count' => Persian::number(1), 'blocked' => '', 'pin' => Messages::BROADCAST_ON];
        self::assertSame([self::card(self::content('text', 'خبر تازه'), $buyersPinned + $forward)], $this->said());

        $this->send($this->tap(self::step('send')));
        $pin = BroadcastPin::query()->sole();
        self::assertSame([['chat_id' => '1001', 'from_chat_id' => (string) self::CHAT, 'message_id' => '71']], $this->paramsOf('forwardMessage'), 'forwarded as it is: no buttons of its own');
        self::assertSame([['chat_id' => '1001', 'message_id' => (string) $pin->message_id, 'disable_notification' => 'true']], $this->paramsOf('pinChatMessage'), 'pinned quietly, and where kept');
        self::assertSame($this->ali->id, $pin->user_id);
        $broadcast = Broadcast::query()->sole();
        self::assertSame([BroadcastMode::Forward, Audience::BUYERS, true, 1], [$broadcast->mode, $broadcast->audience, $broadcast->pin, $broadcast->sent]);
        $unpin = BroadcastHandler::controlCallback(BroadcastHandler::UNPIN, $broadcast->id);
        self::assertStringContainsString($unpin, $this->lastEdit()['reply_markup'], 'over and pinned: its pins can come off');

        $this->send($this->tap($unpin));
        self::assertSame(Messages::BROADCAST_UNPINNING, $this->popup());
        self::assertSame([['chat_id' => '1001', 'message_id' => (string) $pin->message_id]], $this->paramsOf('unpinChatMessage'));
        self::assertSame(0, BroadcastPin::query()->count());
        $run = Broadcast::query()->where('kind', BroadcastKind::Unpin->value)->sole();
        self::assertSame([$broadcast->id, 1, 1, BroadcastStatus::Done], [$run->source_id, $run->total, $run->sent, $run->status]);
        $said = $this->said();
        self::assertSame([Messages::BROADCAST_UNPINNING, self::unpinProgress($run, sent: 1, failed: 0)], [$said[0], end($said)], 'a progress message of its own, under everything');
        self::assertStringNotContainsString($unpin, $this->editsOf(77)[0]['reply_markup'], 'the button goes from the first progress message');

        $this->send($this->tap($unpin));
        self::assertSame(Messages::BROADCAST_UNCHANGED, $this->popup(), 'no pins left to take off');
        self::assertSame(1, Broadcast::query()->where('kind', BroadcastKind::Unpin->value)->count());
    }

    public function testLinkButtonsAreTypedRowByRowAndGoUnderACopy(): void
    {
        $this->send($this->message('/broadcast'));
        $this->telegram()->reply(['message_id' => 900]);
        $this->send($this->message('سلام به همه', ['message_id' => 72]));

        $this->send($this->tap(self::step('btn')));
        self::assertSame([Messages::BROADCAST_BUTTONS_ASK], $this->said());

        $this->send($this->message(null, ['sticker' => ['file_id' => 's1']]));
        self::assertSame([Messages::BROADCAST_BUTTONS_ASK], $this->said(), 'no words: asked again');

        $this->send($this->message('کانال بدون لینک'));
        self::assertSame([Messages::fill(Messages::BROADCAST_BUTTONS_INVALID, ['line' => 'کانال بدون لینک', 'max' => KeyboardLayouts::LABEL_MAX])], $this->said(), 'the line that is not right is said; the admin types them again');

        $this->send($this->message("کانال - https://t.me/news | سایت – https://example.com\nپشتیبانی — tg://resolve?domain=support"));
        self::assertSame([['chat_id' => (string) self::CHAT, 'message_id' => '900']], $this->paramsOf('deleteMessage'), 'the old card goes…');
        self::assertSame([self::card(self::content('text', 'سلام به همه'), ['buttons' => Messages::fill(Messages::BROADCAST_BUTTON_COUNT, ['count' => Persian::number(3)])])], $this->said(), '…and a new one comes under the lines');

        $this->send($this->tap(self::step('send')));
        $copies = $this->paramsOf('copyMessage');
        self::assertCount(3, $copies);
        self::assertSame(
            [
                [['text' => 'سایت', 'url' => 'https://example.com'], ['text' => 'کانال', 'url' => 'https://t.me/news']],
                [['text' => 'پشتیبانی', 'url' => 'tg://resolve?domain=support']],
            ],
            FakeTelegram::markupOf($copies[0])['inline_keyboard'] ?? [],
            'rows as typed, each laid out for a right-to-left reader',
        );
    }

    public function testTheButtonsCanBeTakenOffAgain(): void
    {
        $this->send($this->message('/broadcast'));
        $this->send($this->message('سلام به همه', ['message_id' => 72]));
        $this->send($this->tap(self::step('btn')));
        $this->send($this->message('کانال - https://t.me/news'));

        $this->send($this->tap(self::step('btn')));
        self::assertSame([self::step('btn', 'clear'), self::step('card')], $this->callbacks(0), 'with buttons there: a way to take them off, and back');

        $this->send($this->tap(self::step('btn', 'clear')));
        self::assertSame([self::card(self::content('text', 'سلام به همه'))], $this->said());

        $this->send($this->tap(self::step('send')));
        self::assertSame([], array_filter(array_column($this->paramsOf('copyMessage'), 'reply_markup')), 'copies without buttons');
    }

    public function testAForwardTakesNoButtonsEvenOnesTypedForACopy(): void
    {
        $this->send($this->message('/broadcast'));
        $this->send($this->message('سلام به همه', ['message_id' => 72]));
        $this->send($this->tap(self::step('btn')));
        $this->send($this->message('کانال - https://t.me/news'));

        $this->send($this->tap(self::step('mode')));
        self::assertSame([self::card(self::content('text', 'سلام به همه'), ['mode' => Messages::BROADCAST_MODE_FORWARD, 'buttons' => Messages::BROADCAST_FORWARD_NO_BUTTONS])], $this->said());

        $this->send($this->tap(self::step('send')));
        self::assertSame([], array_filter(array_column($this->paramsOf('forwardMessage'), 'reply_markup')));
        self::assertCount(3, $this->paramsOf('forwardMessage'));
        self::assertNull(Broadcast::query()->sole()->buttons, 'the run keeps none');
    }

    public function testThePickedGroupAndServerAndAnAudienceWithNobodyInIt(): void
    {
        $group = $this->customerGroup('VIP', [$this->ali]);
        $this->send($this->message('/broadcast'));
        $this->send($this->message('ویژه گروه'));

        $this->send($this->tap(self::step('aud', Audience::GROUP)));
        self::assertSame([Messages::BROADCAST_GROUP_ASK], $this->said());
        self::assertSame([self::option('VIP', 1), self::text(BotText::Back)], array_column(array_merge(...$this->inlineKeyboard(0)), 'text'));
        self::assertSame([self::step('aud', Audience::GROUP, $group->id), self::step('aud')], $this->callbacks(0));
        $this->send($this->tap(self::step('aud', Audience::GROUP, $group->id)));
        self::assertSame([self::card(self::content('text', 'ویژه گروه'), ['audience' => 'گروه «VIP»', 'count' => Persian::number(1), 'blocked' => ''])], $this->said());

        $this->send($this->tap(self::step('aud', Audience::SERVER)));
        self::assertSame([Messages::BROADCAST_NO_SERVERS], $this->said(), 'no customer has a service running anywhere');

        $this->send($this->tap(self::step('aud', Audience::AGENTS)));
        self::assertSame([self::card(self::content('text', 'ویژه گروه'), ['audience' => Audience::LABELS[Audience::AGENTS], 'count' => Persian::number(0), 'blocked' => ''])], $this->said());
        self::assertNotContains(self::step('send'), $this->callbacks(0), 'nobody to send to: no «ارسال»');

        // A «ارسال» pressed on an older card all the same.
        $this->send($this->tap(self::step('send')));
        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertSame('true', $this->params(0)['show_alert']);
        self::assertSame(Messages::BROADCAST_EMPTY_AUDIENCE, $this->popup());
        self::assertSame(0, Broadcast::query()->count(), 'nothing started');

        $this->send($this->tap(self::step('aud')));
        self::assertSame([Messages::BROADCAST_AUDIENCE_ASK], $this->said(), 'the draft waits for another audience');
    }

    public function testAGroupDeletedBeforeTheSendOrDuringTheRunReachesNobody(): void
    {
        $group = $this->customerGroup('VIP', [$this->ali]);
        $this->send($this->message('/broadcast'));
        $this->send($this->message('ویژه گروه', ['message_id' => 73]));
        $this->send($this->tap(self::step('aud', Audience::GROUP, $group->id)));

        $this->service(CustomerGroups::class)->delete($group);
        $this->send($this->tap(self::step('send')));
        self::assertSame(Messages::BROADCAST_AUDIENCE_GONE, $this->popup());
        self::assertSame(0, Broadcast::query()->count(), 'not everyone instead: nobody');

        $this->send($this->tap(self::step('card')));
        self::assertSame([self::card(self::content('text', 'ویژه گروه'), ['audience' => 'گروهی که حذف شده', 'count' => Persian::number(0), 'blocked' => ''])], $this->said(), 'the draft waits for another audience');
        self::assertNotContains(self::step('send'), $this->callbacks(0));

        // A run under way whose group goes: whoever it has not reached yet is not reached at all.
        $service = $this->service(BroadcastService::class);
        $other = $this->customerGroup('همکاران', [$this->ali, User::query()->where('telegram_id', 1002)->sole()]);
        $run = $service->start($this->boss, ['audience' => Audience::GROUP, 'audience_id' => $other->id] + $this->draft(5), null);
        self::assertSame(2, $run->total);
        self::assertFalse($service->process($run, 1), 'Ali reached, Sara next');
        $this->service(CustomerGroups::class)->delete($other);
        $this->telegram()->reset();

        self::assertTrue($service->process($run, 50), 'over: nobody left');
        self::assertSame([], $this->paramsOf('copyMessage'));
        self::assertSame([BroadcastStatus::Done, 1], [$run->refresh()->status, $run->sent]);

        try {
            $service->start($this->boss, ['audience' => Audience::GROUP, 'audience_id' => $other->id] + $this->draft(6), null);
            self::fail('A run to a group that is gone was started.');
        } catch (ValidationException $e) {
            self::assertSame(['audience' => [Messages::BROADCAST_AUDIENCE_GONE]], $e->errors());
        }
    }

    public function testAnAudienceGoneBetweenTheLookAndTheStartStartsNothingAndTheDraftWaits(): void
    {
        $group = $this->customerGroup('VIP', [$this->ali]);
        $this->send($this->message('/broadcast'));
        $this->send($this->message('ویژه گروه', ['message_id' => 73]));
        $this->send($this->tap(self::step('aud', Audience::GROUP, $group->id)));

        $moment = null;
        $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use ($group, &$moment): void {
            if ($moment === null && str_starts_with($query->sql, 'select * from "customer_groups"')) {
                $moment = 'looked';
            } elseif ($moment === 'looked' && str_starts_with($query->sql, 'update "telegram_sessions"')) {
                // «ارسال» found the group there and takes the draft: the panel deletes the group right then.
                CustomerGroup::query()->whereKey($group->id)->delete();
                $moment = 'deleted';
            }
        }, fn() => $this->send($this->tap(self::step('send'))));

        self::assertSame('deleted', $moment, 'gone between the look and the start');
        self::assertSame(Messages::BROADCAST_AUDIENCE_GONE, $this->popup());
        self::assertSame(0, Broadcast::query()->count(), 'nothing started');
        $this->send($this->tap(self::step('aud')));
        self::assertSame([Messages::BROADCAST_AUDIENCE_ASK], $this->said(), 'the draft waits for another audience');
    }

    public function testAnAgentsBotNamesOnlyTheServersItsOwnCustomersAreOn(): void
    {
        $germany = $this->fakeServer('آلمان');
        $france = $this->fakeServer('فرانسه');
        $this->subscription($this->ali, $this->plan([], $germany), $germany, 'ali_1');
        $bot = $this->agentBot();
        CurrentBot::run($bot, function () use ($france): void {
            $customer = $this->customer(['telegram_id' => 3003, 'first_name' => 'Reza']);
            $this->subscription($customer, $this->plan(['traffic_gb' => 10], $france), $france, 'reza_1');
        });

        $this->sendTo($bot, $this->message('/broadcast', chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->message('سلام', ['message_id' => 74], self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->tap(self::step('aud'), self::AGENT_TELEGRAM_ID));
        self::assertNotContains(self::step('aud', Audience::AGENTS), $this->callbacks(0), 'an agent\'s bot has no agents of its own');

        $this->sendTo($bot, $this->tap(self::step('aud', Audience::SERVER), self::AGENT_TELEGRAM_ID));
        self::assertSame([self::step('aud', Audience::SERVER, $france->id), self::step('aud')], $this->callbacks(0), "the shop's other servers are not the agent's to learn");
        self::assertSame(self::option('فرانسه', 1), $this->inlineKeyboard(0)[0][0]['text']);

        $this->sendTo($bot, $this->tap(self::step('aud', Audience::SERVER, $germany->id), self::AGENT_TELEGRAM_ID));
        self::assertSame(Messages::BROADCAST_AUDIENCE_GONE, $this->popup(), 'nor to reach by naming one');

        // The main bot names its own customers' server, not the agent's.
        $this->send($this->message('/broadcast'));
        $this->send($this->message('سلام', ['message_id' => 75]));
        $this->send($this->tap(self::step('aud', Audience::SERVER)));
        self::assertSame([self::step('aud', Audience::SERVER, $germany->id), self::step('aud')], $this->callbacks(0));
    }

    public function testPauseResumeAndCancelFromTheProgressMessage(): void
    {
        $service = $this->service(BroadcastService::class);
        $broadcast = $service->start($this->boss, $this->draft(5), 77);

        $this->send($this->tap(BroadcastHandler::controlCallback(BroadcastControl::Pause->value, $broadcast->id)));
        self::assertSame(Messages::BROADCAST_PAUSED, $this->popup());
        self::assertSame([self::progress($broadcast, BroadcastStatus::Paused, sent: 0, blocked: 0)], $this->said(), 'the progress message follows');
        self::assertSame([BroadcastHandler::controlCallback(BroadcastControl::Cancel->value, $broadcast->id), BroadcastHandler::controlCallback(BroadcastControl::Resume->value, $broadcast->id)], $this->callbacks(0));
        self::assertSame(BroadcastStatus::Paused, $broadcast->refresh()->status);
        self::assertFalse($service->process($broadcast, 50), 'a paused run sends nothing');
        self::assertSame(0, $broadcast->refresh()->sent);

        $this->send($this->tap(BroadcastHandler::controlCallback(BroadcastControl::Pause->value, $broadcast->id)));
        self::assertSame(Messages::BROADCAST_UNCHANGED, $this->popup(), 'paused already: refused');
        self::assertSame('true', $this->params(0)['show_alert']);

        $this->send($this->tap(BroadcastHandler::controlCallback(BroadcastControl::Resume->value, $broadcast->id)));
        self::assertSame(Messages::BROADCAST_RESUMED, $this->popup());
        self::assertSame([(string) self::CHAT, '1001', '1002'], array_column($this->paramsOf('copyMessage'), 'chat_id'), 'resumed, and on with it at once');
        self::assertSame([BroadcastStatus::Done, 3, 1], [$broadcast->refresh()->status, $broadcast->sent, $broadcast->blocked]);

        $this->send($this->tap(BroadcastHandler::controlCallback(BroadcastControl::Cancel->value, $broadcast->id)));
        self::assertSame(Messages::BROADCAST_UNCHANGED, $this->popup(), 'over: nothing to cancel');

        $other = $service->start($this->boss, $this->draft(6), 78);
        $this->send($this->tap(BroadcastHandler::controlCallback(BroadcastControl::Cancel->value, $other->id)));
        self::assertSame(Messages::BROADCAST_STOPPED, $this->popup());
        self::assertSame(BroadcastStatus::Cancelled, $other->refresh()->status);
        self::assertTrue($service->process($other, 50), 'a cancelled run is over');
        self::assertSame(0, $other->refresh()->sent);
        $this->telegram()->reset();
        $service->report($other);
        self::assertSame([], $this->calls(), 'the admin who stopped it is not told it went to everyone');

        $this->send($this->tap(BroadcastHandler::controlCallback(BroadcastControl::Pause->value, $other->id + 1)));
        self::assertSame(Messages::BROADCAST_UNCHANGED, $this->popup(), 'a run that is not there');
    }

    public function testAPauseMidRunStopsItAtTheNextCustomerAndNobodyGetsItTwice(): void
    {
        $service = $this->service(BroadcastService::class);
        $run = $service->start($this->boss, $this->draft(5), null);
        // The admin pauses it while Ali's copy is on its way.
        $this->telegram()->on('copyMessage', static function (array $params) use ($service, $run): array {
            if ($params['chat_id'] === '1001') {
                $service->control(Broadcast::query()->findOrFail($run->id), BroadcastControl::Pause);
            }

            return ['message_id' => 500];
        });

        self::assertFalse($service->process($run, 50));
        self::assertSame([(string) self::CHAT, '1001'], array_column($this->paramsOf('copyMessage'), 'chat_id'), 'Sara is not reached');
        self::assertSame([BroadcastStatus::Paused, 2], [$run->refresh()->status, $run->sent], 'what went out is counted');

        $service->control($run, BroadcastControl::Resume);
        $this->telegram()->reset();
        self::assertTrue($service->process($run, 50));
        self::assertSame(['1002'], array_column($this->paramsOf('copyMessage'), 'chat_id'), 'on from where it stopped');
        self::assertSame([3, 1], [$run->refresh()->sent, $run->blocked]);
    }

    public function testACancelMidRunStopsItAtTheNextCustomerForGood(): void
    {
        $service = $this->service(BroadcastService::class);
        $run = $service->start($this->boss, $this->draft(5), null);
        $this->telegram()->on('copyMessage', static function () use ($service, $run): array {
            $service->control(Broadcast::query()->findOrFail($run->id), BroadcastControl::Cancel);

            return ['message_id' => 500];
        });

        self::assertFalse($service->process($run, 50));
        self::assertSame([(string) self::CHAT], array_column($this->paramsOf('copyMessage'), 'chat_id'));
        $this->telegram()->reset();
        self::assertTrue($service->process($run->refresh(), 50), 'over');
        self::assertSame([], $this->calls());
        self::assertSame([BroadcastStatus::Cancelled, 1], [$run->status, $run->sent]);
    }

    public function testOneWorkerAtATimeAndOneThatWentQuietIsTakenOver(): void
    {
        $service = $this->service(BroadcastService::class);
        $run = $service->start($this->boss, $this->draft(5), null);
        // Another worker — the scheduler, while the bot sends the first batch — holds the run.
        $run->forceFill(['lease_token' => 'another-worker', 'leased_until' => now()->addSeconds(30)])->save();

        self::assertFalse($service->process($run, 50));
        self::assertSame([], $this->calls(), 'nobody gets it from two workers');

        Carbon::setTestNow(now()->addSeconds(31));
        self::assertTrue($service->process($run, 50), 'a worker that went quiet loses its hold');
        self::assertSame([(string) self::CHAT, '1001', '1002'], array_column($this->paramsOf('copyMessage'), 'chat_id'));
        self::assertNull($run->refresh()->lease_token, 'let go once done');
    }

    public function testAWorkerWhoseHoldWasTakenOverStopsAtTheNextCustomer(): void
    {
        $service = $this->service(BroadcastService::class);
        $run = $service->start($this->boss, $this->draft(5), null);
        // Ali's copy takes so long that the hold runs out, and another worker takes the run over meanwhile.
        $this->telegram()->on('copyMessage', static function (array $params) use ($run): array {
            if ($params['chat_id'] === '1001') {
                Broadcast::query()->whereKey($run->id)->update(['lease_token' => 'another-worker', 'leased_until' => now()->addSeconds(60)]);
            }

            return ['message_id' => 500];
        });

        self::assertFalse($service->process($run, 50));
        self::assertSame([(string) self::CHAT, '1001'], array_column($this->paramsOf('copyMessage'), 'chat_id'), 'Sara is the other worker\'s now');
        self::assertSame('another-worker', $run->refresh()->lease_token, 'its hold untouched');

        // The other worker carries on from the cursor: Ali was claimed before his copy went.
        $run->forceFill(['lease_token' => null, 'leased_until' => null])->save();
        $this->telegram()->reset();
        self::assertTrue($service->process($run, 50));
        self::assertSame(['1002'], array_column($this->paramsOf('copyMessage'), 'chat_id'), 'nobody twice');
    }

    public function testEachCallIsPacedAndAKnownBlockerCostsNone(): void
    {
        $sleeper = new RecordingSleeper();
        $service = new BroadcastService($this->service(BotApi::class), $this->service(CustomerChats::class), new Pacer($sleeper), $this->service(LoggerInterface::class));
        $run = $service->start($this->boss, ['pin' => true] + $this->draft(5), null);
        $logs = $this->logs();
        $this->telegram()->on('copyMessage', static fn(array $params): mixed => $params['chat_id'] === '1002' ? FakeTelegram::error(403, 'Forbidden: bot was blocked by the user') : ['message_id' => 500]);
        // Ali's pin is refused: his message stands.
        $this->telegram()->on('pinChatMessage', static fn(array $params): mixed => $params['chat_id'] === '1001' ? FakeTelegram::error(400, 'Bad Request: not enough rights to manage pinned messages in the chat') : true);

        self::assertTrue($service->process($run, 50));

        self::assertSame(['copyMessage', 'pinChatMessage', 'copyMessage', 'pinChatMessage', 'copyMessage'], $this->calls());
        self::assertSame(array_fill(0, 5, intdiv(1_000_000, Pacer::PER_SECOND)), $sleeper->microseconds, 'a pause after each call — a pin, a refused copy too — and none for the known blocker, who costs no call');
        self::assertSame([2, 2, 0], [$run->refresh()->sent, $run->blocked, $run->failed]);
        self::assertSame([$this->boss->id], BroadcastPin::query()->pluck('user_id')->all(), 'only the pins that took are kept');
        self::assertTrue($logs->hasInfoThatContains('not pinned'));
    }

    public function testARefusedCopyIsCountedAsFailedAndTheRunGoesOn(): void
    {
        $service = $this->service(BroadcastService::class);
        $run = $service->start($this->boss, $this->draft(5), null);
        $logs = $this->logs();
        $this->telegram()->on('copyMessage', static fn(array $params): mixed => match ($params['chat_id']) {
            '1001' => FakeTelegram::error(400, 'Bad Request: chat not found'),
            '1002' => FakeTelegram::error(400, 'Bad Request: message to copy not found'),
            default => ['message_id' => 500],
        });

        self::assertTrue($service->process($run, 50), 'on to the end');

        self::assertSame([(string) self::CHAT, '1001', '1002'], array_column($this->paramsOf('copyMessage'), 'chat_id'));
        self::assertSame([1, 2, 1], [$run->refresh()->sent, $run->blocked, $run->failed], "Ali's chat is gone: he turned the bot away, like Gone");
        self::assertSame([true, false], [$this->ali->refresh()->bot_blocked, User::query()->where('telegram_id', 1002)->sole()->bot_blocked], 'Ali is not written to again; Sara is');
        self::assertTrue($logs->hasWarningThatContains('could not reach user'));
    }

    public function testAFloodLimitHoldsTheCustomerForTheNextBatch(): void
    {
        $service = $this->service(BroadcastService::class);
        $broadcast = $service->start($this->boss, $this->draft(5), null);
        $floods = 1;
        $this->telegram()->on('copyMessage', static function (array $params) use (&$floods): mixed {
            return $params['chat_id'] === '1001' && $floods-- > 0 ? FakeTelegram::flood(60) : ['message_id' => 500];
        });

        self::assertFalse($service->process($broadcast, 50));
        self::assertSame([(string) self::CHAT, '1001'], array_column($this->paramsOf('copyMessage'), 'chat_id'));
        $broadcast->refresh();
        self::assertSame([1, 0, $this->boss->id], [$broadcast->sent, $broadcast->failed, $broadcast->last_user_id], 'Ali is neither counted nor passed');

        $this->telegram()->reset();
        self::assertTrue($service->process($broadcast, 50));
        self::assertSame(['1001', '1002'], array_column($this->paramsOf('copyMessage'), 'chat_id'), 'Ali gets it now, once');
        self::assertSame([3, 1], [$broadcast->refresh()->sent, $broadcast->blocked]);
    }

    public function testAPinTelegramNoLongerHasCountsAsOffAndAnUnpinRunIsNoNewsForItsAdmin(): void
    {
        $service = $this->service(BroadcastService::class);
        $pinned = $service->start($this->boss, ['pin' => true] + $this->draft(5), 77);
        $service->process($pinned, 50);
        self::assertSame(3, BroadcastPin::query()->count());
        $run = $service->startUnpin($pinned->refresh(), $this->boss, null);
        $run->forceFill(['progress_message_id' => 78])->save();
        $logs = $this->logs();
        $floods = 1;
        $this->telegram()->on('unpinChatMessage', static function (array $params) use (&$floods): mixed {
            return match ($params['chat_id']) {
                // A flood limit first, then Telegram out of reach: not taken off.
                (string) self::CHAT => $floods-- > 0 ? FakeTelegram::flood(60) : FakeTelegram::error(502, 'Bad Gateway'),
                // Ali deleted the message; Sara blocked the bot since: nothing to take off.
                '1001' => FakeTelegram::error(400, 'Bad Request: message to unpin not found'),
                default => FakeTelegram::error(403, 'Forbidden: bot was blocked by the user'),
            };
        });
        $this->telegram()->reset();

        $this->service(SendBroadcastsTask::class)->run();
        self::assertSame([(string) self::CHAT], array_column($this->paramsOf('unpinChatMessage'), 'chat_id'), 'the flood limit stops the tick');
        self::assertSame([0, 0, 3], [$run->refresh()->sent, $run->failed, BroadcastPin::query()->count()], 'the pin put back for the next one');

        $this->telegram()->reset();
        $this->service(SendBroadcastsTask::class)->run();
        self::assertSame([(string) self::CHAT, '1001', '1002'], array_column($this->paramsOf('unpinChatMessage'), 'chat_id'));
        self::assertSame([BroadcastStatus::Done, 2, 1], [$run->refresh()->status, $run->sent, $run->failed]);
        self::assertSame([$this->boss->id], BroadcastPin::query()->pluck('user_id')->all(), 'only the pin that could not come off stays');
        self::assertTrue($logs->hasWarningThatContains('not taken off'));
        $said = $this->said();
        self::assertSame(self::unpinProgress($run, sent: 2, failed: 1), end($said), 'its progress says it all…');
        self::assertSame([], $this->telegram()->sentTo(self::CHAT), '…and no summary follows');
        self::assertTrue($pinned->refresh()->canUnpin(), 'the pin left can come off later');
    }

    public function testAPinInTheChatOfACustomerWithoutTelegramAnyMoreCountsAsOffWithoutACall(): void
    {
        $service = $this->service(BroadcastService::class);
        $pinned = $service->start($this->boss, ['pin' => true] + $this->draft(5), null);
        $service->process($pinned, 50);
        // Ali took Telegram off his account on the shop's website: there is no chat of his to ask any more.
        $this->ali->forceFill(['telegram_id' => null, 'email' => 'ali@example.com'])->save();
        $run = $service->startUnpin($pinned->refresh(), $this->boss, null);
        $this->telegram()->reset();

        self::assertTrue($service->process($run, 50));

        self::assertSame([(string) self::CHAT, '1002'], array_column($this->paramsOf('unpinChatMessage'), 'chat_id'), 'his is not asked about');
        self::assertSame([BroadcastStatus::Done, 3, 0], [$run->refresh()->status, $run->sent, $run->failed], 'and counted as off');
        self::assertSame(0, BroadcastPin::query()->count());
    }

    public function testTheAdminsOwnMessagesAreCourtesiesARunNeverWaitsOn(): void
    {
        $run = $this->service(BroadcastService::class)->start($this->boss, $this->draft(5), 77);
        $logs = $this->logs();
        // The admin deleted the progress message, then blocked the bot.
        $this->telegram()->on('editMessageText', static fn(): mixed => FakeTelegram::error(400, 'Bad Request: message to edit not found'));
        $this->telegram()->on('sendMessage', static fn(): mixed => FakeTelegram::error(403, 'Forbidden: bot was blocked by the user'));

        $this->service(SendBroadcastsTask::class)->run();

        self::assertSame([BroadcastStatus::Done, 3], [$run->refresh()->status, $run->sent]);
        self::assertSame([(string) self::CHAT], array_column($this->paramsOf('sendMessage'), 'chat_id'), 'the summary was tried');
        self::assertTrue($logs->hasInfoThatContains('progress not shown'));
        self::assertTrue($logs->hasWarningThatContains('Could not report broadcast'));
    }

    public function testTheBotSendsTheFirstFewItselfTheSchedulerTheRestAndTheAdminHearsWhenAllGotIt(): void
    {
        $inline = self::inlineLimit();
        // Everyone: the four, and twice the bot's share less two more.
        foreach (range(1, 2 * $inline - 2) as $i) {
            $this->customer(['telegram_id' => 20000 + $i, 'first_name' => "U{$i}"]);
        }
        $this->send($this->message('/broadcast'));
        $this->send($this->message('سلام به همه', ['message_id' => 72]));

        $this->send($this->tap(self::step('send')));
        $run = Broadcast::query()->sole();
        self::assertSame(2 * $inline + 2, $run->total);
        self::assertCount($inline - 1, $this->paramsOf('copyMessage'), 'the first few while the admin waits — the known blocker among them, at no call');
        self::assertSame([$inline - 1, 1], [$run->refresh()->sent, $run->blocked]);

        // Paused and resumed: the next few right away.
        $this->send($this->tap(BroadcastHandler::controlCallback(BroadcastControl::Pause->value, $run->id)));
        $this->send($this->tap(BroadcastHandler::controlCallback(BroadcastControl::Resume->value, $run->id)));
        self::assertCount($inline, $this->paramsOf('copyMessage'));
        self::assertSame(2 * $inline - 1, $run->refresh()->sent);

        // The scheduler finishes it and tells the admin it went to everyone.
        $this->telegram()->reset();
        $this->service(SendBroadcastsTask::class)->run();
        self::assertCount(2, $this->paramsOf('copyMessage'));
        self::assertSame(BroadcastStatus::Done, $run->refresh()->status);
        self::assertSame([Messages::fill(Messages::BROADCAST_DONE, [
            'id' => $run->id,
            'sent' => Persian::number(2 * $inline + 1),
            'blocked' => Persian::number(1),
            'failed' => Persian::number(0),
        ])], $this->telegram()->sentTo(self::CHAT));
        self::assertSame(0, Broadcast::sending()->count());
    }

    public function testTheSchedulerStopsWhenTheRunsTimeIsUpAndTheNextTickGoesOn(): void
    {
        $run = $this->service(BroadcastService::class)->start($this->boss, $this->draft(5), 77);
        $budget = $this->service(Budget::class);

        $budget->turn(0.0);
        try {
            $this->service(SendBroadcastsTask::class)->run();
        } finally {
            $budget->turn(null);
        }
        self::assertSame([], $this->paramsOf('copyMessage'), 'out of time before the first send');
        self::assertSame([BroadcastStatus::Sending, 0], [$run->refresh()->status, $run->sent]);

        $this->service(SendBroadcastsTask::class)->run();
        self::assertSame([(string) self::CHAT, '1001', '1002'], array_column($this->paramsOf('copyMessage'), 'chat_id'), 'the next tick picks up where it left off');
        self::assertSame(BroadcastStatus::Done, $run->refresh()->status);
    }

    public function testARunThatBreaksIsLoggedAndTheNextOneGoesOn(): void
    {
        $service = $this->service(BroadcastService::class);
        $broken = $service->start($this->boss, $this->draft(5), null);
        // Its admin's account is gone: there is no chat to copy the message from.
        Broadcast::query()->whereKey($broken->id)->update(['user_id' => null]);
        $next = $service->start($this->boss, $this->draft(6), null);
        $logs = $this->logs();

        $this->service(SendBroadcastsTask::class)->run();

        self::assertTrue($logs->hasErrorThatContains('failed'));
        self::assertSame([BroadcastStatus::Sending, null], [$broken->refresh()->status, $broken->lease_token], 'its hold let go: it is looked at again, and logged again');
        self::assertSame([BroadcastStatus::Done, 3], [$next->refresh()->status, $next->sent], 'the next run went on');
    }

    /** @return array{message_id: int, mode: BroadcastMode, audience: string, pin: bool} A copy of message `$messageId` to everyone, unpinned. */
    private function draft(int $messageId): array
    {
        return ['message_id' => $messageId, 'mode' => BroadcastMode::Copy, 'audience' => Audience::ALL, 'pin' => false];
    }

    /** @return list<array<string, string>> The parameters of each call of `$method` since the last step, in order. */
    private function paramsOf(string $method): array
    {
        return array_values(array_column(array_filter($this->telegram()->history, static fn(array $call): bool => $call['method'] === $method), 'params'));
    }

    /** @return list<array<string, string>> The edits of the admin's message `$messageId` since the last step, in order. */
    private function editsOf(int $messageId): array
    {
        return array_values(array_filter($this->paramsOf('editMessageText'), static fn(array $params): bool => $params['message_id'] === (string) $messageId));
    }

    /** @return array<string, string> The last edit since the last step — where a run's progress stands. */
    private function lastEdit(): array
    {
        $edits = $this->paramsOf('editMessageText');

        return end($edits) ?: self::fail('Nothing was edited.');
    }

    /** A button of the draft card: `broadcast:<step>[:…]`. */
    private static function step(int|string ...$args): string
    {
        return CallbackData::build(BroadcastHandler::CALLBACK, ...$args);
    }

    /** What the draft card says of a message: its kind, and its words. */
    private static function content(string $kind, string $words): string
    {
        return Messages::BROADCAST_CONTENT[$kind] . ' — «' . $words . '»';
    }

    /**
     * The draft card as the admin reads it: `$values` over a copy to everyone — the four, one of whom blocked the bot —,
     * unpinned, without buttons.
     *
     * @param array<string, string> $values
     */
    private static function card(string $content, array $values = []): string
    {
        return Messages::fill(Messages::BROADCAST_DRAFT, $values + [
            'content' => $content,
            'mode' => Messages::BROADCAST_MODE_COPY,
            'audience' => Audience::LABELS[Audience::ALL],
            'count' => Persian::number(4),
            'blocked' => Messages::fill(Messages::BROADCAST_DRAFT_BLOCKED, ['count' => Persian::number(1)]),
            'pin' => Messages::BROADCAST_OFF,
            'buttons' => Messages::BROADCAST_NO_BUTTONS,
        ]);
    }

    /** An audience as the picker offers it: its words and how many it reaches. */
    private static function option(string $label, int $count): string
    {
        return Messages::fill(Messages::BROADCAST_AUDIENCE_OPTION, ['label' => $label, 'count' => Persian::number($count)]);
    }

    /** The progress message of a copy to everyone (the four), unpinned, as the admin reads it. */
    private static function progress(Broadcast $run, BroadcastStatus $status, int $sent, int $blocked): string
    {
        return Messages::fill(Messages::BROADCAST_PROGRESS, [
            'id' => $run->id,
            'status' => $status->label(),
            'audience' => Audience::LABELS[Audience::ALL],
            'pin' => '',
            'sent' => Persian::number($sent),
            'blocked' => Persian::number($blocked),
            'failed' => Persian::number(0),
            'done' => Persian::number($sent + $blocked),
            'total' => Persian::number(4),
        ]);
    }

    /** The progress message of an unpin run that is over, as the admin reads it. */
    private static function unpinProgress(Broadcast $run, int $sent, int $failed): string
    {
        return Messages::fill(Messages::UNPIN_PROGRESS, [
            'source' => (int) $run->source_id,
            'status' => BroadcastStatus::Done->label(),
            'sent' => Persian::number($sent),
            'failed' => Persian::number($failed),
            'done' => Persian::number($sent + $failed),
            'total' => Persian::number($run->total),
        ]);
    }

    /** How many customers the bot handles itself, right after «ارسال» or «ادامه», before the scheduler takes over. */
    private static function inlineLimit(): int
    {
        return (int) (new \ReflectionClassConstant(BroadcastHandler::class, 'INLINE_LIMIT'))->getValue();
    }
}
