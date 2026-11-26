<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Admin\Api\PremiumTextsController;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Admin\Sections\Bot\Texts\PremiumTextsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Emoji\EmojiMap;
use App\Modules\Telegram\Emoji\PremiumCheck;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Telegram\Models\CustomEmoji;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\ReceivedUpdates;
use App\Modules\Users\Enums\UserRole;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * «💎 ایموجی پرمیوم متن‌ها» from the bot (Sections\Bot\Texts\PremiumTextsScreen): whether the bot may send premium emoji
 * — able, unable, never checked —; the check, sent by this chat's bot to this very chat and offered in the shop's own
 * bot alone, its refusals said; the switch, refused in the API's words until the bot was found able, carrying the state
 * it sets; the plain emoji the texts carry, in the API's order and paged, the swapped ones marked; an emoji's page —
 * where it is, its premium emoji picked from the library, changed, taken off (asked first) —, an older window's tap
 * acting on that emoji or saying it is gone, and 0.10.2's buttons showing the list; «➕ ایموجی دیگر», a premium emoji
 * picked first — a later page of the library —, its own plain emoji kept or another typed, a refused one asking again;
 * an agent's own shop from their own bot; and a shop's admin setting it up as the owner does.
 */
final class BotAreaPremiumTextsTest extends BotTestCase
{
    /** A second Telegram account linked as the owner's: the check must go to the chat tapped from, not the first linked. */
    private const OTHER_OWNER = 7002;

    private const NAME = 'ایموجی پرمیوم متن‌ها';

    /** Words of the check's test message alone (PremiumCheck), which tell it from the bot's other messages. */
    private const PROBE = 'این پیام آزمایشی است';

