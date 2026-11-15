<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Subscriptions\Models\GrantPart;
use App\Modules\Subscriptions\Models\ServerMigration;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * «🖥 سرورها» in the bot (Telegram\Admin\Sections\Owner\Servers): the owner's list and a server's page, a check, its
 * inbounds for sale, a new server walked through its connector's described form with «🧪 تست اتصال», its connection
 * edited with its secret kept, its test accounts, a grant to its services, a move of them, and a delete asked first —
 * every write through the panel's own routes; a shop's admin sees none of it.
 */
final class OwnerServersTest extends BotTestCase
{
    private const OWNER = 7001;

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
        self::assertStringContainsString('آلمان', $this->said()[0]);
        self::assertContains(Nav::to('svv', 'show', $server->id), $this->callbacks(0));
        self::assertContains(Nav::to('sva'), $this->callbacks(0), '«➕ افزودن سرور»');

        $this->send($this->tap(Nav::to('svv', 'show', $server->id), chat: self::OWNER));
        self::assertStringContainsString('بررسی نشده', $this->said()[0]);

        $this->send($this->tap(Nav::to('svi', 'show', $server->id), chat: self::OWNER));
        self::assertContains(Nav::to('svi', 'sell', $inbound->id, 1), $this->callbacks(0));
        $this->send($this->tap(Nav::to('svi', 'sell', $inbound->id, 1), chat: self::OWNER));
        self::assertTrue(ServerInbound::query()->findOrFail($inbound->id)->is_selectable);
        self::assertStringContainsString('۱ از ۱ اینباند برای فروش', $this->said()[0]);

        $this->send($this->tap(Nav::to('svv', 'check', $server->id), chat: self::OWNER));
        self::assertStringContainsString('اتصال برقرار شد', $this->said()[0], 'what the check found, on the page');
        self::assertNotNull($server->refresh()->last_checked_at, 'checked through the panel\'s route');
    }

    public function testANewServerWalksItsConnectorsFormIsTestedAndAdded(): void
    {
        $this->panelHttp()->healthyPanel();
        $this->panelHttp()->healthyPanel();

        $this->send($this->tap(Nav::to('sva'), chat: self::OWNER));
        self::assertContains(Nav::to('svf', 'new', '3x-ui'), $this->callbacks(0), 'each connector to pick');

        $this->send($this->tap(Nav::to('svf', 'new', '3x-ui'), chat: self::OWNER));
        self::assertStringContainsString('نام سرور', $this->said()[0], 'its first field asked');
        $this->send($this->message('DE-1', chat: self::OWNER));
        self::assertStringContainsString('ایموجی پرمیوم', $this->said()[1] ?? $this->said()[0]);
        self::assertContains(Nav::to('svf', 's', 1), $this->callbacks($this->buttonsCall()), 'an optional field passed by');

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
        self::assertStringContainsString('سرور مقصد: S45', $this->said()[0]);
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

    public function testAShopsAdminSeesNoServers(): void
    {
        $this->fakePanel();
        $server = $this->fakeServer('آلمان');
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->message('/admin', chat: 7100));
        self::assertNotContains(Nav::to('sv'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('svv', 'gone', $server->id), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
        self::assertNotNull(Server::query()->find($server->id));
    }

    /** The account 7001 linked as the owner. */
    private function owner(): User
    {
        $owner = User::query()->where('telegram_id', self::OWNER)->first() ?? $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir']);
        $this->linkOwner(self::OWNER);

        return $owner;
    }
}
