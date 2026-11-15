<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Subscriptions\Models\Grant;
use App\Modules\Subscriptions\Services\Grants;
use App\Modules\Telegram\Admin\BotAdminSettings;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Broadcasts\Audience;
use App\Modules\Telegram\Broadcasts\BroadcastMode;
use App\Modules\Telegram\Broadcasts\BroadcastService;
use App\Modules\Telegram\Broadcasts\BroadcastStatus;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\Broadcast;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * «📣 ارسال همگانی» and «🎁 هدیه همگانی» from the bot (Sections\Bot\Broadcasts, Gifts): every seat sees the runs and their
 * controls — through the panel's routes, a cancel asked first — and starts the bot's own compose flow from the list;
 * /broadcast answers whoever manages the shop and nobody else; a mass gift is the owner's: drafted field by field, started
 * in one request, stopped asked first.
 */
final class BotAreaBroadcastsTest extends BotTestCase
{
    private const OWNER = 7001;
    private const STAFF = 7100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testAnAdminControlsARunFromItsScreen(): void
    {
        $staff = $this->customer(['telegram_id' => self::STAFF, 'role' => UserRole::Admin]);
        $this->customer(['telegram_id' => 1001]);
        $run = $this->service(BroadcastService::class)->start($staff, ['message_id' => 5, 'mode' => BroadcastMode::Copy, 'audience' => Audience::ALL, 'pin' => false, 'excerpt' => 'تخفیف پاییزی', 'content' => 'text'], null);

        $this->send($this->tap(Nav::to('hb'), chat: self::STAFF));
        self::assertContains(Nav::to('br'), $this->callbacks(0), 'the daily work: every seat');
        self::assertNotContains(Nav::to('mg'), $this->callbacks(0), 'the mass gift is the owner\'s');
        self::assertNotContains(Nav::to('tx'), $this->callbacks(0), 'the texts are the shop\'s configuration');

        $this->send($this->tap(Nav::to('br'), chat: self::STAFF));
        self::assertContains(Nav::to('brv', 'show', $run->id), $this->callbacks(0));
        self::assertContains(Nav::to('br', 'new'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('brv', 'show', $run->id), chat: self::STAFF));
        self::assertStringContainsString('تخفیف پاییزی', $this->said()[0]);
        self::assertContains(Nav::to('brv', 'pause', $run->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('brv', 'pause', $run->id), chat: self::STAFF));
        self::assertSame(BroadcastStatus::Paused, $run->refresh()->status, 'through the panel\'s route');
        self::assertStringContainsString('متوقف شد', (string) $this->popup());
        self::assertContains(Nav::to('brv', 'resume', $run->id), $this->buttons());

        $this->send($this->tap(Nav::to('brv', 'cancel', $run->id), chat: self::STAFF));
        self::assertStringContainsString('این کار برگشت ندارد', $this->said()[0], 'asked first, in the panel\'s words');
        $this->send($this->tap(Nav::to('brv', 'cancelled', $run->id), chat: self::STAFF));
        self::assertSame(BroadcastStatus::Cancelled, $run->refresh()->status);
        self::assertNotContains(Nav::to('brv', 'cancel', $run->id), $this->buttons(), 'over: nothing more to stop');
    }

