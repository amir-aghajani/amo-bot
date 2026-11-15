<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Auth\Actor;
use App\Modules\Telegram\Admin\BotAdminSettings;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\AdminHandler;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Handlers\StartHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserActions;
use Tests\BotTestCase;

/**
 * The shop's management in the bot's private chat (Telegram\Admin): the owner's one-time link, «⚙️ مدیریت» for whoever
 * manages the shop and nobody else, its home and menus by what the seat may open, and a settings card saved through
 * the panel's own route.
 */
final class BotAdminFlowTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnersLinkMakesTheAccountTheOwnerOnce(): void
    {
        $owner = $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $link = $this->service(OwnerLinks::class)->link();
        self::assertNotNull($link);
        $payload = substr($link['url'], (int) strpos($link['url'], '=') + 1);

        $this->send($this->message('/start ' . $payload, chat: 7001));

        self::assertContains(StartHandler::OWNER_LINKED, $this->said());
        self::assertTrue($this->service(OwnerLinks::class)->isOwner(7001));
        self::assertSame(UserRole::Admin, $owner->refresh()->role, 'the bot\'s admin too: its gates never stop them');
        self::assertContains([AdminHandler::LABEL], $this->replyKeyboard(0), 'the menu has «⚙️ مدیریت»');

        $this->send($this->message('/start ' . $payload, chat: 7002));
        self::assertContains(StartHandler::OWNER_LINK_DEAD, $this->said(), 'used once');
        self::assertFalse($this->service(OwnerLinks::class)->isOwner(7002));
    }

    public function testTheOwnerOpensEverySectionAndSavesASettingsCard(): void
    {
        $this->owner();

        $this->send($this->message('/admin', chat: 7001));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0]);
        self::assertStringContainsString('👑 مالک', $this->said()[0]);
        self::assertContains(Nav::to('hc'), $this->callbacks(0), 'the settings menu');
        self::assertContains(Nav::to('shop'), $this->callbacks(0), 'the owner picks a shop');

        $this->send($this->tap(Nav::to('hc'), chat: 7001));
        self::assertContains(Nav::to('ba'), $this->callbacks(0));
        self::assertContains(Nav::to('ol'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('ba'), chat: 7001));
        self::assertStringContainsString('مدیران ربات', $this->said()[0]);
        self::assertContains(Nav::to('ba', 'e', 1, 1), $this->callbacks(0), 'a grant, shown while the admins are let in');

        $this->send($this->tap(Nav::to('ba', 'e', 1, 1), chat: 7001));
        self::assertSame(['catalog'], $this->service(BotAdminSettings::class)->grants(), 'saved through the panel\'s route');
        self::assertStringContainsString('ذخیره شد', (string) $this->popup());
    }

    public function testAShopsAdminHasTheDailyWorkAndNoSettings(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->message('/admin', chat: 7100));

        self::assertStringContainsString('🛡 مدیر ربات', $this->said()[0]);
        self::assertNotContains(Nav::to('hc'), $this->callbacks(0), 'no settings of the shop\'s');
        self::assertNotContains(Nav::to('shop'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('ba', 'e', 0), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
        self::assertTrue($this->service(BotAdminSettings::class)->enabled(), 'nothing changed');
    }

    public function testNobodyElseGetsTheManagement(): void
    {
        $this->customer(['telegram_id' => 7200]);
        $this->send($this->message('/admin', chat: 7200));
        self::assertNotContains(true, array_map(static fn(string $said): bool => str_contains($said, 'مدیریت فروشگاه'), $this->said()));

        // The admins shut out: an admin is anyone else.
        $this->customer(['telegram_id' => 7300, 'role' => UserRole::Admin]);
        $this->owner();
        $this->send($this->message('/admin', chat: 7001));
        $this->send($this->tap(Nav::to('ba', 'e', 0), chat: 7001));
        self::assertFalse($this->service(BotAdminSettings::class)->enabled());

        $this->send($this->message('/admin', chat: 7300));
        self::assertNotContains(true, array_map(static fn(string $said): bool => str_contains($said, 'مدیریت فروشگاه'), $this->said()));
        $this->send($this->tap(Nav::to('h'), chat: 7300));
        self::assertNotContains(true, array_map(static fn(string $said): bool => str_contains($said, 'مدیریت فروشگاه'), $this->said()));
    }

    public function testAnAdminModeratesAReviewFromTheList(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $review = $this->review(body: 'سرعت عالی');

        $this->send($this->message('/admin', chat: 7100));
        self::assertContains(Nav::to('rv'), $this->callbacks(0), 'the reviews, with what waits counted');
        self::assertStringContainsString('۱ نظر', $this->said()[0]);

        $this->send($this->tap(Nav::to('rv'), chat: 7100));
        self::assertContains(Nav::to('rvv', 'show', $review->id), $this->callbacks(0), 'the waiting one listed');

        $this->send($this->tap(Nav::to('rvv', 'show', $review->id), chat: 7100));
        self::assertStringContainsString('سرعت عالی', $this->said()[0]);
        self::assertContains(Nav::to('rvv', 'ok', $review->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('rvv', 'ok', $review->id), chat: 7100));
        self::assertSame('approved', $review->refresh()->status->value, 'approved through the panel\'s route');
        $edit = array_search('editMessageText', $this->calls(), true);
        self::assertIsInt($edit, 'the review shown again in place');
        self::assertNotContains(Nav::to('rvv', 'ok', $review->id), $this->callbacks($edit), 'approved: no approving again');
        self::assertContains(Nav::to('rvv', 'del', $review->id), $this->callbacks($edit));

        $this->send($this->tap(Nav::to('rvv', 'del', $review->id), chat: 7100));
        self::assertContains(Nav::to('rvv', 'gone', $review->id), $this->callbacks(0), 'asked first');
        $this->send($this->tap(Nav::to('rvv', 'gone', $review->id), chat: 7100));
        self::assertNull($review->newQuery()->find($review->id));
    }

    public function testAMenuButtonLeavesAQuestionItsLabelNoAnswer(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('rv', 'search'), chat: 7100));
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(7100)->state());
        $this->send($this->message(Messages::MENU_WALLET, chat: 7100));

        self::assertStringNotContainsString('🔎 «', implode("\n", $this->said()), 'the wallet, not a search for its label');
        self::assertNull($this->service(SessionStore::class)->load(7100)->state(), 'the question left');
    }

    public function testARefusedAnswerAsksTheQuestionAgainAsItWasAsked(): void
    {
        $this->owner();
        $this->send($this->tap(Nav::to('shop', 'search'), chat: 7001));
        self::assertStringContainsString('کدام فروشگاه؟', $this->said()[0]);

        // A sticker is no text: the question again, its words and hint kept.
        $this->send($this->message(null, ['sticker' => ['file_id' => 'x']], chat: 7001));
        $said = implode("\n", $this->said());
        self::assertStringContainsString('پاسخ را به صورت متن بفرستید', $said);
        self::assertStringContainsString('بخشی از نام فروشگاه', $said, 'the hint asked with it');
        self::assertContains('deleteMessage', $this->calls(), 'what was sent is taken away all the same');
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(7001)->state(), 'which still waits');
    }

    public function testALinkedAccountMakesALinkForAnotherWithNoPassword(): void
    {
        $this->owner();

        // The panel asks its password before a link; the bot's screen, already a linked account, asks none.
        $this->send($this->tap(Nav::to('ol', 'link'), chat: 7001));

        $said = implode("\n", $this->said());
        self::assertStringContainsString('https://t.me/amo_shop_bot?start=' . OwnerLinks::PAYLOAD, $said);
        self::assertNotNull($this->service(OwnerLinks::class)->openUntil(), 'the link is open');
        self::assertTrue($this->service(OwnerLinks::class)->opens(self::linkIn($said)), 'the one shown');
    }

    public function testUnlinkingTakesBackTheRoleTheLinkGaveAndTheManagementWithIt(): void
    {
        $owner = $this->owner(viaStart: true);
        self::assertSame(UserRole::Admin, $owner->refresh()->role);

        $this->service(OwnerLinks::class)->unlink(7001);

        self::assertSame(UserRole::Customer, $owner->refresh()->role);
        $this->send($this->message('/admin', chat: 7001));
        self::assertNotContains(true, array_map(static fn(string $said): bool => str_contains($said, 'مدیریت فروشگاه'), $this->said()));

        // An admin before the link stays one.
        $admin = $this->customer(['telegram_id' => 7400, 'role' => UserRole::Admin]);
        $this->link(7400);
        $this->service(OwnerLinks::class)->unlink(7400);
        self::assertSame(UserRole::Admin, $admin->refresh()->role);

        // Made an admin by the link, then made one by hand in «کاربران»: the owner's choice, which unlinking keeps.
        $chosen = $this->customer(['telegram_id' => 7500]);
        $this->link(7500);
        self::assertSame(UserRole::Admin, $chosen->refresh()->role);
        $this->service(UserActions::class)->setRole($chosen, Actor::system(), ['role' => UserRole::Admin->value]);
        $this->service(OwnerLinks::class)->unlink(7500);
        self::assertSame(UserRole::Admin, User::query()->findOrFail($chosen->id)->role);
    }

    public function testASixthAccountEndsTheOldestOnesLinkAndRole(): void
    {
        $first = $this->owner(viaStart: true);
        foreach ([7002, 7003, 7004, 7005, 7006] as $telegramId) {
            $this->customer(['telegram_id' => $telegramId]);
            $this->link($telegramId);
        }

        self::assertSame([7002, 7003, 7004, 7005, 7006], $this->service(OwnerLinks::class)->ids());
        self::assertSame(UserRole::Customer, $first->refresh()->role);
    }

    public function testTheOwnersLinkOpensWhileTheBotIsOffAndItsOwnerIsNeverShutOut(): void
    {
        $this->botSettings('general', ['enabled' => false, 'phone_required' => true]);
        $owner = $this->owner(viaStart: true);
        self::assertContains(StartHandler::OWNER_LINKED, $this->said(), 'past the off switch and the phone');

        // The admin role taken away by hand: still the owner, and the bot's gates still let them in.
        $owner->role = UserRole::Customer;
        $owner->save();
        $this->send($this->message('/admin', chat: 7001));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0]);

        // Anyone else's guess at a link is shut out as any message is.
        $this->customer(['telegram_id' => 7002]);
        $this->send($this->message('/start owner-guess', chat: 7002));
        self::assertSame([self::text(BotText::BotOff)], $this->said());
    }

    /** The account 7001 linked as the owner — by opening the link in the bot with `$viaStart`. */
    private function owner(bool $viaStart = false): User
    {
        $owner = User::query()->where('telegram_id', 7001)->first() ?? $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        if ($viaStart) {
            $this->link(7001);

            return $owner;
        }
        $this->linkOwner(7001);

        return $owner;
    }

    /** The /start payload of the owner's link in what the bot said. */
    private static function linkIn(string $said): string
    {
        self::assertSame(1, preg_match('~\?start=(' . preg_quote(OwnerLinks::PAYLOAD, '~') . '[A-Za-z0-9]+)~', $said, $match), 'a link in what was said');

        return $match[1];
    }

    /** A new link, opened in the bot by `$telegramId`'s account. */
    private function link(int $telegramId): void
    {
        $link = $this->service(OwnerLinks::class)->link();
        self::assertNotNull($link);
        $this->send($this->message('/start ' . substr($link['url'], (int) strpos($link['url'], '=') + 1), chat: $telegramId));
    }
}
