<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerCards;

use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Customers\CustomerCards;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * «🏷 تخفیف» on a customer's card in the report group: their own discount and what it does, a preset percent set at
 * once — the current one ✅ — and «✍️ درصد دیگر», which asks for any percent from 0 to 100. Never the admin's own
 * account, an agent's or another admin's: their card is locked, and a press or a reply about them refused.
 */
final class CustomerDiscountTest extends BotTestCase
{
    /** A card of the bot's in the group, under which the buttons are pressed. */
    private const CARD = 5252;

    private const ABOUT = 'ℹ️ از هر خریدش کم می‌شود؛ اگر فروش ویژه یا تخفیف سطح وفاداری‌اش بیشتر باشد همان جایش را می‌گیرد و با هم جمع نمی‌شوند.';

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

    public function testTheSectionShowsThePercentWhatItDoesAndThePresets(): void
    {
        $this->send($this->press("ud:o:{$this->ali->id}"));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertSame(implode("\n", [
            "⚙️ <b>مدیریت کاربر › تخفیف اختصاصی</b> · \u{200E}#{$this->ali->id}",
            "👤 <a href=\"tg://user?id=7070\">Ali</a> · \u{200E}@ali · <code>7070</code>",
            '🏷 تخفیف اختصاصی: ندارد',
            self::ABOUT,
        ]), $this->params(0)['text']);
        self::assertSame([
            ['۱۰٪', '۵٪', '✅ بدون تخفیف'],
            ['۲۵٪', '۲۰٪', '۱۵٪'],
            ['✍️ درصد دیگر', '۵۰٪', '۳۰٪'],
            ['⬅️ بازگشت'],
        ], $this->labels(0), 'three a row, each row\'s first on the right');
        $id = $this->ali->id;
        self::assertSame([
            "ud:s:{$id}:10", "ud:s:{$id}:5", "ud:s:{$id}:0",
            "ud:s:{$id}:25", "ud:s:{$id}:20", "ud:s:{$id}:15",
            "ud:a:{$id}", "ud:s:{$id}:50", "ud:s:{$id}:30",
            "um:o:{$id}",
        ], $this->callbacks(0));
    }