    /** The emoji a page of the list. */
    private const PER_PAGE = 40;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner(UserRole::Admin);
        CustomEmoji::query()->create(['emoji_id' => '111', 'emoji' => '✅']);
        CustomEmoji::query()->create(['emoji_id' => '222', 'emoji' => '❤️']);
    }

    public function testTheStatusSaysWhetherTheBotWasFoundAbleUnableOrNeverChecked(): void
    {
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111']]]);

        $this->tapAs(Nav::to('tx'));
        self::assertContains(Nav::to('txp'), $this->offered(), 'reached from «متن‌های ربات»');
        $this->tapAs(Nav::to('txe'));
        self::assertContains(Nav::to('txp'), $this->offered(), 'and from the library of premium emoji');

        $this->tapAs(Nav::to('txp'));
        $screen = $this->lastSaid();
        self::assertStringContainsString('<b>' . PremiumTextsScreen::TITLE . '</b>', $screen);
        self::assertStringContainsString('ربات: <b>❔ نامشخص، هنوز بررسی نشده</b>', $screen, 'never checked');
        self::assertStringNotContainsString('آخرین بررسی', $screen);
        self::assertStringContainsString('«' . PremiumTextsScreen::CHECK . '» را بزنید', $screen);
        self::assertStringContainsString(self::NAME . ': <b>' . Words::OFF . '</b>', $screen);
        self::assertStringContainsString('پرمیوم شده: <b>۱ از ۱۰۰</b>', $screen);
        self::assertStringContainsString('خاموش است: تا روشن نشود', $screen);
        self::assertSame(PremiumTextsScreen::LOCKED, $this->button(Nav::to('txp', 'on', 1))['text'] ?? null, 'the switch says what it waits for');
        self::assertNotNull($this->button(Nav::to('txp', 'chk')));

        $this->service(PremiumEmojiStatus::class)->record(false);
        $this->tapAs(Nav::to('txp'));
        $screen = $this->lastSaid();
        self::assertStringContainsString('ربات: <b>⚠️ نمی‌تواند ایموجی پرمیوم بفرستد</b>', $screen, 'found unable');
        self::assertStringContainsString('آخرین بررسی: <b>', $screen);
        self::assertStringContainsString('⚠️ ' . PremiumTextsScreen::WHY, $screen, 'why, in plain words');
        self::assertSame(PremiumTextsScreen::LOCKED, $this->button(Nav::to('txp', 'on', 1))['text'] ?? null);

        $this->service(PremiumEmojiStatus::class)->record(true);
        $this->tapAs(Nav::to('txp'));
        $screen = $this->lastSaid();
        self::assertStringContainsString('ربات: <b>✅ می‌تواند ایموجی پرمیوم بفرستد</b>', $screen, 'found able');
        self::assertStringContainsString('آخرین بررسی: <b>', $screen);
        self::assertStringNotContainsString('⚠️ ' . PremiumTextsScreen::WHY, $screen, 'no warning');
        self::assertSame(Words::turn(true, self::NAME), $this->button(Nav::to('txp', 'on', 1))['text'] ?? null);
    }

    public function testTheListHasTheEmojiTheTextsCarryInTheApisOrderPagedWithWhereEachIs(): void
    {
        $this->service(BotTexts::class)->save(BotText::PhoneVerified, 'ثبت شد 🦄 ❤ 🦀');
        $this->service(KeyboardLayouts::class)->save(KeyboardLayouts::START, ['type' => 'inline', 'rows' => [[
            ['action' => 'support', 'label' => '🦀 پشتیبانی', 'style' => null],
            ['action' => 'wallet', 'label' => '🦞 کیف پول', 'style' => null],
        ]]]);
        // The map's heart without its variation selector, the texts' maybe with it: one heart. 🦉 no text carries.
        CustomEmoji::query()->create(['emoji_id' => '333', 'emoji' => '🦉']);
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '❤', 'emoji_id' => '222'], ['emoji' => '🦉', 'emoji_id' => '333']]]);
        $rows = $this->inventory();
        $pages = (int) ceil(count($rows) / self::PER_PAGE);
        self::assertGreaterThan(1, $pages, 'the default texts alone carry more than a page');
        self::assertSame(['🦉', 0, 0, '333'], array_values($rows[array_key_last($rows)]), 'one no text carries: last');

        $this->tapAs(Nav::to('txp'));
        $carried = count(array_filter($rows, static fn(array $row): bool => $row['texts'] + $row['menu'] > 0));
        self::assertStringContainsString('ایموجی‌های متن‌ها: <b>' . Fmt::number($carried) . '</b>', $this->lastSaid());
        self::assertStringContainsString('پرمیوم شده: <b>۲ از ۱۰۰</b>', $this->lastSaid());
        self::assertContains(Nav::to(Screens::PAGE, 'show', 1, $pages), $this->offered());
        $this->assertFits();

        $seen = [];
        for ($page = 1; $page <= $pages; $page++) {
            $this->tapAs(Nav::to('txp', 'page', $page));
            $this->assertFits();
            foreach ($this->emojiButtons() as [$label, $token, $at]) {
                self::assertSame((string) $page, $at, 'each button carries the page it is on');
                $seen[] = [$label, $token];
            }
        }
        $expected = array_map(static fn(array $row): array => [$row['emoji'] . ($row['emoji_id'] !== null ? ' ' . PremiumTextsScreen::SWAPPED : ''), PremiumTextsScreen::token($row['emoji'])], $rows);
        self::assertSame($expected, $seen, 'every emoji in the API\'s order, a swapped one marked, forty a page');
        self::assertContains([$this->row('❤')['emoji'] . ' ' . PremiumTextsScreen::SWAPPED, PremiumTextsScreen::token('❤️')], $seen, 'the heart swapped, with or without its variation selector');

        // Where each is.
        $where = [
            '🦄' => 'در ۱ متن',
            '🦀' => 'در ۱ متن و ۱ دکمه منو',
            '🦞' => 'در ۱ دکمه منو',
            '✅' => 'در ' . Fmt::number($this->row('✅')['texts']) . ' متن',
            '🦉' => 'فعلا در هیچ متنی نیست',
        ];
        foreach ($where as $emoji => $said) {
            $this->tapAs(Nav::to('txp', 'e', PremiumTextsScreen::token($emoji)));
            self::assertStringContainsString('<b>ایموجی ' . $this->row($emoji)['emoji'] . '</b>', $this->lastSaid());
            self::assertStringContainsString('استفاده: <b>' . $said . '</b>', $this->lastSaid(), $emoji);
        }
        self::assertStringContainsString('ایموجی پرمیوم: <b><tg-emoji emoji-id="333">🦉</tg-emoji></b>', $this->lastSaid());
    }

    public function testAnEmojiIsSwappedChangedAndTakenOffFromItsPageItsItemKeptInPlace(): void
    {
        $this->service(BotTexts::class)->save(BotText::PhoneVerified, 'ثبت شد 🦄');
        CustomEmoji::query()->create(['emoji_id' => '333', 'emoji' => '🦉']);
        $unicorn = PremiumTextsScreen::token('🦄');
        $page = $this->listPage('🦄');

        $this->tapAs(Nav::to('txp', 'page', $page));
        self::assertSame('🦄', $this->button(Nav::to('txp', 'e', $unicorn, $page))['text'] ?? null, 'nothing swapped in: the emoji alone');

        $this->tapAs(Nav::to('txp', 'e', $unicorn, $page));
        $screen = $this->lastSaid();
        self::assertStringContainsString('<b>ایموجی 🦄</b>', $screen);
        self::assertStringContainsString('استفاده: <b>در ۱ متن</b>', $screen);
        self::assertStringContainsString('ایموجی پرمیوم: <b>ندارد</b>', $screen);
        self::assertSame(PremiumTextsScreen::PICK, $this->button(Nav::to('txp', 'ch', $unicorn))['text'] ?? null);
        self::assertNull($this->button(Nav::to('txp', 'un', $unicorn)), 'nothing to take off');
        self::assertContains(Nav::to('txp', 'page', $page), $this->offered(), 'back to the page it was opened from');

        // Picked from the library.
        $this->tapAs(Nav::to('txp', 'ch', $unicorn));
        self::assertStringContainsString('<b>' . PremiumTextsScreen::PICK . '</b>', $this->lastSaid());
        self::assertStringContainsString('<tg-emoji emoji-id="111">✅</tg-emoji>', $this->lastSaid());
        self::assertContains(Nav::to('txp', 'to', $unicorn, '222'), $this->offered());
        self::assertContains(Nav::to('txp', 'e', $unicorn), $this->offered(), 'back to its page');
        $this->tapAs(Nav::to('txp', 'to', $unicorn, '111'));
        self::assertSame(['enabled' => false, 'items' => [['emoji' => '🦄', 'emoji_id' => '111']]], $this->stored());
        self::assertSame('✅ ایموجی پرمیوم 🦄 ذخیره شد', $this->popup());
        self::assertStringContainsString('ایموجی پرمیوم: <b><tg-emoji emoji-id="111">🦄</tg-emoji></b>', $this->lastSaid());
        self::assertSame(PremiumTextsScreen::CHANGE, $this->button(Nav::to('txp', 'ch', $unicorn))['text'] ?? null);
        $remove = $this->button(Nav::to('txp', 'un', $unicorn));
        self::assertSame([PremiumTextsScreen::UNSWAP, 'danger'], [$remove['text'] ?? null, $remove['style'] ?? null]);

        $this->tapAs(Nav::to('txp', 'page', $page));
        self::assertSame('🦄 ' . PremiumTextsScreen::SWAPPED, $this->button(Nav::to('txp', 'e', $unicorn, $page))['text'] ?? null, 'marked on the list');

        // Changed: a premium emoji standing for another plain emoji is not offered; its item keeps its place.
        $this->service(EmojiMap::class)->save(['items' => [['emoji' => '🦄', 'emoji_id' => '111'], ['emoji' => '🦉', 'emoji_id' => '333']]]);
        $this->tapAs(Nav::to('txp', 'ch', $unicorn));
        self::assertStringContainsString('<b>' . PremiumTextsScreen::CHANGE . '</b>', $this->lastSaid());
        self::assertStringContainsString('ایموجی پرمیوم فعلی: <b><tg-emoji emoji-id="111">🦄</tg-emoji></b>', $this->lastSaid());
        self::assertSame([Nav::to('txp', 'to', $unicorn, '222')], array_values(array_filter($this->offered(), static fn(string $data): bool => str_starts_with($data, 'a:txp:to:'))));
        $this->tapAs(Nav::to('txp', 'to', $unicorn, '222'));
        self::assertSame([['emoji' => '🦄', 'emoji_id' => '222'], ['emoji' => '🦉', 'emoji_id' => '333']], $this->stored()['items']);
        self::assertStringContainsString('ایموجی پرمیوم: <b><tg-emoji emoji-id="222">🦄</tg-emoji></b>', $this->lastSaid());

        // An older picker's taps: one standing for another emoji, the same again, one gone from the library.
        $this->tapAs(Nav::to('txp', 'to', $unicorn, '333'));
        self::assertSame(PremiumTextsScreen::ALREADY, $this->popup());
        self::assertStringContainsString('<b>' . PremiumTextsScreen::CHANGE . '</b>', $this->lastSaid(), 'the picker again');
        $this->tapAs(Nav::to('txp', 'to', $unicorn, '222'));
        self::assertSame(PremiumTextsScreen::ALREADY, $this->popup());
        self::assertStringContainsString('<b>ایموجی 🦄</b>', $this->lastSaid(), 'its page');
        $this->tapAs(Nav::to('txp', 'to', $unicorn, '999'));
        self::assertSame(PremiumTextsScreen::NOT_KNOWN, $this->popup());
        self::assertSame([['emoji' => '🦄', 'emoji_id' => '222'], ['emoji' => '🦉', 'emoji_id' => '333']], $this->stored()['items'], 'nothing changed');

        // Taken off, asked first; the panel reordered the map meanwhile.
        $this->tapAs(Nav::to('txp', 'un', $unicorn));
        self::assertStringContainsString('❓ ایموجی پرمیوم این ایموجی برداشته شود؟', $this->lastSaid());
        self::assertStringContainsString('🦄 ← <tg-emoji emoji-id="222">🦄</tg-emoji>', $this->lastSaid());
        $yes = $this->button(Nav::to('txp', 'und', $unicorn));
        self::assertSame([Words::yes('برداشته شود', '🗑'), 'danger'], [$yes['text'] ?? null, $yes['style'] ?? null]);
        self::assertContains(Nav::to('txp', 'e', $unicorn), $this->offered(), '«✖️ انصراف»: its page');
        self::assertCount(2, $this->stored()['items']);
        $this->service(EmojiMap::class)->save(['items' => [['emoji' => '🦉', 'emoji_id' => '333'], ['emoji' => '🦄', 'emoji_id' => '222']]]);
        $this->tapAs(Nav::to('txp', 'und', $unicorn));
        self::assertSame(['enabled' => false, 'items' => [['emoji' => '🦉', 'emoji_id' => '333']]], $this->stored());
        self::assertSame('🗑 ایموجی پرمیوم 🦄 برداشته شد', $this->popup());
        self::assertStringContainsString('ایموجی پرمیوم: <b>ندارد</b>', $this->lastSaid(), 'still carried: its page');

        // None any more: said, nothing asked or changed.
        $this->tapAs(Nav::to('txp', 'und', $unicorn));
        self::assertSame(PremiumTextsScreen::NOT_SWAPPED, $this->popup());
        $this->tapAs(Nav::to('txp', 'un', $unicorn));
        self::assertSame(PremiumTextsScreen::NOT_SWAPPED, $this->popup());
        self::assertStringNotContainsString('❓', $this->lastSaid());
        self::assertCount(1, $this->stored()['items']);

        // One no text carries, taken off: the list, which has it no more.
        $this->tapAs(Nav::to('txp', 'und', PremiumTextsScreen::token('🦉')));
        self::assertSame(['enabled' => false, 'items' => []], $this->stored());
        self::assertSame('🗑 ایموجی پرمیوم 🦉 برداشته شد', $this->popup());
        self::assertStringContainsString('<b>' . PremiumTextsScreen::TITLE . '</b>', $this->lastSaid());
        self::assertNotContains(PremiumTextsScreen::token('🦉'), array_column($this->emojiButtons(), 1));
    }

    public function testEveryEmojiAFreshShopsTextsCarryIsNamedApartAndSwapped(): void
    {
        $rows = $this->inventory();
        $tokens = array_map(static fn(array $row): string => PremiumTextsScreen::token($row['emoji']), $rows);
        self::assertCount(count($rows), array_unique($tokens), 'no two emoji share a token');

        foreach ($rows as $row) {
            // Each tap past the chat's pace of taps (ReceivedUpdates::FLOOD_UPDATES), as an owner trying them would be.
            Carbon::setTestNow(now()->addSeconds(ReceivedUpdates::FLOOD_SECONDS + 1));
            $this->service(EmojiMap::class)->save(['items' => []]);
            $this->tapAs(Nav::to('txp', 'to', PremiumTextsScreen::token($row['emoji']), '111'));
            self::assertSame('✅ ایموجی پرمیوم ' . $row['emoji'] . ' ذخیره شد', $this->popup(), $row['emoji']);
            self::assertSame('111', $this->row($row['emoji'])['emoji_id'], $row['emoji']);
        }
    }

    public function testAHeartIsChangedInTheFormTheMapKeepsIt(): void
    {
        $this->service(BotTexts::class)->save(BotText::PhoneVerified, 'ثبت شد ❤️');
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤', 'emoji_id' => '222']]]);
        CustomEmoji::query()->create(['emoji_id' => '333', 'emoji' => '🦉']);

        $this->tapAs(Nav::to('txp', 'e', PremiumTextsScreen::token('❤')));
        self::assertStringContainsString('<b>ایموجی ❤️</b>', $this->lastSaid(), 'shown with its variation selector');
        self::assertStringContainsString('<tg-emoji emoji-id="222">❤️</tg-emoji>', $this->lastSaid());

        $this->tapAs(Nav::to('txp', 'to', PremiumTextsScreen::token('❤️'), '333'));
        self::assertSame([['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤', 'emoji_id' => '333']], $this->stored()['items'], 'its item, in its place and form');
    }

    public function testAStaleTokenOrA0102ButtonShowsTheListAndChangesNothing(): void
    {
        $this->service(BotTexts::class)->save(BotText::PhoneVerified, 'ثبت شد 🦄');
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111']]]);
        $before = $this->stored();
        $unicorn = PremiumTextsScreen::token('🦄');

        $this->tapAs(Nav::to('txp', 'e', 'deadbeef'));
        self::assertSame(PremiumTextsScreen::GONE, $this->popup());
        self::assertStringContainsString('<b>' . PremiumTextsScreen::TITLE . '</b>', $this->lastSaid());

        // The text edited meanwhile: no text carries it, nothing swapped in.
        $this->tapAs(Nav::to('txp', 'e', $unicorn));
        self::assertStringContainsString('<b>ایموجی 🦄</b>', $this->lastSaid());
        $this->service(BotTexts::class)->save(BotText::PhoneVerified, 'ثبت شد');
        foreach ([Nav::to('txp', 'e', $unicorn), Nav::to('txp', 'ch', $unicorn), Nav::to('txp', 'to', $unicorn, '222'), Nav::to('txp', 'un', $unicorn), Nav::to('txp', 'und', $unicorn)] as $tap) {
            $this->tapAs($tap);
            self::assertSame(PremiumTextsScreen::GONE, $this->popup(), $tap);
            self::assertStringContainsString('<b>' . PremiumTextsScreen::TITLE . '</b>', $this->lastSaid(), $tap);
        }

        // 0.10.2's buttons, a premium emoji's id where a token goes: the list.
        foreach ([Nav::to('txp', 'rm', '111'), Nav::to('txp', 'rmd', '111'), Nav::to('txp', 'e', '111'), Nav::to('txp', 'ch', '111'), Nav::to('txp', 'un', '111')] as $tap) {
            $this->tapAs($tap);
            self::assertStringContainsString('<b>' . PremiumTextsScreen::TITLE . '</b>', $this->lastSaid(), $tap);
            self::assertStringNotContainsString('❓', $this->lastSaid(), $tap);
        }
        self::assertSame($before, $this->stored());
    }

    public function testTheCheckGoesFromThisBotToTheChatItWasTappedInAndSaysWhyItCouldNot(): void
    {
        $this->customer(['telegram_id' => self::OTHER_OWNER]);
        $this->linkOwner(self::OTHER_OWNER);
        $this->telegramKeepsPremiumEmoji(true);

        $this->send($this->tap(Nav::to('txp', 'chk'), chat: self::OTHER_OWNER));
        $probe = $this->probe();
        self::assertNotNull($probe, 'the test message was sent');
        self::assertSame((string) self::OTHER_OWNER, $this->params($probe)['chat_id'], 'to this chat (?chat=), not the first account linked');
        self::assertSame(FakeTelegram::TOKEN, $this->telegram()->tokenOf($probe), 'by the main bot');
        self::assertStringContainsString('<tg-emoji emoji-id="222">❤️</tg-emoji>', $this->params($probe)['text'], 'the newest premium emoji the bot knows');
        $deleted = array_search('deleteMessage', $this->calls(), true);
        self::assertIsInt($deleted, 'and deleted again');
        self::assertSame([(string) self::OTHER_OWNER, '555'], [$this->params($deleted)['chat_id'], $this->params($deleted)['message_id']]);
        self::assertTrue($this->service(PremiumEmojiStatus::class)->current()['ok'] ?? null);
        self::assertSame('✅ ربات می‌تواند ایموجی پرمیوم بفرستد', $this->popup());
        self::assertStringContainsString('ربات: <b>✅ می‌تواند ایموجی پرمیوم بفرستد</b>', $this->lastSaid());

        // Telegram dropped it: found unable, and why said.
        $this->telegramKeepsPremiumEmoji(false);
        $this->tapAs(Nav::to('txp', 'chk'));
        self::assertNotNull($this->probe());
        self::assertSame((string) self::OWNER, $this->params((int) $this->probe())['chat_id']);
        self::assertFalse($this->service(PremiumEmojiStatus::class)->current()['ok'] ?? null);
        self::assertSame('⚠️ تلگرام ایموجی پرمیوم را برداشت؛ ربات هنوز نمی‌تواند', $this->popup());
        self::assertStringContainsString('ربات: <b>⚠️ نمی‌تواند ایموجی پرمیوم بفرستد</b>', $this->lastSaid());
        self::assertStringContainsString('⚠️ ' . PremiumTextsScreen::WHY, $this->lastSaid());

        // Nothing to test with: the API's words in a popup, the screen kept.
        CustomEmoji::query()->delete();
        $this->tapAs(Nav::to('txp', 'chk'));
        self::assertNull($this->probe());
        self::assertSame(PremiumCheck::NOTHING_TO_TEST, $this->popup());
        self::assertTrue($this->alerted());
        self::assertStringContainsString('<b>' . PremiumTextsScreen::TITLE . '</b>', $this->lastSaid());
        self::assertFalse($this->service(PremiumEmojiStatus::class)->current()['ok'] ?? null, 'nothing tested, nothing changed');

        // Not sent: said, and the window drawn again — what was found before stays: nothing new was found.
        CustomEmoji::query()->create(['emoji_id' => '111', 'emoji' => '✅']);
        $this->telegram()->on('sendMessage', static fn(): mixed => FakeTelegram::error(403, 'Forbidden: bot was blocked by the user'));
        $this->tapAs(Nav::to('txp', 'chk'));
        self::assertSame(PremiumTextsController::NOT_SENT, $this->popup());
        self::assertTrue($this->alerted());
        self::assertFalse($this->service(PremiumEmojiStatus::class)->current()['ok'] ?? null);
        self::assertStringContainsString('ربات: <b>⚠️ نمی‌تواند ایموجی پرمیوم بفرستد</b>', $this->lastSaid());
    }

    public function testTheCheckIsOfferedInTheShopsOwnBotAlone(): void
    {
        $shop = $this->agentBot();
        $this->telegramKeepsPremiumEmoji(true);

        $this->tapAs(Nav::to('shop', 'pick', $shop->id));
        $this->tapAs(Nav::to('txp'));
        self::assertNotContains(Nav::to('txp', 'chk'), $this->offered(), 'the agent\'s bot has no chat with the owner here');
        self::assertStringContainsString(PremiumTextsScreen::ELSEWHERE, $this->lastSaid(), 'said why instead');

        // An older window's button: refused before any request.
        $this->tapAs(Nav::to('txp', 'chk'));
        self::assertSame(PremiumTextsScreen::ELSEWHERE, $this->popup());
        self::assertNull($this->probe());
        self::assertNull(CurrentBot::run($shop, fn(): ?array => $this->service(PremiumEmojiStatus::class)->current()));
    }

    public function testTheSwitchIsRefusedUntilTheBotWasFoundAbleAndCarriesTheStateItSets(): void
    {
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111']]]);

        $this->tapAs(Nav::to('txp', 'on', 1));
        self::assertSame(EmojiMap::NOT_ABLE, $this->popup(), 'never checked: the API\'s own words');
        self::assertTrue($this->alerted());
        self::assertSame(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111']]], $this->stored());

        $this->service(PremiumEmojiStatus::class)->record(false);
        $this->tapAs(Nav::to('txp', 'on', 1));
        self::assertSame(EmojiMap::NOT_ABLE, $this->popup(), 'found unable');
        self::assertFalse($this->stored()['enabled']);

        $this->service(PremiumEmojiStatus::class)->record(true);
        $this->tapAs(Nav::to('txp', 'on', 1));
        self::assertSame(['enabled' => true, 'items' => [['emoji' => '✅', 'emoji_id' => '111']]], $this->stored(), 'on, the map as it was');
        self::assertSame('✅ ' . self::NAME . ' روشن شد', $this->popup());
        self::assertStringContainsString(self::NAME . ': <b>' . Words::ON . '</b>', $this->lastSaid());
        self::assertSame(Words::turn(false, self::NAME), $this->button(Nav::to('txp', 'on', 0))['text'] ?? null);

        $this->tapAs(Nav::to('txp', 'on', 1));
        self::assertTrue($this->stored()['enabled'], 'an older window\'s «on» never turns it off');
        self::assertSame(self::NAME . ' همین حالا روشن است', $this->popup());

        $this->tapAs(Nav::to('txp', 'on', 0));
        self::assertSame(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111']]], $this->stored());
        self::assertSame('⏸ ' . self::NAME . ' خاموش شد', $this->popup());
    }

    public function testAMapLeftOnStaysOnAsItsEmojiAreSwappedAndTakenOffThoughFoundUnableSince(): void
    {
        $this->service(BotTexts::class)->save(BotText::PhoneVerified, 'ثبت شد 🦄');
        CustomEmoji::query()->create(['emoji_id' => '333', 'emoji' => '⭐']);
        $this->service(PremiumEmojiStatus::class)->record(true);
        $this->service(EmojiMap::class)->save(['enabled' => true, 'items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤️', 'emoji_id' => '222']]]);
        $this->service(PremiumEmojiStatus::class)->record(false);

        $this->tapAs(Nav::to('txp'));
        self::assertStringContainsString(self::NAME . ': <b>' . Words::ON . '</b>', $this->lastSaid());
        self::assertStringContainsString('⚠️ ' . PremiumTextsScreen::WHY, $this->lastSaid(), 'customers see plain emoji meanwhile');
        self::assertSame(Words::turn(false, self::NAME), $this->button(Nav::to('txp', 'on', 0))['text'] ?? null, 'and it may be turned off');

        $this->tapAs(Nav::to('txp', 'to', PremiumTextsScreen::token('🦄'), '333'));
        self::assertSame(['enabled' => true, 'items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤️', 'emoji_id' => '222'], ['emoji' => '🦄', 'emoji_id' => '333']]], $this->stored(), 'the list alone sent: still on');

        $this->tapAs(Nav::to('txp', 'und', PremiumTextsScreen::token('✅')));
        self::assertSame(['enabled' => true, 'items' => [['emoji' => '❤️', 'emoji_id' => '222'], ['emoji' => '🦄', 'emoji_id' => '333']]], $this->stored());
    }

    public function testAFullMapStillChangesAnEmojisPremiumOneButSwapsNoMore(): void
    {
        $emojis = [...array_map(mb_chr(...), range(0x1F600, 0x1F64F)), ...array_map(mb_chr(...), range(0x1F680, 0x1F693))];
        self::assertCount(100, $emojis);
        // Ids as long as Telegram's: nineteen digits.
        $id = static fn(int $n): string => (string) (1_000_000_000_000_000_000 + $n);
        foreach ([...$emojis, '🛸'] as $n => $emoji) {
            CustomEmoji::query()->create(['emoji_id' => $id($n + 1), 'emoji' => $emoji]);
        }
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => array_map(static fn(int $n): array => ['emoji' => $emojis[$n], 'emoji_id' => $id($n + 1)], range(0, 99))]);
        $pages = (int) ceil(count($this->inventory()) / self::PER_PAGE);

        $this->tapAs(Nav::to('txp'));
        self::assertStringContainsString('فهرست پر است: حداکثر ۱۰۰ ایموجی.', $this->lastSaid());
        self::assertNotContains(Nav::to('txp', 'add'), $this->offered(), 'no «ایموجی دیگر» either');
        $this->assertFits();
        $this->tapAs(Nav::to('txp', 'page', $pages));
        self::assertSame([$emojis[99] . ' ' . PremiumTextsScreen::SWAPPED, PremiumTextsScreen::token($emojis[99]), (string) $pages], $this->emojiButtons()[array_key_last($this->emojiButtons())], 'the map\'s last, carried by no text: last');
        $this->assertFits();

        // One with none: nothing to pick, said; an older window's taps refused.
        $check = PremiumTextsScreen::token('✅');
        $this->tapAs(Nav::to('txp', 'e', $check));
        self::assertNotContains(Nav::to('txp', 'ch', $check), $this->offered());
        self::assertStringContainsString('فهرست پر است: حداکثر ۱۰۰ ایموجی.', $this->lastSaid());
        self::assertContains(Nav::to('txp', 'page', $pages), $this->offered(), 'back to the page last shown');
        $this->tapAs(Nav::to('txp', 'ch', $check));
        self::assertSame('فهرست پر است: حداکثر ۱۰۰ ایموجی', $this->popup());
        $this->tapAs(Nav::to('txp', 'to', $check, $id(101)));
        self::assertSame('فهرست پر است: حداکثر ۱۰۰ ایموجی', $this->popup());
        self::assertCount(100, $this->stored()['items']);

        // One with a premium emoji still changes it.
        $first = PremiumTextsScreen::token($emojis[0]);
        $this->tapAs(Nav::to('txp', 'ch', $first));
        self::assertContains(Nav::to('txp', 'to', $first, $id(101)), $this->offered(), 'the one premium emoji standing for none');
        $this->assertFits();
        $this->tapAs(Nav::to('txp', 'to', $first, $id(101)));
        self::assertCount(100, $this->stored()['items']);
        self::assertSame(['emoji' => $emojis[0], 'emoji_id' => $id(101)], $this->stored()['items'][0]);
    }

    public function testAnotherEmojiIsPickedFromALaterPageItsOwnEmojiKeptOrAnotherTyped(): void
    {
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111']]]);
        CustomEmoji::query()->where('emoji_id', '222')->delete();
        foreach (range(1, 30) as $n) {
            CustomEmoji::query()->create(['emoji_id' => (string) (1000 + $n), 'emoji' => $n === 1 ? '🔥' : '🎁']);
        }

        $this->tapAs(Nav::to('txp'));
        self::assertSame(PremiumTextsScreen::OTHER, $this->button(Nav::to('txp', 'add'))['text'] ?? null);

        $this->tapAs(Nav::to('txp', 'add'));
        self::assertStringContainsString('<b>' . PremiumTextsScreen::OTHER . '</b>', $this->lastSaid());
        self::assertStringContainsString('۳۰ ایموجی پرمیوم', $this->lastSaid(), 'the ones not in the map yet');
        self::assertContains(Nav::to('txp', 'pk', '1030'), $this->offered(), 'latest first');
        self::assertNotContains(Nav::to('txp', 'pk', '111'), $this->offered(), 'one in the map already is not offered');
        self::assertContains(Nav::to(Screens::PAGE, 'show', 1, 2), $this->offered());
        self::assertContains(Nav::to('txp', 'add', 2), $this->offered());

        $this->tapAs(Nav::to('txp', 'add', 2));
        self::assertNotContains(Nav::to('txp', 'pk', '1030'), $this->offered());
        self::assertSame('۳۰ 🔥', $this->button(Nav::to('txp', 'pk', '1001'))['text'] ?? null, 'numbered as it is drawn');
        self::assertStringContainsString('۳۰ <tg-emoji emoji-id="1001">🔥</tg-emoji>', $this->lastSaid());

        $this->tapAs(Nav::to('txp', 'pk', '1001'));
        $question = $this->lastSaid();
        self::assertStringContainsString('✍️ ایموجی معمولی', $question);
        self::assertStringContainsString(Words::CURRENT . ': <code>🔥</code>', $question, 'its own plain emoji, the value now');
        self::assertStringContainsString('<tg-emoji emoji-id="1001">🔥</tg-emoji>', $question);
        self::assertSame('✔️ همین 🔥 بماند', $this->button(Nav::to('txp', 'keep', '1001'))['text'] ?? null);
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state(), 'waiting for the plain emoji');

        // Its own kept, at a tap: its page.
        $this->tapAs(Nav::to('txp', 'keep', '1001'));
        self::assertSame(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '🔥', 'emoji_id' => '1001']]], $this->stored(), 'the whole list and it');
        self::assertSame('✅ ایموجی پرمیوم 🔥 ذخیره شد', $this->popup());
        self::assertStringContainsString('<b>ایموجی 🔥</b>', $this->lastSaid());
        self::assertStringContainsString('ایموجی پرمیوم: <b><tg-emoji emoji-id="1001">🔥</tg-emoji></b>', $this->lastSaid());

        // Another typed.
        $this->tapAs(Nav::to('txp', 'pk', '1002'));
        $this->sayAs('🦄');
        self::assertSame([['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '🔥', 'emoji_id' => '1001'], ['emoji' => '🦄', 'emoji_id' => '1002']], $this->stored()['items']);
        self::assertStringContainsString('<b>✅ ایموجی پرمیوم 🦄 ذخیره شد</b>', $this->lastSaid());
        self::assertStringContainsString('ایموجی پرمیوم: <b><tg-emoji emoji-id="1002">🦄</tg-emoji></b>', $this->lastSaid());
        self::assertStringContainsString('استفاده: <b>فعلا در هیچ متنی نیست</b>', $this->lastSaid());

        // Picked again from an older window: said, nothing asked.
        $this->tapAs(Nav::to('txp', 'pk', '1002'));
        self::assertSame(PremiumTextsScreen::ALREADY, $this->popup());
        self::assertNotSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state());
    }

    public function testARefusedPlainEmojiKeepsTheQuestionAndNothingIsLost(): void
    {
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '🎁', 'emoji_id' => '111']]]);
        CustomEmoji::query()->create(['emoji_id' => '333', 'emoji' => '🎁']);
        $before = $this->stored();

        // Typed: not one emoji — the API's words over the question, which still waits.
        $this->tapAs(Nav::to('txp', 'pk', '333'));
        $this->sayAs('قلب');
        self::assertStringContainsString('⚠️ ' . EmojiMap::NOT_EMOJI, $this->lastSaid());
        self::assertStringContainsString('✍️ ایموجی معمولی', $this->lastSaid());
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state());

        // One that has a premium emoji already: its page, to change it there; nothing saved.
        $this->sayAs('🎁');
        self::assertStringContainsString(PremiumTextsScreen::HAS_ONE, $this->lastSaid());
        self::assertStringContainsString('ایموجی 🎁', $this->lastSaid());
        self::assertNotSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state());
        self::assertSame($before, $this->stored());

        // Kept at a tap (its own plain emoji has a premium one already): the same.
        $this->tapAs(Nav::to('txp', 'pk', '333'));
        $this->tapAs(Nav::to('txp', 'keep', '333'));
        self::assertStringContainsString('ایموجی 🎁', $this->lastSaid());
        self::assertSame($before, $this->stored());

        $this->tapAs(Nav::to('txp', 'pk', '333'));
        $this->sayAs('⭐');
        self::assertSame([['emoji' => '🎁', 'emoji_id' => '111'], ['emoji' => '⭐', 'emoji_id' => '333']], $this->stored()['items']);
    }

    public function testThePickerSaysHowToShowTheBotAPremiumEmojiWhenItKnowsNoneToAdd(): void
    {
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤️', 'emoji_id' => '222']]]);

        $this->tapAs(Nav::to('txp', 'add'));
        self::assertStringContainsString('همه ایموجی‌های پرمیومی که ربات می‌شناسد در فهرست هستند', $this->lastSaid());
        self::assertContains(Nav::to('txe', 'add'), $this->offered(), '/emoji\'s own flow');

        // From an emoji's page too, back to it.
        $this->service(BotTexts::class)->save(BotText::PhoneVerified, 'ثبت شد 🦄');
        $this->tapAs(Nav::to('txp', 'ch', PremiumTextsScreen::token('🦄')));
        self::assertStringContainsString('همه ایموجی‌های پرمیومی که ربات می‌شناسد در فهرست هستند', $this->lastSaid());
        self::assertContains(Nav::to('txp', 'e', PremiumTextsScreen::token('🦄')), $this->offered());

        CustomEmoji::query()->delete();
        $this->service(EmojiMap::class)->save(['items' => []]);
        $this->tapAs(Nav::to('txp', 'add'));
        self::assertStringContainsString('ربات هنوز ایموجی پرمیومی نمی‌شناسد', $this->lastSaid());
        self::assertStringContainsString('/emoji', $this->lastSaid());
        self::assertContains(Nav::to('txe', 'add'), $this->offered());

        // Gone from the library since the picker was drawn.
        $this->tapAs(Nav::to('txp', 'pk', '999'));
        self::assertSame(PremiumTextsScreen::NOT_KNOWN, $this->popup());
        self::assertNotSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state());
    }

    public function testAnAgentSetsUpTheirOwnShopAndChecksFromTheirOwnBot(): void
    {
        $bot = $this->agentBot();
        CurrentBot::run($bot, static fn() => CustomEmoji::query()->create(['emoji_id' => '444', 'emoji' => '🎁']));
        CurrentBot::run($bot, static fn() => CustomEmoji::query()->create(['emoji_id' => '555', 'emoji' => '⭐']));
        $this->telegramKeepsPremiumEmoji(true);

        $this->sendTo($bot, $this->tap(Nav::to('txp'), chat: self::AGENT_TELEGRAM_ID));
        self::assertContains(Nav::to('txp', 'chk'), $this->offered(), 'their own bot\'s chat');

        $this->sendTo($bot, $this->tap(Nav::to('txp', 'chk'), chat: self::AGENT_TELEGRAM_ID));
        $probe = $this->probe();
        self::assertNotNull($probe);
        self::assertSame([(string) self::AGENT_TELEGRAM_ID, FakeTelegram::AGENT_TOKEN], [$this->params($probe)['chat_id'], $this->telegram()->tokenOf($probe)]);
        self::assertStringContainsString('<tg-emoji emoji-id="555">⭐</tg-emoji>', $this->params($probe)['text'], 'their shop\'s own library');
        self::assertTrue(CurrentBot::run($bot, fn(): ?bool => $this->service(PremiumEmojiStatus::class)->current()['ok'] ?? null));
        self::assertNull($this->service(PremiumEmojiStatus::class)->current(), 'the main shop\'s untouched');

        $this->sendTo($bot, $this->tap(Nav::to('txp', 'pk', '444'), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->tap(Nav::to('txp', 'keep', '444'), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->tap(Nav::to('txp', 'to', PremiumTextsScreen::token('✅'), '555'), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->tap(Nav::to('txp', 'on', 1), chat: self::AGENT_TELEGRAM_ID));
        self::assertSame(['enabled' => true, 'items' => [['emoji' => '🎁', 'emoji_id' => '444'], ['emoji' => '✅', 'emoji_id' => '555']]], $this->stored($bot));
        self::assertSame(['enabled' => false, 'items' => []], $this->stored(), 'the main shop\'s map untouched');
    }

    public function testAShopsAdminSetsItUpAsTheOwnerDoes(): void
    {
        $this->service(PremiumEmojiStatus::class)->record(true);
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('tx'), chat: 7100));
        self::assertContains(Nav::to('txp'), $this->offered());

        $this->send($this->tap(Nav::to('txp'), chat: 7100));
        self::assertStringContainsString(PremiumTextsScreen::TITLE, $this->said()[0], 'its screen, not the home');

        $this->send($this->tap(Nav::to('txp', 'on', 1), chat: 7100));
        self::assertTrue($this->stored()['enabled'], 'switched on through the panel\'s route');
        $this->send($this->tap(Nav::to('txp', 'chk'), chat: 7100));
        self::assertNotNull($this->probe(), 'the check, sent to their own chat');
    }

    /**
     * Telegram answers the check's test message as it does for a bot whose maker has Telegram Premium (`$kept`: the
     * premium emoji kept as one) or not; every other message the bot sends is answered as usual.
     */
    private function telegramKeepsPremiumEmoji(bool $kept): void
    {
        $this->telegram()->on('sendMessage', static fn(array $params): array => str_contains($params['text'] ?? '', self::PROBE)
            ? ['message_id' => 555, 'chat' => ['id' => (int) $params['chat_id'], 'type' => 'private'], 'text' => 'x', 'entities' => $kept ? [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '1']] : []]
            : ['message_id' => 556]);
    }

    /** The call since the last send() that sent the check's test message; null for none. */
    private function probe(): ?int
    {
        foreach ($this->calls() as $index => $method) {
            if ($method === 'sendMessage' && str_contains($this->params($index)['text'] ?? '', self::PROBE)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The window's button whose data is `$data` — its label and colour —, as the bot last drew it; null for none.
     *
     * @return array<string, mixed>|null
     */
    private function button(string $data): ?array
    {
        foreach (array_merge(...$this->inlineKeyboard($this->windowCall())) as $button) {
            if (($button['callback_data'] ?? null) === $data) {
                return $button;
            }
        }

        return null;
    }

    /**
     * The list's emoji buttons as the window shows them, in reading order (each row is drawn reversed): label, token
     * and the page it carries.
     *
     * @return list<array{string, string, string}>
     */
    private function emojiButtons(): array
    {
        $buttons = [];
        foreach ($this->inlineKeyboard($this->windowCall()) as $row) {
            foreach (array_reverse($row) as $button) {
                $parts = explode(':', (string) ($button['callback_data'] ?? ''));
                if (($parts[1] ?? null) === 'txp' && ($parts[2] ?? null) === 'e') {
                    $buttons[] = [(string) ($button['text'] ?? ''), $parts[3] ?? '', $parts[4] ?? ''];
                }
            }
        }

        return $buttons;
    }

    /**
     * The emoji the shop's texts carry, as the API lists them (PremiumTextsController's `emojis`).
     *
     * @return list<array{emoji: string, texts: int, menu: int, emoji_id: ?string}>
     */
    private function inventory(): array
    {
        return EmojiMap::inventory($this->service(BotTexts::class)->wordings(), $this->service(KeyboardLayouts::class)->get(KeyboardLayouts::START)->iconable(), $this->stored()['items']);
    }

    /**
     * The list's row of `$emoji` (❤ and ❤️ one).
     *
     * @return array{emoji: string, texts: int, menu: int, emoji_id: ?string}
     */
    private function row(string $emoji): array
    {
        foreach ($this->inventory() as $row) {
            if (EmojiMap::key($row['emoji']) === EmojiMap::key($emoji)) {
                return $row;
            }
        }

        self::fail("{$emoji} is not in the list.");
    }

    /** The page of the list `$emoji` is on. */
    private function listPage(string $emoji): int
    {
        $tokens = array_map(static fn(array $row): string => PremiumTextsScreen::token($row['emoji']), $this->inventory());

        return intdiv((int) array_search(PremiumTextsScreen::token($emoji), $tokens, true), self::PER_PAGE) + 1;
    }

    /** The window as Telegram takes it: at most 100 buttons, each one's data within 64 bytes, its text within 4096. */
    private function assertFits(): void
    {
        $buttons = array_merge(...$this->inlineKeyboard($this->windowCall()));
        self::assertLessThanOrEqual(100, count($buttons));
        foreach ($buttons as $button) {
            self::assertLessThanOrEqual(64, strlen((string) ($button['callback_data'] ?? '')));
        }
        self::assertLessThanOrEqual(4096, mb_strlen($this->lastSaid()));
    }

    /** Whether the tap was answered by a popup to read and dismiss (Admin::alert()), not a toast. */
    private function alerted(): bool
    {
        $index = array_search('answerCallbackQuery', $this->calls(), true);

        return $index !== false && ($this->params($index)['show_alert'] ?? null) === 'true';
    }

    /**
     * The map as the shop keeps it — the main shop's, or `$bot`'s.
     *
     * @return array{enabled: bool, items: list<array{emoji: string, emoji_id: string}>}
     */
    private function stored(?Bot $bot = null): array
    {
        return CurrentBot::run($bot ?? Bot::MAIN, fn(): array => $this->service(EmojiMap::class)->stored());
    }
}
