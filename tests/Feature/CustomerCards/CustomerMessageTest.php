<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerCards;

use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Customers\CustomerCards;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Telegram\Reports\UserManagement;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserActions;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * «✉️ ارسال پیام» on a customer's card in the report group: the admin's reply to the bot's question goes to the customer
 * from support — under «📩 پیام از طرف پشتیبانی:» unless the bot settings say otherwise, its length counted as Telegram
 * counts it and cut under a long heading —, the card's front comes back and what came of it is said under the reply.
 * Never to the admin's own account, through another admin's question neither.
 */
final class CustomerMessageTest extends BotTestCase
{
    /** A card of the bot's in the group, under which the buttons are pressed. */
    private const CARD = 5252;

    /** @var array<string, int> */
    private array $threads;

    private User $boss;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->threads = $this->reportGroup();
        $this->boss = $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $this->ali = $this->customer(['telegram_id' => 7070, 'username' => 'ali']);
    }

    public function testTheReplyIsSentToTheCustomerFromSupportAndTheFrontComesBack(): void
    {
        self::assertContains("ux:a:{$this->ali->id}", array_column(array_merge(...UserManagement::main($this->ali, $this->boss)), 'callback_data'));

        $this->send($this->press("ux:a:{$this->ali->id}"));
        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls());
        self::assertStringStartsWith('✉️ متن پیام را در پاسخ به همین پیام بنویسید', $this->params(0)['text']);
        self::assertTrue($this->markup(0)['force_reply']);
        $prompt = $this->prompt();

        $this->send($this->reply($prompt, 'نه', from: self::GROUP_MEMBER));
        self::assertSame([GroupButtons::onlyAdmins(CustomerCards::MAY)], $this->said());
        self::assertSame([], $this->telegram()->sentTo(7070), 'nothing sent on a member\'s word');

        $this->send($this->reply($prompt, str_repeat('ا', UserActions::MESSAGE_MAX + 1)));
        self::assertSame(['متن پیام حداکثر 3500 کاراکتر است.'], $this->said(), 'too long: asked again');

        $this->send($this->reply($prompt, "سلام علی <b>جان</b>\nسرور آلمان درست شد."));
        self::assertSame(["📩 پیام از طرف پشتیبانی:\n\nسلام علی &lt;b&gt;جان&lt;/b&gt;\nسرور آلمان درست شد."], $this->telegram()->sentTo(7070), 'under its heading, the admin\'s words as plain text');
        self::assertSame(['sendMessage', 'editMessageText', 'deleteMessage', 'sendMessage'], $this->calls(), 'the message, then the card back, the question gone, the word under the reply');
        self::assertSame((string) self::CARD, $this->params(1)['message_id']);
        self::assertStringStartsWith("⚙️ <b>مدیریت کاربر</b> · \u{200E}#{$this->ali->id}", $this->params(1)['text']);
        self::assertSame((string) $prompt, $this->params(2)['message_id']);
        self::assertSame('✅ پیام در تلگرام به او رسید.', $this->params(3)['text']);
        self::assertSame(NoticeType::SupportMessage, Notification::query()->where('user_id', $this->ali->id)->sole()->type, 'kept for their website');
    }

    public function testItsLimitCountsAsTelegramDoesAndALongHeadingCutsTheWordsToAMessage(): void
    {
        $this->send($this->press("ux:a:{$this->ali->id}"));
        $prompt = $this->prompt();

        // 3,500 characters, 600 of them emoji: 4,100 as Telegram counts them.
        $this->send($this->reply($prompt, str_repeat('ا', 2900) . str_repeat('😀', 600)));
        self::assertSame(['متن پیام حداکثر 3500 کاراکتر است.'], $this->said(), 'too long as Telegram counts it: asked again');
        self::assertSame([], $this->telegram()->sentTo(7070));

        $this->service(BotTexts::class)->save(BotText::SupportMessage, '📩 ' . str_repeat('پیام از طرف پشتیبانی ', 40) . "\n\n%message%");
        $words = str_repeat('ا', 2300) . str_repeat('😀', 600);
        self::assertSame(UserActions::MESSAGE_MAX, Limits::length($words));
        $this->send($this->reply($prompt, $words));

        $sent = $this->telegram()->sentTo(7070);
        self::assertCount(1, $sent, 'at the limit: it goes');
        self::assertLessThanOrEqual(Limits::MESSAGE, TelegramHtml::visibleLength($sent[0]), 'within a message under its long heading');
        self::assertStringEndsWith('…', $sent[0], 'its end cut');
        self::assertSame($sent[0], Notification::query()->where('user_id', $this->ali->id)->sole()->text, 'as the website keeps it');
    }

    public function testAnotherAdminsQuestionAboutTheirOwnAccountTheyAnswerIsAskedAgain(): void
    {
        $reza = $this->admin(['telegram_id' => 9191, 'username' => 'reza']);
        $this->send($this->press("ux:a:{$reza->id}"));
        $prompt = $this->prompt();

        $this->send($this->reply($prompt, 'سلام خودم', from: 9191));
        self::assertSame(['برای خودتان پیام نمی‌فرستید.'], $this->said());
        self::assertSame([], $this->telegram()->sentTo(9191), 'nothing sent to their own account');

        $this->send($this->reply($prompt, 'سلام رضا'));
        self::assertSame(["📩 پیام از طرف پشتیبانی:\n\nسلام رضا"], $this->telegram()->sentTo(9191), 'the question given back: the admin who asked writes to them');
    }

    public function testWhatCameOfItSaysTheEmailForOneWithoutTelegram(): void
    {
        $sara = $this->customer(['telegram_id' => null, 'email' => 'sara@example.com', 'username' => null]);
        $this->send($this->press("ux:a:{$sara->id}"));

        $this->send($this->reply($this->prompt(), 'سلام'));

        self::assertSame('⚠️ تلگرام ندارد و ایمیل هم برایش نرفت؛ پیام فقط در وب‌سایتش می‌ماند.', end($this->telegram()->history)['params']['text'], 'an email of theirs, the shop\'s email not set up');
    }

    public function testWithoutTheHeadingTheWordsGoAlone(): void
    {
        $this->botSettings('general', ['support_header' => false]);
        $this->send($this->press("ux:a:{$this->ali->id}"));

        $this->send($this->reply($this->prompt(), 'تمدید شما انجام شد.'));

        self::assertSame(['تمدید شما انجام شد.'], $this->telegram()->sentTo(7070));
    }

    public function testACustomerWhoBlockedTheBotIsSaidSo(): void
    {
        $this->send($this->press("ux:a:{$this->ali->id}"));
        $prompt = $this->prompt();
        $this->telegram()->on('sendMessage', static fn(array $params): mixed => $params['chat_id'] === '7070' ? FakeTelegram::error(403, 'Forbidden: bot was blocked by the user') : ['message_id' => 900]);

        $this->send($this->reply($prompt, 'سلام'));

        self::assertSame('⚠️ ربات را بلاک کرده؛ پیام در تلگرام به او نرسید و فقط در وب‌سایتش می‌ماند.', end($this->telegram()->history)['params']['text']);
        self::assertTrue($this->ali->refresh()->bot_blocked);
    }

    public function testTheirOwnCardOffersNoneAndAPressIsRefused(): void
    {
        self::assertNotContains("ux:a:{$this->boss->id}", array_column(array_merge(...UserManagement::main($this->boss, $this->boss)), 'callback_data'));
        $agent = $this->agent(overrides: ['telegram_id' => 9292]);
        self::assertContains("ux:a:{$agent->id}", array_column(array_merge(...UserManagement::main($agent, $this->boss)), 'callback_data'), "an agent's locked card still messages them");

        $this->send($this->press("ux:a:{$this->boss->id}"));
        self::assertSame('برای خودتان پیام نمی‌فرستید.', $this->popup());
        self::assertSame(0, ReportMessage::query()->where('ref', 'like', 'ask:ux:%')->count(), 'nothing asked');
    }

    /** A press on the card's buttons, by the report group's bot admin unless told otherwise. */
    private function press(string $data, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupTap($data, self::CARD, $this->threads['users'], $from);
    }

    /** A reply to the bot's question `$prompt`, by the report group's bot admin unless told otherwise. */
    private function reply(int $prompt, string $text, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupReply('…', $text, $this->threads['users'], $prompt, $from);
    }

    /** The message id of the section's latest question. */
    private function prompt(): int
    {
        return (int) ReportMessage::query()->where('ref', 'like', 'ask:ux:%')->latest('id')->value('message_id');
    }
}