    public function testAPresetIsSetAtOnceAndMarked(): void
    {
        $this->send($this->press("ud:s:{$this->ali->id}:15"));

        self::assertSame(15, $this->ali->refresh()->discount_percent);
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertStringContainsString("\n🏷 تخفیف اختصاصی: ۱۵٪\n", $this->params(0)['text']);
        self::assertSame('✅ ۱۵٪', $this->labels(0)[1][2]);
        self::assertSame('بدون تخفیف', $this->labels(0)[0][2]);
        self::assertSame('🏷 تخفیف اختصاصی ۱۵٪ شد.', $this->popup());
        self::assertNotSame('true', $this->params(1)['show_alert'] ?? null, 'a toast');

        $this->send($this->press("ud:s:{$this->ali->id}:0"));
        self::assertSame(0, $this->ali->refresh()->discount_percent);
        self::assertSame('🏷 تخفیف اختصاصی برداشته شد.', $this->popup());
        self::assertSame('✅ بدون تخفیف', $this->labels(0)[0][2]);

        $this->send($this->press("ud:s:{$this->ali->id}:120"));
        self::assertSame(0, $this->ali->refresh()->discount_percent, 'a forged press past 100');
        self::assertSame('درصد تخفیف باید عددی بین 0 تا 100 باشد؛ 0 یعنی بدون تخفیف.', $this->popup());

        $this->send($this->press("ud:s:{$this->ali->id}:x"));
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'a malformed press only answered');
        self::assertNull($this->popup());
    }

    public function testAnotherPercentIsAskedAndSetByTheReply(): void
    {
        $this->send($this->press("ud:a:{$this->ali->id}"));

        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls());
        self::assertSame("✍️ تخفیف اختصاصی چند درصد باشد؟ عددی از ۰ تا ۱۰۰ بنویسید؛ ۰ یعنی بدون تخفیف. \u{200E}@boss", $this->params(0)['text']);
        self::assertTrue($this->markup(0)['force_reply']);
        self::assertSame(CustomerCards::WRITE_REPLY, $this->popup());
        $prompt = $this->prompt();

        foreach (['۱۲۰', 'دوازده', '12.5', '-3'] as $typed) {
            $this->send($this->reply($prompt, $typed));
            self::assertSame(['درصد را با رقم بنویسید: عددی از ۰ تا ۱۰۰؛ ۰ یعنی بدون تخفیف.'], $this->said(), $typed);
        }
        self::assertSame(0, $this->ali->refresh()->discount_percent);

        $this->send($this->reply($prompt, 'نه', from: self::GROUP_MEMBER));
        self::assertSame([GroupButtons::onlyAdmins(CustomerCards::MAY)], $this->said());

        $this->send($this->reply($prompt, '۱۲٪'));
        self::assertSame(12, $this->ali->refresh()->discount_percent);
        self::assertSame(['editMessageText', 'deleteMessage', 'sendMessage'], $this->calls());
        self::assertSame((string) self::CARD, $this->params(0)['message_id']);
        self::assertStringContainsString("\n🏷 تخفیف اختصاصی: ۱۲٪\n", $this->params(0)['text']);
        self::assertStringNotContainsString('✅', implode(' ', array_merge(...$this->labels(0))), 'no preset is 12');
        self::assertSame((string) $prompt, $this->params(1)['message_id'], 'the question deleted');
        self::assertSame('✅ تخفیف اختصاصی ۱۲٪ شد.', $this->params(2)['text']);
    }

    public function testTheirOwnAnAgentsAndAnotherAdminsAreLockedAndRefused(): void
    {
        $sara = $this->admin(['telegram_id' => 9191, 'username' => 'sara']);
        $agent = $this->agent(overrides: ['telegram_id' => 9292, 'username' => 'agent_reza']);
        $locked = [
            ActorRefusedException::OWN_ACCOUNT => [$this->boss, '🔒 حساب خودتان است؛ با مدیر دیگری یا از پنل تغییر می‌کند.'],
            ActorRefusedException::AGENT_ACCOUNT => [$agent, '🔒 حساب نماینده است؛ فقط از پنل تغییر می‌کند.'],
            ActorRefusedException::ADMIN_DISCOUNT => [$sara, '🔒 حساب مدیر ربات است؛ تخفیف اختصاصی‌اش فقط از پنل تغییر می‌کند.'],
        ];
        foreach ($locked as $why => [$user, $lock]) {
            $this->send($this->press("ud:o:{$user->id}"));
            self::assertStringEndsWith(self::ABOUT . "\n\n" . $lock, $this->params(0)['text'], $why);
            self::assertSame(["um:o:{$user->id}"], $this->callbacks(0), "{$why}: only the way back");

            $this->send($this->press("ud:s:{$user->id}:20"));
            self::assertSame($why, $this->popup());
            self::assertSame('true', $this->params(1)['show_alert']);
            self::assertStringEndsWith($lock, $this->params(0)['text'], "{$why}: the section as it stands");
            self::assertSame(0, $user->refresh()->discount_percent, $why);

            $this->send($this->press("ud:a:{$user->id}"));
            self::assertSame($why, $this->popup(), "{$why}: nothing asked");
            self::assertNotContains('sendMessage', $this->calls());
        }
        self::assertSame(0, ReportMessage::query()->where('ref', 'like', 'ask:ud:%')->count());
    }

    public function testAnAccountLockedMeanwhileIsRefusedOnReply(): void
    {
        $this->send($this->press("ud:a:{$this->ali->id}"));
        $prompt = $this->prompt();
        $this->ali->forceFill(['agency_level_id' => $this->agencyLevel()->id])->save();

        $this->send($this->reply($prompt, '10'));
        self::assertSame([ActorRefusedException::AGENT_ACCOUNT], $this->said(), 'the question kept');
        self::assertSame(0, $this->ali->refresh()->discount_percent);
    }

    public function testOnlyTheBotsAdminsPress(): void
    {
        $this->send($this->press("ud:s:{$this->ali->id}:50", from: self::GROUP_MEMBER));

        self::assertSame(GroupButtons::onlyAdmins(CustomerCards::MAY), $this->popup());
        self::assertSame(0, $this->ali->refresh()->discount_percent);
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
        return (int) ReportMessage::query()->where('ref', 'like', 'ask:ud:%')->latest('id')->value('message_id');
    }

    /** @return list<list<string>> The labels of the n-th call's inline buttons, row by row. */
    private function labels(int $call): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'text'), $this->inlineKeyboard($call));
    }
}
