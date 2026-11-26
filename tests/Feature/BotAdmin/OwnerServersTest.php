<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Subscriptions\Models\GrantPart;
use App\Modules\Subscriptions\Models\MigrationLeftover;
use App\Modules\Subscriptions\Models\ServerMigration;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Users\Enums\UserRole;
use App\Support\Persian;
use Tests\BotTestCase;

/**
 * «🖥 سرورها» in the bot (Telegram\Admin\Sections\Owner\Servers): the owner's list and a server's page, a check, its
 * inbounds for sale, a new server walked through its connector's described form with «🧪 تست اتصال», its connection
 * edited with its secret kept, its test accounts, a grant to its services, a move of them, and a delete asked first —
 * every write through the panel's own routes; the main shop's admins work them as the owner does.
 */
final class OwnerServersTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
    }

    public function testTheOwnerOpensAServerChecksItAndChoosesItsInboundsForSale(): void
    {
        $this->fakePanel();
        $server = $this->fakeServer('آلمان');
        $inbound = $this->inbound($server, '1', ['is_selectable' => false]);

        $this->send($this->message('/admin', chat: self::OWNER));
        self::assertContains(Nav::to('sv'), $this->callbacks(0), 'the servers on the home');

        $this->send($this->tap(Nav::to('sv'), chat: self::OWNER));
        self::assertStringContainsString('🟡 آلمان', implode(' ', $this->buttonLabels(0)), 'the button is the row: its chip and name');
        self::assertContains(Nav::to('svv', 'show', $server->id), $this->callbacks(0));
        self::assertContains(Nav::to('sva'), $this->callbacks(0), '«➕ افزودن سرور»');

        $this->send($this->tap(Nav::to('svv', 'show', $server->id), chat: self::OWNER));
        self::assertStringContainsString('بررسی نشده', $this->said()[0]);

        $this->send($this->tap(Nav::to('svi', 'show', $server->id), chat: self::OWNER));
        self::assertContains(Nav::to('svi', 'sell', $inbound->id, 1, $server->id), $this->callbacks(0), 'the switch names its server too');
        $this->send($this->tap(Nav::to('svi', 'sell', $inbound->id, 1, $server->id), chat: self::OWNER));
        self::assertTrue(ServerInbound::query()->findOrFail($inbound->id)->is_selectable);
        self::assertStringContainsString('۱ اینباند · ۱ برای فروش', $this->said()[0]);
        // An older version's switch (no server in it) acts on the server last opened.
        $this->send($this->tap(Nav::to('svi', 'sell', $inbound->id, 0), chat: self::OWNER));
        self::assertFalse(ServerInbound::query()->findOrFail($inbound->id)->is_selectable);
        $this->send($this->tap(Nav::to('svi', 'sell', $inbound->id, 1, $server->id), chat: self::OWNER));

        $this->send($this->tap(Nav::to('svv', 'check', $server->id), chat: self::OWNER));
        self::assertStringContainsString('اتصال برقرار شد', $this->said()[0], 'what the check found, on the page');
        self::assertNotNull($server->refresh()->last_checked_at, 'checked through the panel\'s route');
    }

    public function testTheCardReadsTheInboundsAgainAndARebeccaPanelSellsFromOneAtATime(): void
    {
        $this->fakePanel();
        $server = $this->fakeServer('آلمان');
        $this->inbound($server, '1');
        $this->send($this->tap(Nav::to('svv', 'show', $server->id), chat: self::OWNER));
        self::assertContains(Nav::to('svv', 'sync', $server->id), $this->callbacks(0), 'read again from the card itself');
        self::assertStringContainsString('اینباندهای فروش: <b>۱ از ۱</b>', $this->said()[0]);

        self::assertNull($server->last_checked_at);
        $this->send($this->tap(Nav::to('svv', 'sync', $server->id), chat: self::OWNER));
        self::assertStringContainsString('اینباندها از پنل خوانده شدند', (string) $this->popup());
        self::assertNotNull($server->refresh()->last_checked_at, 'its panel asked, through POST …/inbounds/sync');
        self::assertStringContainsString('وضعیت: <b>🟢 متصل</b>', $this->said()[0], 'the card again, as the API now has it');

        $rebecca = $this->panelServer('RB', ['driver' => 'rebecca', 'base_url' => 'https://rb.example:8000', 'serves_subscriptions' => true]);
        $this->inbound($rebecca, '3');
        $second = $this->inbound($rebecca, '4', ['is_selectable' => false]);
        $this->send($this->tap(Nav::to('svi', 'show', $rebecca->id), chat: self::OWNER));
        self::assertContains(Nav::to('svi', 'sell', $second->id, 1, $rebecca->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('svi', 'sell', $second->id, 1, $rebecca->id), chat: self::OWNER));
        self::assertStringContainsString('اول علامت «فروش» سرویس دیگر این سرور را بردارید', (string) $this->popup(), 'the API\'s refusal, said');
        self::assertFalse($second->refresh()->is_selectable);
    }

    public function testAnOlderWindowsButtonsActOnTheServerTheyShowWhicheverPageWasOpenedSince(): void
    {
        $this->fakePanel();
        $germany = $this->fakeServer('آلمان');
        $holland = $this->fakeServer('هلند');
        MigrationLeftover::query()->create(['server_id' => $germany->id, 'remote_name' => 'ali_1']);
        MigrationLeftover::query()->create(['server_id' => $holland->id, 'remote_name' => 'sara_1']);

        // Germany checked, its inbounds and its leftovers' «فراموش کردن» asked…
        $this->send($this->tap(Nav::to('svv', 'check', $germany->id), chat: self::OWNER));
        $inbound = $this->inbound($germany, '7', ['is_selectable' => false]);
        $this->send($this->tap(Nav::to('svi', 'show', $germany->id), chat: self::OWNER));
        self::assertContains(Nav::to('svi', 'sync', $germany->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('svm', 'show', $germany->id), chat: self::OWNER));
        self::assertContains(Nav::to('svm', 'forget', $germany->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('svm', 'forget', $germany->id), chat: self::OWNER));
        self::assertContains(Nav::to('svm', 'forgot', $germany->id), $this->callbacks(0), 'the yes names the server it was asked about');

        // …then Holland's inbounds opened: its card shows no test of Germany's.
        $this->send($this->tap(Nav::to('svi', 'show', $holland->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svv', 'show', $holland->id), chat: self::OWNER));
        self::assertStringNotContainsString('نتیجه بررسی اتصال', $this->said()[0], 'another server\'s check is not this one\'s');

        // The older window's buttons still act on Germany.
        $this->send($this->tap(Nav::to('svi', 'sell', $inbound->id, 1, $germany->id), chat: self::OWNER));
        self::assertTrue($inbound->refresh()->is_selectable, 'PATCH /servers/{germany}/inbounds/{id}, not Holland\'s');
        self::assertStringContainsString('آلمان', $this->said()[0]);
        $this->send($this->tap(Nav::to('svv', 'show', $holland->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svi', 'sync', $germany->id), chat: self::OWNER));
        self::assertStringContainsString('<i>مدیریت › سرورها › آلمان</i>', $this->said()[0], 'POST /servers/{germany}/inbounds/sync: Germany\'s list');
        $this->send($this->tap(Nav::to('svv', 'show', $holland->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svm', 'clean', $germany->id), chat: self::OWNER));
        self::assertStringContainsString('<i>مدیریت › سرورها › آلمان</i>', $this->said()[0], 'POST /servers/{germany}/leftovers/clean: Germany\'s moves');
        self::assertNull(MigrationLeftover::query()->where('server_id', $holland->id)->value('removed_at'), 'Holland\'s leftover untouched');
        $this->send($this->tap(Nav::to('svv', 'show', $holland->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svm', 'forgot', $germany->id), chat: self::OWNER));
        self::assertSame(['sara_1'], MigrationLeftover::pending()->pluck('remote_name')->all(), 'DELETE /servers/{germany}/leftovers: Holland\'s kept');
    }

    public function testTheInboundsPagesNameTheirServerAndAnOlderPagerStillTurns(): void
    {
        $this->fakePanel();
        $germany = $this->fakeServer('آلمان');
        $holland = $this->fakeServer('هلند');
        $inbounds = array_map(fn(int $n): ServerInbound => $this->inbound($germany, (string) $n), range(1, 11));
        $sell = static fn(ServerInbound $inbound): string => Nav::to('svi', 'sell', $inbound->id, 0, $germany->id);

        $this->send($this->tap(Nav::to('svi', 'show', $germany->id), chat: self::OWNER));
        self::assertContains(Nav::to('svi', 'page', $germany->id, 2), $this->callbacks(0), 'the pager names its server');
        self::assertNotContains($sell($inbounds[10]), $this->callbacks(0), 'ten a page');

        $this->send($this->tap(Nav::to('svv', 'show', $holland->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svi', 'page', $germany->id, 2), chat: self::OWNER));
        self::assertContains($sell($inbounds[10]), $this->callbacks(0), 'Germany\'s second page, Holland\'s opened since');
        self::assertStringContainsString('<i>مدیریت › سرورها › آلمان</i>', $this->said()[0]);

        // An older version's pager (the page alone) turns the list last opened.
        $this->send($this->tap(Nav::to('svi', 'page', 1), chat: self::OWNER));
        self::assertContains($sell($inbounds[0]), $this->callbacks(0));
        self::assertNotContains($sell($inbounds[10]), $this->callbacks(0));
    }

    public function testAMovesDraftHasOneWayOutAskedFirstOnceChanged(): void
    {
        $this->fakePanel();
        $source = $this->fakeServer('آلمان');
        $this->fakeServer('هلند');

        $this->send($this->tap(Nav::to('svmn', 'new', $source->id), chat: self::OWNER));
        $labels = array_column(array_merge(...$this->inlineKeyboard(0)), 'text', 'callback_data');
        self::assertSame(Words::BACK, $labels[Nav::to('svmn', 'quit')] ?? null, 'as it started: «⬅️ بازگشت» the one way out');
        self::assertNotContains(Words::CANCEL, $labels);

        $this->send($this->tap(Nav::to('svmn', 'ntf', 0), chat: self::OWNER));
        $buttons = array_column(array_merge(...$this->inlineKeyboard(0)), null, 'callback_data');
        self::assertSame(['🗑 کنار گذاشتن', 'danger'], [$buttons[Nav::to('svmn', 'quit')]['text'] ?? null, $buttons[Nav::to('svmn', 'quit')]['style'] ?? null], 'changed: «🗑 کنار گذاشتن», red');
        self::assertNotContains(Words::BACK, array_column($buttons, 'text'));

        $this->send($this->tap(Nav::to('svmn', 'quit'), chat: self::OWNER));
        self::assertStringContainsString('این پیش‌نویس کنار گذاشته شود؟', $this->said()[0], 'asked first, in a form\'s words');
        self::assertSame([Nav::to('svmn', 'show'), Nav::to('svmn', 'gone')], $this->callbacks(0));
        $this->send($this->tap(Nav::to('svmn', 'show'), chat: self::OWNER));
        self::assertStringContainsString('پیام به مشتری‌ها: <b>⏸ خاموش</b>', $this->said()[0], 'not dropped: the draft as it was');
        $this->send($this->tap(Nav::to('svmn', 'gone'), chat: self::OWNER));
        self::assertSame(0, ServerMigration::query()->count(), 'dropped: nothing started');
        self::assertContains(Nav::to('svmn', 'new', $source->id), $this->callbacks(0), 'back on the moves');
    }

    public function testTheFormsQuestionsSayTheValueAndTheLimitsAndPutTheDefaultBackOrClearIt(): void
    {
        $server = $this->panelServer('DE-1');
        $this->send($this->tap(Nav::to('svf', 'edit', $server->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svf', 'adv', 1), chat: self::OWNER));
        $button = static fn(array $keyboard, string $label): string => (string) (array_values(array_filter(array_merge(...$keyboard), static fn(array $one): bool => str_starts_with((string) ($one['text'] ?? ''), $label)))[0]['callback_data'] ?? self::fail("no «{$label}»"));
        $timeout = $button($this->inlineKeyboard(0), '✏️ تایم‌اوت اتصال');
        $index = substr($timeout, strlen(Nav::to('svf', 'e')) + 1);

        $this->send($this->tap($timeout, chat: self::OWNER));
        $asked = $this->said()[0];
        self::assertStringContainsString('مقدار فعلی: <code>' . Persian::digits((string) PanelConnection::DEFAULT_TIMEOUT) . '</code>', $asked, 'the value now, in Persian digits, to copy');
        self::assertStringContainsString('محدوده: از ۵ تا ۱۲۰ ثانیه', $asked, 'its limits');
        self::assertNotContains(Nav::to('svf', 'df', $index), $this->callbacks(0), 'at its default: nothing to put back');
        $this->send($this->message('۲۵', chat: self::OWNER));
        self::assertStringContainsString('تایم‌اوت اتصال (ثانیه): <b>۲۵ ثانیه</b>', implode("\n", $this->said()));

        $this->send($this->tap($timeout, chat: self::OWNER));
        self::assertContains(Nav::to('svf', 'df', $index), $this->callbacks(0), '«↩️ پیش‌فرض» once it holds another');
        $this->send($this->tap(Nav::to('svf', 'df', $index), chat: self::OWNER));
        self::assertStringContainsString('تایم‌اوت اتصال (ثانیه): <b>' . Persian::number(PanelConnection::DEFAULT_TIMEOUT) . ' ثانیه</b>', $this->said()[0], 'back as it comes, in the draft');

        $subscription = $button($this->inlineKeyboard(0), '✏️ آدرس اشتراک');
        $this->send($this->tap($subscription, chat: self::OWNER));
        $this->send($this->message('https://sub.example.com/sub', chat: self::OWNER));
        $this->send($this->tap($subscription, chat: self::OWNER));
        $clear = Nav::to('svf', 'x', substr($subscription, strlen(Nav::to('svf', 'e')) + 1));
        self::assertContains($clear, $this->callbacks(0), '«🗑 خالی کردن» for a field that may be empty and holds a value');
        $this->send($this->tap($clear, chat: self::OWNER));
        self::assertStringContainsString('آدرس اشتراک: <b>—</b>', $this->said()[0]);
        self::assertSame(['DE-1', 'https://de-1.example:2053/base'], [$server->refresh()->name, $server->base_url], 'nothing saved before «💾»');
    }

    public function testANewServerWalksItsConnectorsFormIsTestedAndAdded(): void
    {
        $this->panelHttp()->healthyPanel();
        $this->panelHttp()->healthyPanel();

        $this->send($this->tap(Nav::to('sva'), chat: self::OWNER));
        self::assertContains(Nav::to('svf', 'new', '3x-ui'), $this->callbacks(0), 'each connector to pick');

        $this->send($this->tap(Nav::to('svf', 'new', '3x-ui'), chat: self::OWNER));
        self::assertStringContainsString('نام سرور', $this->said()[0], 'its first field asked');
        self::assertStringContainsString('مثال: <code>آلمان ۱</code>', $this->said()[0], 'the input\'s placeholder as the example, its «مثلا» off');
        self::assertSame([['📋 خلاصه'], [Words::CANCEL]], array_map(static fn(array $row): array => array_column($row, 'text'), $this->inlineKeyboard(0)), 'a required field empty: no skip; the summary a way of its own');
        $this->send($this->message('DE-1', chat: self::OWNER));
        self::assertStringContainsString('ایموجی پرمیوم', $this->said()[1] ?? $this->said()[0]);
        self::assertContains(Nav::to('svf', 's', 1), $this->callbacks($this->buttonsCall()), 'an optional field passed by');
        self::assertContains([Words::SKIP, '📋 خلاصه'], array_map(static fn(array $row): array => array_reverse(array_column($row, 'text')), $this->inlineKeyboard($this->buttonsCall())), 'the walk\'s ways a row of their own, «⏭ رد شدن» first');

        $this->send($this->tap(Nav::to('svf', 's', 1), chat: self::OWNER));
        self::assertStringContainsString('آدرس پنل', $this->said()[0]);
        $this->send($this->message('https://panel.example:2053/base/', chat: self::OWNER));
        $this->send($this->tap(Nav::to('svf', 'c', 3, 0), chat: self::OWNER));
        self::assertStringContainsString('✍️', $this->said()[0], 'the token asked once the way in is picked');
        $this->send($this->message('tok-1', chat: self::OWNER));

        $card = implode("\n", $this->said());
        self::assertStringContainsString('DE-1', $card, 'the walk ends on the card');
        self::assertStringContainsString('تازه نوشته شده', $card, 'a secret typed is never shown back');
        self::assertStringNotContainsString('tok-1', $card);

        $this->send($this->tap(Nav::to('svf', 'test'), chat: self::OWNER));
        self::assertStringContainsString('اتصال برقرار شد', $this->said()[0], 'tested before anything is saved');
        self::assertSame(0, Server::query()->count());

        $this->send($this->tap(Nav::to('svf', 'save'), chat: self::OWNER));
        $server = Server::query()->sole();
        self::assertSame(['DE-1', '3x-ui', 'tok-1', 'https://panel.example:2053/base'], [$server->name, $server->driver, $server->api_token, $server->base_url]);
        self::assertStringContainsString('🖥 DE-1', $this->said()[0], 'its page, with what its panel answered');
        self::assertStringContainsString('اتصال برقرار شد', $this->said()[0]);
    }

    public function testTheFormsCardHasOneWayOutAskedFirstOnceSomethingIsChanged(): void
    {
        $server = $this->panelServer('DE-1');
        $this->send($this->tap(Nav::to('svf', 'edit', $server->id), chat: self::OWNER));
        $labels = array_column(array_merge(...$this->inlineKeyboard(0)), 'text', 'callback_data');
        self::assertSame(Words::SAVE, $labels[Nav::to('svf', 'save')] ?? null, 'the one save word');
        self::assertSame(Words::BACK, $labels[Nav::to('svf', 'quit')] ?? null, 'nothing changed: «⬅️ بازگشت» the one way out');
        self::assertNotContains(Words::CANCEL, $labels);

        $this->send($this->tap(Nav::to('svf', 'e', 0), chat: self::OWNER));
        $this->send($this->message('DE-2', chat: self::OWNER));
        $buttons = array_column(array_merge(...$this->inlineKeyboard($this->buttonsCall())), null, 'callback_data');
        self::assertSame(['🗑 کنار گذاشتن', 'danger'], [$buttons[Nav::to('svf', 'quit')]['text'] ?? null, $buttons[Nav::to('svf', 'quit')]['style'] ?? null], 'changed: the one way out «🗑 کنار گذاشتن», red');
        self::assertNotContains(Words::BACK, array_column($buttons, 'text'), 'no second way out');

        // The way out — an older window's «⬅️ بازگشت» too, the same button — asks first.
        $this->send($this->tap(Nav::to('svf', 'quit'), chat: self::OWNER));
        self::assertStringContainsString('تغییرها کنار گذاشته شوند؟', $this->said()[0]);
        self::assertSame([Nav::to('svf', 'show'), Nav::to('svf', 'gone')], $this->callbacks(0), 'asked first: «✖️ انصراف» back to the card, the yes drops it');
        self::assertSame('danger', $this->inlineKeyboard(0)[0][1]['style'] ?? null);
        $this->send($this->tap(Nav::to('svf', 'show'), chat: self::OWNER));
        self::assertStringContainsString('نام سرور: <b>DE-2</b>', $this->said()[0], 'not dropped: the draft as it was');
        $this->send($this->tap(Nav::to('svf', 'gone'), chat: self::OWNER));
        self::assertSame('DE-1', $server->refresh()->name, 'dropped: nothing saved');
        self::assertStringContainsString('🖥 DE-1', $this->said()[0], 'back on its page');
    }

    public function testEditingTheConnectionKeepsItsSecretAndARefusalIsSaidUnderItsField(): void
    {
        $server = $this->panelServer('DE-1');

        $this->send($this->tap(Nav::to('svf', 'edit', $server->id), chat: self::OWNER));
        self::assertStringContainsString('تنظیمات اتصال', $this->said()[0]);
        self::assertStringContainsString('ذخیره شده', $this->said()[0], 'the token kept, never shown');

        $this->send($this->tap(Nav::to('svf', 'e', 0), chat: self::OWNER));
        $this->send($this->message('DE-2', chat: self::OWNER));
        self::assertStringContainsString('تغییرها هنوز ذخیره نشده‌اند', implode("\n", $this->said()));

        $this->send($this->tap(Nav::to('svf', 'save'), chat: self::OWNER));
        $server->refresh();
        self::assertSame(['DE-2', 'tok-1'], [$server->name, $server->api_token], 'renamed, its token kept');

        // A moved address without the token again: the API's words under the field, the draft kept.
        $this->send($this->tap(Nav::to('svf', 'edit', $server->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svf', 'e', 2), chat: self::OWNER));
        $this->send($this->message('https://other.example', chat: self::OWNER));
        self::assertStringContainsString('⚠️', implode("\n", $this->said()), 'the token\'s `moved` words said before the save');
        $this->send($this->tap(Nav::to('svf', 'save'), chat: self::OWNER));
        self::assertNotNull($this->popup(), 'refused');
        self::assertSame('https://de-1.example:2053/base', $server->refresh()->base_url);
    }

    public function testItsTestAccountsGrantsAndMovesAreTheOwners(): void
    {
        $this->fakePanel();
        $server = $this->sellingServer('آلمان');
        $other = $this->sellingServer('هلند');
        $plan = $this->plan([], $server);
        $this->subscription($this->customer(), $plan, $server, 'ali');

        // The test-account card is the catalog's (tsrv), opened from the server and back to it.
        $this->send($this->tap(Nav::to('svv', 'show', $server->id), chat: self::OWNER));
        self::assertContains(Nav::to('tsrv', 'srv', $server->id, 'v'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('tsrv', 'srv', $server->id, 'v'), chat: self::OWNER));
        self::assertStringContainsString('اکانت تست «آلمان»', $this->said()[0]);
        self::assertStringContainsString('مدیریت › سرورها › آلمان', $this->said()[0], 'in the server\'s path');
        self::assertContains(Nav::to('svv'), $this->callbacks(0), 'back to the server it was opened from');
        $switch = array_values(array_filter($this->callbacks(0), static fn(string $data): bool => preg_match('/^a:tsrv:[a-z]+:0(:|$)/', $data) === 1))[0] ?? self::fail('no switch');
        $this->send($this->tap($switch, chat: self::OWNER));
        self::assertTrue((bool) $server->refresh()->test_enabled, 'switched through PUT …/test-account');

        $this->send($this->tap(Nav::to('svg', 'show', $server->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svgn', 'new', $server->id), chat: self::OWNER));
        self::assertStringContainsString('تعداد روز', $this->said()[0]);
        $this->send($this->tap(Nav::to('svgn', 'p', 0, 1), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svgn', 's', 1), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svgn', 's', 2), chat: self::OWNER));
        self::assertStringContainsString('به ۱ سرویس روی این سرور اضافه می‌شود', $this->said()[0], 'whom it reaches');
        $this->send($this->tap(Nav::to('svgn', 'save'), chat: self::OWNER));
        self::assertSame(1, GrantPart::query()->count());
        self::assertStringContainsString('افزودن زمان و حجم شروع شد', (string) $this->popup());
        self::assertStringContainsString('۳ روز', $this->said()[0], 'the grant on its list');
        self::assertContains(Nav::to('svg', 'stop', GrantPart::query()->sole()->id), $this->callbacks($this->buttonsCall()), 'the one under way stopped from there');

        $this->send($this->tap(Nav::to('svmn', 'new', $server->id), chat: self::OWNER));
        self::assertNotContains(Nav::to('svmn', 'start'), $this->callbacks(0), 'no target yet');
        $this->send($this->tap(Nav::to('svmn', 'tgt', $other->id), chat: self::OWNER));
        self::assertStringContainsString('۱ سرویس منتقل می‌شود', $this->said()[0]);
        $this->send($this->tap(Nav::to('svmn', 'start'), chat: self::OWNER));
        self::assertStringContainsString('انتقال سرویس‌ها شروع شد', (string) $this->popup());
        $job = ServerMigration::query()->sole()->id;
        self::assertContains(Nav::to('svm', 'cancel', $job), $this->callbacks($this->buttonsCall()));
        $this->send($this->tap(Nav::to('svm', 'cancel', $job), chat: self::OWNER));
        self::assertContains(Nav::to('svm', 'cancelled', $job), $this->callbacks(0), 'asked first');
        $this->send($this->tap(Nav::to('svm', 'cancelled', $job), chat: self::OWNER));
        self::assertSame('cancelled', ServerMigration::query()->sole()->status->value);
    }

    public function testTheMoveFormPagesItsTargetsAndItsSwitchesCarryTheirValue(): void
    {
        $this->fakePanel();
        $server = $this->sellingServer('آلمان');
        $this->subscription($this->customer(), $this->plan([], $server), $server, 'ali');
        $targets = [];
        for ($n = 1; $n <= 45; $n++) {
            $targets[] = $this->sellingServer('S' . $n);
        }

        $this->send($this->tap(Nav::to('svmn', 'new', $server->id), chat: self::OWNER));
        self::assertContains(Nav::to('svmn', 'dis', 1), $this->callbacks(0), 'each switch carries the value it sets');
        self::assertContains(Nav::to('svmn', 'ntf', 0), $this->callbacks(0));
        $this->send($this->tap(Nav::to('svmn', 'ntf', 0), chat: self::OWNER));
        $this->send($this->tap(Nav::to('svmn', 'ntf', 0), chat: self::OWNER));
        self::assertContains(Nav::to('svmn', 'ntf', 1), $this->callbacks(0), 'a second tap leaves it off');

        $this->send($this->tap(Nav::to('svmn', 'target', 1), chat: self::OWNER));
        self::assertLessThanOrEqual(100, count($this->callbacks(0)), 'within Telegram\'s keyboard');
        self::assertNotContains(Nav::to('svmn', 'tgt', $targets[44]->id), $this->callbacks(0), 'the 45th on the next page');
        self::assertContains(Nav::to('svmn', 'target', 2), $this->callbacks(0));
        $this->send($this->tap(Nav::to('svmn', 'target', 2), chat: self::OWNER));
        self::assertContains(Nav::to('svmn', 'tgt', $targets[44]->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('svmn', 'tgt', $targets[44]->id), chat: self::OWNER));
        self::assertStringContainsString('سرور مقصد: <b>S45</b>', $this->said()[0]);
    }

    public function testDeletingAServerIsAskedFirstAndRefusedWhileItHasServices(): void
    {
        $this->fakePanel();
        $busy = $this->sellingServer('آلمان');
        $this->subscription($this->customer(), $this->plan([], $busy), $busy, 'ali');
        $idle = $this->fakeServer('هلند');

        $this->send($this->tap(Nav::to('svv', 'gone', $busy->id), chat: self::OWNER));
        self::assertStringContainsString('اشتراک روی این سرور است', (string) $this->popup(), 'the API\'s refusal in a popup');
        self::assertNotNull(Server::query()->find($busy->id));

        $this->send($this->tap(Nav::to('svv', 'del', $idle->id), chat: self::OWNER));
        self::assertContains(Nav::to('svv', 'gone', $idle->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('svv', 'gone', $idle->id), chat: self::OWNER));
        self::assertNull(Server::query()->find($idle->id));
    }

    public function testAShopsAdminWorksTheServersAsTheOwnerDoes(): void
    {
        $this->fakePanel();
        $server = $this->fakeServer('آلمان');
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->message('/admin', chat: 7100));
        self::assertContains(Nav::to('sv'), $this->callbacks(0), 'the servers, on the home');

        $this->send($this->tap(Nav::to('svv', 'del', $server->id), chat: 7100));
        self::assertContains(Nav::to('svv', 'gone', $server->id), $this->callbacks(0), 'asked first, not the home');
        $this->send($this->tap(Nav::to('svv', 'gone', $server->id), chat: 7100));
        self::assertNull(Server::query()->find($server->id), 'deleted through the panel\'s route');
    }
}
