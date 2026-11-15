<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;

/**
 * «⌨️ کیبوردها» from the bot (Sections\Bot\Keyboards): the start menu laid out as the customer sees it, a button moved
 * along its row and down a row, its text typed (a premium emoji in it lifted to its icon), its colour and its action
 * changed, removed and added back, the rows moved and removed, the kind switched and the built-in layout back — each
 * saved at once through the panel's route, the whole layout.
 */
final class BotAreaKeyboardsTest extends BotTestCase
{
    private const OWNER = 7001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->customer(['telegram_id' => self::OWNER, 'role' => UserRole::Admin]);
        $this->linkOwner(self::OWNER);
        $this->inlineStartMenu([
            [['action' => 'buy', 'label' => 'خرید'], ['action' => 'services', 'label' => 'سرویس‌ها']],
            [['action' => 'wallet', 'label' => 'کیف پول']],
        ]);
    }

    public function testAButtonIsMovedRetextedRecolouredAndRemoved(): void
    {
        $this->send($this->tap(Nav::to('kb'), chat: self::OWNER));
        self::assertContains(Nav::to('kbb', 'show', 'start', 'buy'), $this->callbacks(0), 'the one keyboard opened straight away');

        $this->send($this->tap(Nav::to('kbb', 'show', 'start', 'buy'), chat: self::OWNER));
        self::assertStringContainsString('ردیف ۱، دکمه ۱ از ۲', $this->said()[0]);
        self::assertContains(Nav::to('kbb', 'mv', 'start', 'buy', 'l'), $this->callbacks(0));
        self::assertNotContains(Nav::to('kbb', 'mv', 'start', 'buy', 'r'), $this->callbacks(0), 'the first of its row: no further right');
        self::assertNotContains(Nav::to('kbb', 'mv', 'start', 'buy', 'u'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('kbb', 'mv', 'start', 'buy', 'l'), chat: self::OWNER));
        self::assertSame([['services', 'buy'], ['wallet']], $this->actions());
        $this->send($this->tap(Nav::to('kbb', 'mv', 'start', 'buy', 'd'), chat: self::OWNER));
        self::assertSame([['services'], ['wallet', 'buy']], $this->actions());
        self::assertStringContainsString('ردیف ۲، دکمه ۲ از ۲', $this->said()[0], 'the button, where it now is');

        $this->send($this->tap(Nav::to('kbb', 'lb', 'start', 'buy'), chat: self::OWNER));
        $this->send($this->message('🔥 خرید ویژه', ['entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '111']]], chat: self::OWNER));
        $button = $this->layout()['rows'][1][1];
        self::assertSame(['خرید ویژه', '111'], [$button['label'], $button['icon']], 'the premium emoji lifted to its icon');

        $this->send($this->tap(Nav::to('kbb', 'sts', 'start', 'buy', 'success'), chat: self::OWNER));
        self::assertSame('success', $this->layout()['rows'][1][1]['style']);

        $this->send($this->tap(Nav::to('kbb', 'acs', 'start', 'buy', 'support'), chat: self::OWNER));
        self::assertSame(['support', 'خرید ویژه'], [$this->layout()['rows'][1][1]['action'], $this->layout()['rows'][1][1]['label']], 'a typed text stays with its button');

        $this->send($this->tap(Nav::to('kbb', 'del', 'start', 'support'), chat: self::OWNER));
        self::assertContains(Nav::to('kbb', 'deld', 'start', 'support'), $this->callbacks(0), 'asked first');
        $this->send($this->tap(Nav::to('kbb', 'deld', 'start', 'support'), chat: self::OWNER));
        self::assertSame([['services'], ['wallet']], $this->actions());
    }

    public function testButtonsAreAddedRowsMovedAndTheLayoutPutBack(): void
    {
        $this->send($this->tap(Nav::to('kbl', 'add', 'start'), chat: self::OWNER));
        self::assertContains(Nav::to('kbl', 'addr', 'start', 2), $this->callbacks(0), 'a new row too');
        $this->send($this->tap(Nav::to('kbl', 'addr', 'start', 1), chat: self::OWNER));
        self::assertNotContains(Nav::to('kbl', 'adda', 'start', 1, 'buy'), $this->callbacks(0), 'each button once');
        $this->send($this->tap(Nav::to('kbl', 'adda', 'start', 1, 'support'), chat: self::OWNER));
        self::assertSame([['buy', 'services'], ['wallet', 'support']], $this->actions());

        $this->send($this->tap(Nav::to('kbl', 'mr', 'start', 0, 'd'), chat: self::OWNER));
        self::assertSame([['wallet', 'support'], ['buy', 'services']], $this->actions());
        $this->send($this->tap(Nav::to('kbl', 'dr', 'start', 0), chat: self::OWNER));
        self::assertContains(Nav::to('kbl', 'drd', 'start', 0, 'wallet'), $this->callbacks(0), 'asked first, about this row');
        $this->send($this->tap(Nav::to('kbl', 'drd', 'start', 0, 'wallet'), chat: self::OWNER));
        self::assertSame([['buy', 'services']], $this->actions());
        $this->send($this->tap(Nav::to('kbl', 'drd', 'start', 0, 'wallet'), chat: self::OWNER));
        self::assertSame([['buy', 'services']], $this->actions(), 'a second «yes»: the row now there is another, kept');
        self::assertStringContainsString('عوض شده است', (string) $this->popup());

        $this->send($this->tap(Nav::to('kbl', 'kind', 'start', 'reply'), chat: self::OWNER));
        self::assertSame('reply', $this->layout()['type']);

        $this->send($this->tap(Nav::to('kbl', 'rs', 'start'), chat: self::OWNER));
        self::assertContains(Nav::to('kbl', 'rsd', 'start'), $this->callbacks(0), 'asked first');
        $this->send($this->tap(Nav::to('kbl', 'rsd', 'start'), chat: self::OWNER));
        self::assertTrue($this->service(KeyboardLayouts::class)->isDefault('start'));
    }

    public function testAButtonsNewTextMayBeAMenuLabelItsIconNot(): void
    {
        $this->send($this->tap(Nav::to('kbb', 'lb', 'start', 'buy'), chat: self::OWNER));
        self::assertSame(Admin::INPUT_ANY, $this->service(SessionStore::class)->load(self::OWNER)->state(), 'a new label may be any words, a menu label\'s too');
        $this->send($this->tap(Nav::to('kbb', 'ic', 'start', 'buy'), chat: self::OWNER));
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state(), 'an icon is no words: a menu button leaves it');
    }

    public function testATextThePanelRefusesIsSaidAndNothingKept(): void
    {
        $this->send($this->tap(Nav::to('kbb', 'lb', 'start', 'buy'), chat: self::OWNER));
        $this->send($this->message(str_repeat('خرید ', 14), chat: self::OWNER));
        self::assertStringContainsString('متن دکمه حداکثر 64 کاراکتر است', $this->said()[0]);
        self::assertSame('خرید', $this->layout()['rows'][0][0]['label']);
    }

    /** @return array{type: string, rows: list<list<array{action: string, label: string, style: string|null, icon: string|null}>>} */
    private function layout(): array
    {
        return $this->service(KeyboardLayouts::class)->get('start')->toArray();
    }

    /** @return list<list<string>> The start menu's actions, row by row. */
    private function actions(): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'action'), $this->layout()['rows']);
    }
}
