<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Users\Enums\UserRole;
use App\Support\Traffic;
use Tests\BotTestCase;

/**
 * «🧪 اکانت تست» in the bot's management: the tests customers took and one of them, what the panel warns of, every
 * server's test terms — a server's own card the owner's alone —, and the rules card saved through the bot settings.
 */
final class CatalogTestsTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnerReadsTheTestsAndOneOfThem(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $ali = $this->customer(['first_name' => 'Ali', 'username' => 'ali']);
        $service = $this->subscription($ali, $this->plan([], $server), $server, 'test_ali_1', ['expires_at' => now()->addHours(5)]);
        $test = TestAccount::query()->create(['user_id' => $ali->id, 'server_id' => $server->id, 'subscription_id' => $service->id, 'traffic_bytes' => 500 * Traffic::MEGABYTE, 'duration_hours' => 24, 'on_hold' => true, 'issued_at' => now()]);

        $this->tapAs(Nav::to('hs'));
        self::assertContains(Nav::to('ts'), $this->callbacks(0));

        $this->tapAs(Nav::to('ts'));
        self::assertStringContainsString('اکانت تست خاموش است', $this->said()[0], 'the panel\'s warning of the switch');
        self::assertContains(Nav::to('tsv', 'show', $test->id), $this->callbacks(0));
        self::assertContains(Nav::to('ts', 'f', 'status', 'running'), $this->callbacks(0), 'the states as chips');

        $this->tapAs(Nav::to('tsv', 'show', $test->id));
        $page = $this->said()[0];
        self::assertStringContainsString('۵۰۰ مگابایت', $page);
        self::assertStringContainsString('از اولین اتصال', $page);
        self::assertStringContainsString('۵ ساعت مانده', $page);
        self::assertContains(Nav::to('sbv', 'show', $service->id), $this->callbacks(0), 'its service a tap away');
    }

    public function testTheListNarrowsToOneCustomersTests(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $ali = $this->customer(['first_name' => 'Ali']);
        $reza = $this->customer(['first_name' => 'Reza', 'telegram_id' => 5252]);
        $mine = TestAccount::query()->create(['user_id' => $ali->id, 'server_id' => $server->id, 'traffic_bytes' => 1, 'duration_hours' => 1, 'on_hold' => true, 'issued_at' => now()]);
        $theirs = TestAccount::query()->create(['user_id' => $reza->id, 'server_id' => $server->id, 'traffic_bytes' => 1, 'duration_hours' => 1, 'on_hold' => true, 'issued_at' => now()]);

        $this->tapAs(Nav::to('ts', 'user', $ali->id));
        self::assertContains(Nav::to('tsv', 'show', $mine->id), $this->callbacks(0));
        self::assertNotContains(Nav::to('tsv', 'show', $theirs->id), $this->callbacks(0), 'the customer\'s tests alone, as the panel\'s ?user=');

        $this->tapAs(Nav::to('ts', 'user', 0));
        self::assertContains(Nav::to('tsv', 'show', $theirs->id), $this->callbacks(0));
    }

    public function testTheTestsSwitchedOffAreTurnedOnFromTheListAndATestLeadsToItsCustomer(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $ali = $this->customer(['first_name' => 'Ali']);
        $test = TestAccount::query()->create(['user_id' => $ali->id, 'server_id' => $server->id, 'traffic_bytes' => 1, 'duration_hours' => 1, 'on_hold' => true, 'issued_at' => now()]);

        $this->tapAs(Nav::to('ts'));
        self::assertContains(Nav::to('tss', 'e', 0, 1), $this->callbacks(0), 'the switch a tap from the warning');
        $this->tapAs(Nav::to('tss', 'e', 0, 1));
        self::assertTrue($this->setting('test_enabled'));

        $this->tapAs(Nav::to('ts'));
        self::assertNotContains(Nav::to('tss', 'e', 0, 1), $this->callbacks(0), 'nothing to fix: no switch offered');
        $this->tapAs(Nav::to('tsv', 'show', $test->id));
        self::assertContains(Nav::to('ts', 'user', $ali->id), $this->callbacks(0), 'the customer\'s other tests');
        self::assertContains(Nav::to('usv', 'show', $ali->id), $this->callbacks(0), 'the customer\'s own page');
    }

    public function testTheMenuWithoutTheTestsButtonIsWarnedOf(): void
    {
        $this->owner();
        $this->botSettings('test_accounts', ['test_enabled' => true]);

        $this->tapAs(Nav::to('ts'));
        self::assertStringNotContainsString('در منوی ربات نیست', $this->said()[0], 'the built-in menu has it');
        self::assertStringContainsString('🧪 اکانت تست', $this->said()[0]);

        $this->service(KeyboardLayouts::class)->save('start', ['type' => 'reply', 'rows' => [[['action' => 'buy', 'label' => 'خرید']]]]);
        $this->tapAs(Nav::to('ts'));
        self::assertStringContainsString('دکمه «اکانت تست» در منوی ربات نیست', $this->said()[0]);
        self::assertContains(Nav::to('kb'), $this->callbacks(0));
    }

    public function testTheRulesCardSavesThroughTheBotSettings(): void
    {
        $this->owner();

        $this->tapAs(Nav::to('tss'));
        self::assertStringContainsString('فاصله حذف', $this->said()[0], 'the clean-up\'s numbers while it is on');

        $this->tapAs(Nav::to('tss', 'e', 0, 1));
        self::assertTrue($this->setting('test_enabled'));

        $this->tapAs(Nav::to('tss', 'e', 1));
        $this->send($this->message('۳', chat: self::OWNER));
        self::assertSame(3, $this->setting('test_per_user'));

        $this->tapAs(Nav::to('tss', 'e', 5, 0));
        self::assertFalse($this->setting('test_cleanup'));
        self::assertStringNotContainsString('فاصله حذف', $this->said()[0], 'hidden with the clean-up off');
    }

    public function testAServersTermsAreTheOwnersToSet(): void
    {
        $this->owner();
        $server = $this->sellingServer();

        $this->tapAs(Nav::to('tsr'));
        self::assertStringContainsString('۱ سرور', $this->said()[0]);
        self::assertStringStartsWith('⏸ آلمان · ', $this->buttonLabels(0)[0], 'a server\'s button is its row: its chip, name and terms');
        self::assertContains(Nav::to('tsrv', 'srv', $server->id), $this->callbacks(0));

        $this->tapAs(Nav::to('tsrv', 'srv', $server->id));
        self::assertStringContainsString('«آلمان»', $this->said()[0]);
        $this->tapAs(Nav::to('tsrv', 'e', 0, 1));
        $this->tapAs(Nav::to('tsrv', 'e', 1));
        $this->send($this->message('۱۰۲۴', chat: self::OWNER));

        $server->refresh();
        self::assertTrue($server->test_enabled);
        self::assertSame(1024 * Traffic::MEGABYTE, $server->test_traffic_bytes);
        self::assertStringContainsString('ذخیره شد', $this->said()[0]);
    }

    public function testAShopsAdminHasNoTests(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('hs'), chat: 7100));
        self::assertNotContains(Nav::to('ts'), $this->callbacks(0), 'the tests are the shop\'s configuration');

        $this->send($this->tap(Nav::to('tss', 'e', 0, 1), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0]);
        self::assertFalse($this->setting('test_enabled'));
    }

    private function setting(string $name): mixed
    {
        return $this->service(BotSettingsScreen::class)->present()[$name] ?? null;
    }
}