    public function testANewBroadcastStartsTheBotsOwnComposeFlow(): void
    {
        $this->customer(['telegram_id' => self::STAFF, 'role' => UserRole::Admin]);
        $this->customer(['telegram_id' => 1001]);

        $this->send($this->tap(Nav::to('br', 'new'), chat: self::STAFF));
        self::assertStringContainsString(Messages::BROADCAST_ASK, $this->said()[0]);

        $this->send($this->message('سلام به همه', ['message_id' => 70], chat: self::STAFF));
        self::assertStringContainsString('پیش‌نویس', implode("\n", $this->said()), 'the draft card, as after /broadcast');
        $edit = array_search('editMessageText', $this->calls(), true);
        self::assertIsInt($edit, 'the window it was asked from, answered');
        self::assertSame('77', (string) $this->params($edit)['message_id']);
        self::assertSame([Nav::to('br')], $this->callbacks($edit), '«⚙️ بازگشت به مدیریت» in place of «✖️ انصراف»');

        $this->send($this->tap(Nav::to('br', 'new'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('br'), chat: self::STAFF));
        $this->send($this->message('این پیام همگانی نیست', ['message_id' => 71], chat: self::STAFF));
        self::assertStringNotContainsString('پیش‌نویس', implode("\n", $this->said()), '«✖️ انصراف» left the flow');
    }

    public function testBroadcastAnswersWhoeverManagesTheShopAndNobodyElse(): void
    {
        $this->customer(['telegram_id' => 1001]);
        $this->send($this->message('/broadcast', chat: 1001));
        self::assertSame([], $this->calls(), 'a customer: no answer at all');
        $this->send($this->message('/emoji', chat: 1001));
        self::assertSame([], $this->calls());

        // An owner whose account is no admin of the bot (its role changed since) still composes.
        $this->owner(UserRole::Customer);
        $this->send($this->message('/broadcast', chat: self::OWNER));
        self::assertSame([Messages::BROADCAST_ASK], $this->said());
        $this->send($this->message('/emoji', chat: self::OWNER));
        self::assertSame([Messages::EMOJI_ASK], $this->said());

        // A shop's admin with «مدیران ربات» shut, as before it: the bot's admin.
        $this->customer(['telegram_id' => self::STAFF, 'role' => UserRole::Admin]);
        $this->botSettings('bot_admins', ['bot_admins_enabled' => false]);
        self::assertFalse($this->service(BotAdminSettings::class)->enabled());
        $this->send($this->message('/broadcast', chat: self::STAFF));
        self::assertSame([Messages::BROADCAST_ASK], $this->said());
    }

    public function testAnotherShopsBroadcastsAreSentFromItsOwnBot(): void
    {
        $this->owner();
        $shop = $this->agentBot();

        $this->send($this->tap(Nav::to('shop', 'pick', $shop->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('br'), chat: self::OWNER));
        self::assertNotContains(Nav::to('br', 'new'), $this->callbacks(0));
        self::assertStringContainsString('از ربات همین فروشگاه بفرستید', $this->said()[0]);
        self::assertStringContainsString('ارسال همگانی', $this->said()[0], 'its runs still listed');
    }

    public function testTheOwnerGivesAMassGiftFieldByFieldAndStopsIt(): void
    {
        $this->owner();
        $server = $this->fakeServer();
        $plan = $this->plan();
        $this->subscription($this->customer(['telegram_id' => 1001]), $plan, $server, 'ali_1');

        $this->send($this->tap(Nav::to('mg'), chat: self::OWNER));
        self::assertContains(Nav::to('mgn', 'new'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('mgn', 'new'), chat: self::OWNER));
        self::assertStringContainsString('همه سرویس‌ها (۱)', implode(' ', array_column(array_merge(...$this->inlineKeyboard(0)), 'text')), 'each audience with whom it reaches');
        $this->send($this->tap(Nav::to('mgn', 'a', 'all'), chat: self::OWNER));
        self::assertStringContainsString('تعداد روز', $this->said()[0]);
        $this->send($this->message('۷ روز', chat: self::OWNER));
        self::assertStringContainsString('حجم', $this->said()[0], 'then the traffic');
        $this->send($this->tap(Nav::to('mgn', 'skip', 'gb'), chat: self::OWNER));
        self::assertStringContainsString('دلیل', $this->said()[0], 'then the reason');
        $this->send($this->message('هدیه نوروز', chat: self::OWNER));
        self::assertStringContainsString('به ۱ سرویس اضافه می‌شود', $this->said()[0], 'the summary says whom it reaches');
        self::assertContains(Nav::to('mgn', 'go'), $this->buttons());

        $this->send($this->tap(Nav::to('mgn', 'gb'), chat: self::OWNER));
        $this->send($this->message('۰٫۵', chat: self::OWNER));
        self::assertStringContainsString('حجم: ۰٫۵ گیگابایت', $this->said()[0], 'a fraction in Persian, its separator too');
        self::assertContains(Nav::to('mgn', 'msg', 0), $this->buttons(), 'a switch carries what it sets');
        $this->send($this->tap(Nav::to('mgn', 'msg', 0), chat: self::OWNER));
        $this->send($this->tap(Nav::to('mgn', 'msg', 0), chat: self::OWNER));
        self::assertStringContainsString('پیام به مشتری‌ها: خیر', $this->said()[0], 'a second tap leaves it off');

        $this->send($this->tap(Nav::to('mgn', 'go'), chat: self::OWNER));
        $grant = Grant::query()->sole();
        self::assertSame([7, 'هدیه نوروز'], [$grant->days, $grant->reason], 'one request, as the panel\'s form sends it');
        self::assertGreaterThan(0, (int) $grant->traffic_bytes);
        self::assertStringContainsString('۷ روز', $this->said()[0]);

        $this->send($this->tap(Nav::to('mgv', 'stop', $grant->id), chat: self::OWNER));
        self::assertStringContainsString('به بقیه سرویس‌ها اضافه نمی‌شود', $this->said()[0], 'asked first');
        $this->send($this->tap(Nav::to('mgv', 'stopped', $grant->id), chat: self::OWNER));
        self::assertStringContainsString('لغو', (string) $this->popup());
        self::assertSame('cancelled', $this->service(Grants::class)->present($grant->refresh()->load(['parts.server', 'customerGroup']))['status']);
    }

    public function testAShopsAdminHasNoMassGift(): void
    {
        $this->customer(['telegram_id' => self::STAFF, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('mgn', 'new'), chat: self::STAFF));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
        self::assertSame(0, Grant::query()->count());
        self::assertSame(0, Broadcast::query()->count());
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

    /** The account OWNER linked as the owner — the bot's admin too, as the link makes it, unless `$role` says otherwise. */
    private function owner(UserRole $role = UserRole::Admin): User
    {
        $owner = User::query()->where('telegram_id', self::OWNER)->first() ?? $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir', 'role' => $role]);
        $this->linkOwner(self::OWNER);

        return $owner;
    }
}
