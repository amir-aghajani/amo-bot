<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Emoji\CustomEmojis;
use App\Modules\Telegram\Handlers\CustomEmojiHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\CustomEmoji;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;

/**
 * «📝 متن‌های ربات» from the bot (Sections\Bot\Texts): the groups and the panel's tabs and search, a text reworded by a
 * message — its formatting and premium emoji kept the way the panel keeps them, a button's first premium emoji its icon
 * —, or by its HTML code, refused by the panel's rules (the question waiting), previewed and reset; and the premium
 * emoji the bot knows, one taken off the list, more added through the bot's own /emoji.
 */
final class BotAreaTextsTest extends BotTestCase
{
    /** The owner's account in these tests: the bot's admin, as an agent is in their own bot. */
    private const ADMIN = self::CHAT;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin(['telegram_id' => self::ADMIN]);
    }

    public function testATextIsRewordedByAMessageItsFormattingKept(): void
    {
        $this->asOwner();

        $this->send($this->tap(Nav::to('tx')));
        self::assertContains(Nav::to('txg', 'show', 'general'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('txg', 'show', 'general')));
        self::assertContains(Nav::to('txv', 'show', 'welcome'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('txv', 'show', 'welcome')));
        self::assertStringContainsString('خوش‌آمدگویی', $this->said()[0]);
        self::assertStringContainsString('سلام %name%', $this->said()[0], 'the wording as the panel keeps it');

        $this->send($this->tap(Nav::to('txv', 'e', 'welcome')));
        self::assertStringContainsString('%name%', $this->said()[0], 'its variables, where the new wording is asked for');
        $this->send($this->message('سلام %name% عزیز', ['entities' => [['type' => 'bold', 'offset' => 0, 'length' => 4]]]));
        self::assertSame('<b>سلام</b> %name% عزیز', $this->service(BotTexts::class)->present(BotText::Welcome)['value']);
        self::assertStringContainsString('متن ذخیره شد', $this->said()[0]);

        $this->send($this->tap(Nav::to('tx', 'f', 'changed')));
        self::assertContains(Nav::to('txg', 'show', 'general'), $this->callbacks(0), 'the group, under «تغییر یافته»');

        $this->send($this->tap(Nav::to('txv', 'pv', 'welcome')));
        self::assertContains('<b>سلام</b> امیر عزیز', array_column(array_filter($this->telegram()->history, static fn(array $call): bool => $call['method'] === 'sendMessage'), 'params')[0] ?? [], 'the preview, its sample in');

        $this->send($this->tap(Nav::to('txv', 'rs', 'welcome')));
        self::assertContains(Nav::to('txv', 'rsd', 'welcome'), $this->callbacks(0), 'asked first');
        $this->send($this->tap(Nav::to('txv', 'rsd', 'welcome')));
        self::assertFalse($this->service(BotTexts::class)->isCustomized(BotText::Welcome));
    }

    public function testAButtonsPremiumEmojiIsItsIconAndAPopupShowsAsOne(): void
    {
        $this->asOwner();

        $this->send($this->tap(Nav::to('txv', 'e', 'cancel')));
        $this->send($this->message('🔥 بیخیال', ['entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '111']]]));
        self::assertSame('<tg-emoji emoji-id="111">🔥</tg-emoji> بیخیال', $this->service(BotTexts::class)->present(BotText::Cancel)['value'], 'as the panel\'s picker writes it');

        $this->send($this->tap(Nav::to('txv', 'e', 'banned')));
        $this->send($this->message('<حساب> شما بسته است & بس'));
        self::assertSame('<حساب> شما بسته است & بس', $this->service(BotTexts::class)->present(BotText::Banned)['value'], 'a popup as typed: nothing escaped');

        $this->send($this->tap(Nav::to('txv', 'pv', 'banned')));
        self::assertSame('<حساب> شما بسته است & بس', $this->popup(), 'previewed as the popup itself');
    }

    public function testHtmlCodeTheRulesRefuseKeepsTheQuestion(): void
    {
        $this->asOwner();

        $this->send($this->tap(Nav::to('txv', 'h', 'welcome')));
        $this->send($this->message('<b>سلام %name%'));
        self::assertStringStartsWith('<b>⚠️', $this->said()[0], 'the panel\'s refusal over the question');
        self::assertSame(Admin::INPUT_ANY, $this->service(SessionStore::class)->load(self::ADMIN)->state(), 'which still waits');
        self::assertFalse($this->service(BotTexts::class)->isCustomized(BotText::Welcome));

        $this->send($this->message('<b>سلام</b> %name%'));
        self::assertSame('<b>سلام</b> %name%', $this->service(BotTexts::class)->present(BotText::Welcome)['value']);
    }

    public function testANewWordingMayBeAMenuLabelAndThePartKeepsItsSpacing(): void
    {
        $this->asOwner();

        // The menu's reply keyboard stays under the question: its label's words are the answer, not a way out.
        $this->send($this->tap(Nav::to('txv', 'e', 'welcome')));
        $this->send($this->message(Messages::MENU_SUPPORT));
        self::assertSame(Messages::MENU_SUPPORT, $this->service(BotTexts::class)->present(BotText::Welcome)['value']);

        // The code edit shows the code now to copy, and a part keeps the blank lines above it as «✏️» does.
        $this->service(BotTexts::class)->save(BotText::PlanFirstPurchase, "\n\n🎁 <b>قدیمی</b> %regular%");
        $this->send($this->tap(Nav::to('txv', 'h', 'plan_first_purchase')));
        self::assertStringContainsString('<code>🎁 &lt;b&gt;قدیمی&lt;/b&gt; %regular%</code>', $this->said()[0], 'the current code, copyable and escaped');
        $this->send($this->message('🎁 <i>تازه</i> %regular%'));
        self::assertSame("\n\n🎁 <i>تازه</i> %regular%", $this->service(BotTexts::class)->present(BotText::PlanFirstPurchase)['value']);
    }

    public function testAButtonsPreviewLeavesOneWindow(): void
    {
        $this->asOwner();

        $this->send($this->tap(Nav::to('txv', 'pv', 'cancel'), message: ['message_id' => 501]));
        $preview = array_search('sendMessage', $this->calls(), true);
        self::assertIsInt($preview);
        self::assertSame([Nav::to('txv', 'pvd', 'cancel', 501)], $this->callbacks($preview), 'the button knows the window it was asked from');

        $this->send($this->tap(Nav::to('txv', 'pvd', 'cancel', 501), message: ['message_id' => 502]));
        $deleted = array_search('deleteMessage', $this->calls(), true);
        self::assertIsInt($deleted);
        self::assertSame('501', (string) $this->params($deleted)['message_id'], 'that window goes; the preview\'s message is the window now');
        self::assertContains(Nav::to('txv', 'e', 'cancel'), $this->callbacks((int) array_search('editMessageText', $this->calls(), true)));
    }

    public function testTheSearchFindsTextsByTheirWords(): void
    {
        $this->asOwner();

        $this->send($this->tap(Nav::to('tx', 'search')));
        $this->send($this->message('خوش آمدگویی'));
        self::assertNotContains(Nav::to('txv', 'show', 'welcome'), $this->buttons(), 'a space is no half-space');
        $this->send($this->tap(Nav::to('tx', 'search')));
        $this->send($this->message('خوشآمدگویی'));
        self::assertContains(Nav::to('txv', 'show', 'welcome'), $this->buttons(), 'the half-space ignored');
    }

    public function testThePremiumEmojiListAndTheBotsOwnEmojiFlow(): void
    {
        $this->asOwner();
        $this->telegram()->on('getCustomEmojiStickers', static fn(array $params): array => array_map(
            static fn(string $id): array => ['file_id' => "sticker-{$id}", 'type' => 'custom_emoji', 'custom_emoji_id' => $id, 'emoji' => '🎁', 'is_animated' => false, 'is_video' => false],
            json_decode($params['custom_emoji_ids'], true),
        ));
        $this->service(CustomEmojis::class)->remember([['id' => '222', 'emoji' => '🎁']]);

        $this->send($this->tap(Nav::to('txe')));
        self::assertStringContainsString('<tg-emoji emoji-id="222">🎁</tg-emoji>', $this->said()[0]);
        $this->send($this->tap(Nav::to('txe', 'del', '222', 1)));
        self::assertSame(1, CustomEmoji::query()->count(), 'asked first');
        self::assertContains(Nav::to('txe', 'deld', '222', 1), $this->callbacks(0));
        $this->send($this->tap(Nav::to('txe', 'deld', '222', 1)));
        self::assertSame(0, CustomEmoji::query()->count(), 'taken off through the panel\'s route');

        $this->send($this->tap(Nav::to('txe', 'add')));
        self::assertStringContainsString(Messages::EMOJI_ASK, $this->said()[0]);
        $this->send($this->message('🎁 هدیه', ['entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '333']]]));
        self::assertSame('333', CustomEmoji::query()->sole()->emoji_id, '/emoji\'s own flow kept it');
        $edit = array_search('editMessageText', $this->calls(), true);
        self::assertIsInt($edit, 'the window it was asked from, answered');
        self::assertSame('77', (string) $this->params($edit)['message_id']);
        self::assertSame([Nav::to('txe')], $this->callbacks($edit), '«⚙️ بازگشت به مدیریت» in place of «✖️ انصراف»');
    }

    public function testTheEmojiListKeepsItsPageAndIsAddedToInItsOwnBotAlone(): void
    {
        $this->asOwner();
        $this->telegram()->on('getCustomEmojiStickers', static fn(array $params): array => array_map(
            static fn(string $id): array => ['file_id' => "sticker-{$id}", 'type' => 'custom_emoji', 'custom_emoji_id' => $id, 'emoji' => '🎁', 'is_animated' => false, 'is_video' => false],
            json_decode($params['custom_emoji_ids'], true),
        ));
        $this->service(CustomEmojis::class)->remember(array_map(static fn(int $n): array => ['id' => (string) (1000 + $n), 'emoji' => '🎁'], range(1, 30)));

        $this->send($this->tap(Nav::to('txe', 'page', 2)));
        self::assertContains(Nav::to(Screens::PAGE, 'show', 2, 2), $this->callbacks(0), 'the pages\' «n / m»: page 2 of 2');
        $tile = array_values(array_filter($this->callbacks(0), static fn(string $data): bool => str_starts_with($data, 'a:txe:del:')))[0] ?? '';
        self::assertStringEndsWith(':2', $tile, 'a tile carries its page');
        $this->send($this->tap(str_replace(':del:', ':deld:', $tile)));
        self::assertSame(29, CustomEmoji::query()->count());
        self::assertContains(Nav::to('txe', 'page', 1), $this->buttons(), 'still on page 2 after the delete');

        $this->send($this->tap(Nav::to('shop', 'pick', $this->agentBot()->id)));
        $this->send($this->tap(Nav::to('txe')));
        self::assertNotContains(Nav::to('txe', 'add'), $this->callbacks(0), 'an emoji sent here is this bot\'s, not the agent shop\'s');
        $this->send($this->tap(Nav::to('txe', 'add')));
        self::assertNotSame(CustomEmojiHandler::STATE, $this->service(SessionStore::class)->load(self::ADMIN)->state());
    }

    public function testAShopsAdminHasNoTexts(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('txv', 'e', 'welcome'), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0]);
        self::assertNotSame(Admin::INPUT, $this->service(SessionStore::class)->load(7100)->state());
    }

    /** @return list<string> The buttons of the window as the bot last drew it. */
    private function buttons(): array
    {
        for ($call = count($this->calls()) - 1; $call >= 0; $call--) {
            if ($this->callbacks($call) !== []) {
                return $this->callbacks($call);
            }
        }

        return [];
    }

    /** The test's admin linked as the owner of the main bot. */
    private function asOwner(): void
    {
        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->linkOwner(self::ADMIN);
    }
}
