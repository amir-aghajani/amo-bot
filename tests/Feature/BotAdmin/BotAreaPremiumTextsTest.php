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
use App\Modules\Telegram\Models\CustomEmoji;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * «💎 ایموجی پرمیوم متن‌ها» from the bot (Sections\Bot\Texts\PremiumTextsScreen): whether the bot may send premium emoji
 * — able, unable, never checked —; the check, sent by this chat's bot to this very chat and offered in the shop's own
 * bot alone, its refusals said; the switch, refused in the API's words until the bot was found able, carrying the state
 * it sets; a premium emoji added from the library — a later page of it —, its own plain emoji kept or another typed, a
 * refused one asking again; one removed, asked first, an older window's tap acting on that item or saying it is gone; an
 * agent's own shop from their own bot; and a seat that does not set the shop up kept out.
 */
final class BotAreaPremiumTextsTest extends BotTestCase
{
    /** A second Telegram account linked as the owner's: the check must go to the chat tapped from, not the first linked. */
    private const OTHER_OWNER = 7002;

    private const NAME = 'ایموجی پرمیوم متن‌ها';

    /** Words of the check's test message alone (PremiumCheck), which tell it from the bot's other messages. */
    private const PROBE = 'این پیام آزمایشی است';

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
        self::assertStringContainsString('فهرست: <b>۱ ایموجی از ۱۰۰</b>', $screen);
        $uses = EmojiMap::uses(['✅'], $this->service(BotTexts::class)->wordings())['✅'];
        self::assertGreaterThan(0, $uses);
        self::assertStringContainsString('✅ ← <tg-emoji emoji-id="111">✅</tg-emoji> · در ' . Fmt::number($uses) . ' متن', $screen, 'each item drawn premium beside its plain emoji, with the texts it is in');
        self::assertStringContainsString('خاموش است: تا روشن نشود', $screen);
        self::assertSame(PremiumTextsScreen::LOCKED, $this->button(Nav::to('txp', 'on', 1))['text'] ?? null, 'the switch says what it waits for');
        self::assertSame('🗑 ✅', $this->button(Nav::to('txp', 'rm', '111'))['text'] ?? null);
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

    public function testAMapLeftOnStaysOnAsItsEmojiAreAddedAndRemovedThoughFoundUnableSince(): void
    {
        CustomEmoji::query()->create(['emoji_id' => '333', 'emoji' => '⭐']);
        $this->service(PremiumEmojiStatus::class)->record(true);
        $this->service(EmojiMap::class)->save(['enabled' => true, 'items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤️', 'emoji_id' => '222']]]);
        $this->service(PremiumEmojiStatus::class)->record(false);

        $this->tapAs(Nav::to('txp'));
        self::assertStringContainsString(self::NAME . ': <b>' . Words::ON . '</b>', $this->lastSaid());
        self::assertStringContainsString('⚠️ ' . PremiumTextsScreen::WHY, $this->lastSaid(), 'customers see plain emoji meanwhile');
        self::assertSame(Words::turn(false, self::NAME), $this->button(Nav::to('txp', 'on', 0))['text'] ?? null, 'and it may be turned off');

        $this->tapAs(Nav::to('txp', 'pk', '333'));
        $this->tapAs(Nav::to('txp', 'keep', '333'));
        self::assertSame(['enabled' => true, 'items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤️', 'emoji_id' => '222'], ['emoji' => '⭐', 'emoji_id' => '333']]], $this->stored(), 'the list alone sent: still on');

        $this->tapAs(Nav::to('txp', 'rmd', '111'));
        self::assertSame(['enabled' => true, 'items' => [['emoji' => '❤️', 'emoji_id' => '222'], ['emoji' => '⭐', 'emoji_id' => '333']]], $this->stored());
    }

    public function testTheMapIsPagedItsLinesWithItsButtonsAndAnAddedEmojiShowsOnItsPage(): void
    {
        $emojis = [...array_map(mb_chr(...), range(0x1F600, 0x1F64F)), ...array_map(mb_chr(...), range(0x1F680, 0x1F693))];
        self::assertCount(100, $emojis);
        // Ids as long as Telegram's: nineteen digits.
        $id = static fn(int $n): string => (string) (1_000_000_000_000_000_000 + $n);
        foreach ($emojis as $n => $emoji) {
            CustomEmoji::query()->create(['emoji_id' => $id($n + 1), 'emoji' => $emoji]);
        }
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => array_map(static fn(int $n): array => ['emoji' => $emojis[$n], 'emoji_id' => $id($n + 1)], range(0, 98))]);

        $this->tapAs(Nav::to('txp'));
        self::assertContains(Nav::to(Screens::PAGE, 'show', 1, 5), $this->offered());
        self::assertContains(Nav::to('txp', 'page', 2), $this->offered());
        self::assertContains(Nav::to('txp', 'rm', $id(20)), $this->offered());
        self::assertNotContains(Nav::to('txp', 'rm', $id(21)), $this->offered(), 'twenty a page');
        self::assertStringNotContainsString('emoji-id="' . $id(21) . '"', $this->lastSaid(), 'its lines paged with its buttons');
        $this->assertFits();

        $this->tapAs(Nav::to('txp', 'page', 2));
        self::assertContains(Nav::to('txp', 'rm', $id(21)), $this->offered());
        self::assertStringContainsString($emojis[20] . ' ← <tg-emoji emoji-id="' . $id(21) . '">' . $emojis[20] . '</tg-emoji>', $this->lastSaid());
        $this->assertFits();

        $this->tapAs(Nav::to('txp', 'page', 1));
        $this->tapAs(Nav::to('txp', 'pk', $id(100)));
        $this->tapAs(Nav::to('txp', 'keep', $id(100)));
        self::assertCount(100, $this->stored()['items']);
        self::assertSame(['emoji' => $emojis[99], 'emoji_id' => $id(100)], $this->stored()['items'][99]);
        self::assertContains(Nav::to('txp', 'rm', $id(100)), $this->offered(), 'shown on its page: the last');
        self::assertContains(Nav::to(Screens::PAGE, 'show', 5, 5), $this->offered());
        self::assertNotContains(Nav::to('txp', 'add'), $this->offered(), 'full: no more to add');
        self::assertStringContainsString('فهرست پر است: حداکثر ۱۰۰ ایموجی.', $this->lastSaid());
        $this->assertFits();
    }

    public function testAPremiumEmojiIsPickedFromALaterPageItsOwnEmojiKeptOrAnotherTyped(): void
    {
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111']]]);
        CustomEmoji::query()->where('emoji_id', '222')->delete();
        foreach (range(1, 30) as $n) {
            CustomEmoji::query()->create(['emoji_id' => (string) (1000 + $n), 'emoji' => $n === 1 ? '🔥' : '🎁']);
        }

        $this->tapAs(Nav::to('txp', 'add'));
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

        // Its own kept, at a tap.
        $this->tapAs(Nav::to('txp', 'keep', '1001'));
        self::assertSame(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '🔥', 'emoji_id' => '1001']]], $this->stored(), 'the whole list and it');
        self::assertSame('✅ 🔥 به فهرست اضافه شد', $this->popup());
        self::assertStringContainsString('🔥 ← <tg-emoji emoji-id="1001">🔥</tg-emoji>', $this->lastSaid());

        // Another typed.
        $this->tapAs(Nav::to('txp', 'pk', '1002'));
        $this->sayAs('❤️');
        self::assertSame([['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '🔥', 'emoji_id' => '1001'], ['emoji' => '❤️', 'emoji_id' => '1002']], $this->stored()['items']);
        self::assertStringContainsString('<b>✅ ❤️ به فهرست اضافه شد</b>', $this->lastSaid());
        self::assertStringContainsString('❤️ ← <tg-emoji emoji-id="1002">❤️</tg-emoji>', $this->lastSaid());

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

        // Typed: not one emoji, then one the map has — the API's words over the question, which still waits.
        $this->tapAs(Nav::to('txp', 'pk', '333'));
        $this->sayAs('قلب');
        self::assertStringContainsString('⚠️ ' . EmojiMap::NOT_EMOJI, $this->lastSaid());
        self::assertStringContainsString('✍️ ایموجی معمولی', $this->lastSaid());
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state());
        $this->sayAs('🎁');
        self::assertStringContainsString('⚠️ ' . EmojiMap::TWICE_EMOJI, $this->lastSaid());
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state());
        self::assertSame($before, $this->stored());

        // Kept at a tap, refused (its own plain emoji has a premium one already): said, and asked again.
        $this->tapAs(Nav::to('txp', 'keep', '333'));
        self::assertSame(EmojiMap::TWICE_EMOJI, $this->popup());
        self::assertTrue($this->alerted());
        self::assertStringContainsString('✍️ ایموجی معمولی', $this->lastSaid());
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state(), 'the question waits again');
        self::assertSame($before, $this->stored());

        $this->sayAs('⭐');
        self::assertSame([['emoji' => '🎁', 'emoji_id' => '111'], ['emoji' => '⭐', 'emoji_id' => '333']], $this->stored()['items']);
    }

    public function testThePickerSaysHowToShowTheBotAPremiumEmojiWhenItKnowsNoneToAdd(): void
    {
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤️', 'emoji_id' => '222']]]);

        $this->tapAs(Nav::to('txp', 'add'));
        self::assertStringContainsString('همه ایموجی‌های پرمیومی که ربات می‌شناسد در فهرست هستند', $this->lastSaid());
        self::assertContains(Nav::to('txe', 'add'), $this->offered(), '/emoji\'s own flow');

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

    public function testARemovalIsAskedFirstAndAnOlderWindowsTapActsOnItsItemOrSaysItIsGone(): void
    {
        CustomEmoji::query()->create(['emoji_id' => '333', 'emoji' => '⭐']);
        $this->service(EmojiMap::class)->save(['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤️', 'emoji_id' => '222']]]);

        $this->tapAs(Nav::to('txp'));
        self::assertSame('🗑 ❤️', $this->button(Nav::to('txp', 'rm', '222'))['text'] ?? null);

        $this->tapAs(Nav::to('txp', 'rm', '222'));
        self::assertCount(2, $this->stored()['items'], 'asked first');
        self::assertStringContainsString('❓ این ایموجی از فهرست حذف شود؟', $this->lastSaid());
        self::assertStringContainsString('❤️ ← <tg-emoji emoji-id="222">❤️</tg-emoji>', $this->lastSaid());
        $yes = $this->button(Nav::to('txp', 'rmd', '222'));
        self::assertSame([Words::yes('حذف شود', '🗑'), 'danger'], [$yes['text'] ?? null, $yes['style'] ?? null]);
        self::assertContains(Nav::to('txp'), $this->offered(), '«✖️ انصراف»');

        // The panel changed the map meanwhile: the tap still takes off that item, the list as the panel has it now.
        $this->service(EmojiMap::class)->save(['items' => [['emoji' => '❤️', 'emoji_id' => '222'], ['emoji' => '⭐', 'emoji_id' => '333'], ['emoji' => '✅', 'emoji_id' => '111']]]);
        $this->tapAs(Nav::to('txp', 'rmd', '222'));
        self::assertSame(['enabled' => false, 'items' => [['emoji' => '⭐', 'emoji_id' => '333'], ['emoji' => '✅', 'emoji_id' => '111']]], $this->stored());
        self::assertSame('🗑 ❤️ از فهرست حذف شد', $this->popup());
        self::assertStringNotContainsString('emoji-id="222"', $this->lastSaid());

        // Gone already: said, nothing asked or changed.
        $this->tapAs(Nav::to('txp', 'rmd', '222'));
        self::assertSame(PremiumTextsScreen::GONE, $this->popup());
        self::assertCount(2, $this->stored()['items']);
        $this->tapAs(Nav::to('txp', 'rm', '222'));
        self::assertSame(PremiumTextsScreen::GONE, $this->popup());
        self::assertStringNotContainsString('❓', $this->lastSaid());
    }

    public function testAnAgentSetsUpTheirOwnShopAndChecksFromTheirOwnBot(): void
    {
        $bot = $this->agentBot();
        CurrentBot::run($bot, static fn() => CustomEmoji::query()->create(['emoji_id' => '444', 'emoji' => '🎁']));
        $this->telegramKeepsPremiumEmoji(true);

        $this->sendTo($bot, $this->tap(Nav::to('txp'), chat: self::AGENT_TELEGRAM_ID));
        self::assertContains(Nav::to('txp', 'chk'), $this->offered(), 'their own bot\'s chat');

        $this->sendTo($bot, $this->tap(Nav::to('txp', 'chk'), chat: self::AGENT_TELEGRAM_ID));
        $probe = $this->probe();
        self::assertNotNull($probe);
        self::assertSame([(string) self::AGENT_TELEGRAM_ID, FakeTelegram::AGENT_TOKEN], [$this->params($probe)['chat_id'], $this->telegram()->tokenOf($probe)]);
        self::assertStringContainsString('<tg-emoji emoji-id="444">🎁</tg-emoji>', $this->params($probe)['text'], 'their shop\'s own library');
        self::assertTrue(CurrentBot::run($bot, fn(): ?bool => $this->service(PremiumEmojiStatus::class)->current()['ok'] ?? null));
        self::assertNull($this->service(PremiumEmojiStatus::class)->current(), 'the main shop\'s untouched');

        $this->sendTo($bot, $this->tap(Nav::to('txp', 'pk', '444'), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->tap(Nav::to('txp', 'keep', '444'), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->tap(Nav::to('txp', 'on', 1), chat: self::AGENT_TELEGRAM_ID));
        self::assertSame(['enabled' => true, 'items' => [['emoji' => '🎁', 'emoji_id' => '444']]], $this->stored($bot));
        self::assertSame(['enabled' => false, 'items' => []], $this->stored(), 'the main shop\'s map untouched');
    }

    public function testASeatThatDoesNotSetTheShopUpIsKeptOut(): void
    {
        $this->service(PremiumEmojiStatus::class)->record(true);
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('tx'), chat: 7100));
        self::assertNotContains(Nav::to('txp'), $this->offered());

        $this->send($this->tap(Nav::to('txp'), chat: 7100));
        self::assertStringContainsString(Words::OLD_BUTTON, $this->said()[0], 'the home, as an older window\'s button');
        self::assertStringNotContainsString(PremiumTextsScreen::TITLE, $this->said()[0]);

        $this->send($this->tap(Nav::to('txp', 'on', 1), chat: 7100));
        $this->send($this->tap(Nav::to('txp', 'chk'), chat: 7100));
        self::assertSame(['enabled' => false, 'items' => []], $this->stored(), 'a forged tap changes nothing');
        self::assertNull($this->probe());
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
